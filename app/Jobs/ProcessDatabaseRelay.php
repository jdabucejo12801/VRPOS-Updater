<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProcessDatabaseRelay implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    protected string $table;

    /**
     * Can be a single column name ("ID") or a comma-separated composite key
     * ("Transaction_ID,POS_ID,Branch_ID").
     */
    protected ?string $primaryKey;

    protected array $records;

    protected string $relayId;

    public function __construct(string $table, ?string $primaryKey, array $records, ?string $relayId = null)
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->records = $records;
        $this->relayId = $relayId ?? (string) str()->uuid();
    }

    protected function getValidTableColumns(): array
    {
        $connection = DB::connection('sqlsrv');
        $schemaAndObject = $this->parseSchemaAndObject($this->table);

        try {
            $columns = Schema::connection('sqlsrv')->getColumnListing($this->table);
        } catch (\Throwable $exception) {
            Log::warning('Falling back to INFORMATION_SCHEMA for column listing', [
                'table' => $this->table,
                'error' => $exception->getMessage(),
            ]);
            $columns = [];
        }

        if (empty($columns)) {
            $results = $connection->select(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :tableSchema
                   AND TABLE_NAME = :tableName
                 ORDER BY ORDINAL_POSITION",
                [
                    'tableSchema' => $schemaAndObject['schema'],
                    'tableName' => $schemaAndObject['object'],
                ]
            );

            $columns = array_map(static fn ($column) => $column->COLUMN_NAME, $results);
        }

        $normalizedColumns = [];
        foreach ($columns as $column) {
            $value = (string) $column;
            if ($value !== '') {
                $normalizedColumns[] = $value;
            }
        }

        return array_values(array_unique($normalizedColumns));
    }

    protected function filterRecordToSchemaColumns(array $record, array $validColumns): array
    {
        if (empty($record)) {
            return [];
        }

        $validColumnsByName = [];
        foreach ($validColumns as $column) {
            $validColumnsByName[strtolower((string) $column)] = (string) $column;
        }

        $filteredRecord = [];
        foreach ($record as $column => $value) {
            $normalizedColumn = strtolower((string) $column);
            if (array_key_exists($normalizedColumn, $validColumnsByName)) {
                $filteredRecord[$validColumnsByName[$normalizedColumn]] = $value;
            }
        }

        return $filteredRecord;
    }

    protected function quoteSqlServerIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    /**
     * Split a potentially schema-qualified table name into [schema, object].
     *
     * If no schema is present, defaults to 'dbo'.
     */
    protected function parseSchemaAndObject(string $table): array
    {
        $table = trim($table);

        if (str_contains($table, '.')) {
            [$schema, $object] = explode('.', $table, 2);
            $schema = trim($schema);
            $object = trim($object);

            if ($schema === '' || $object === '') {
                return ['schema' => 'dbo', 'object' => $table];
            }

            return ['schema' => $schema, 'object' => $object];
        }

        return ['schema' => 'dbo', 'object' => $table];
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function quoteSqlServerObjectLiteral(array $schemaAndObject): string
    {
        return $this->quoteSqlServerIdentifier($schemaAndObject['schema']) . '.' . $this->quoteSqlServerIdentifier($schemaAndObject['object']);
    }


    public function handle(): void
    {
        if (empty($this->records)) {
            Log::warning('ProcessDatabaseRelay called with empty records', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'primaryKey' => $this->primaryKey,
            ]);

            Log::channel('relay')->warning('Relay job received empty records', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'status' => 'empty_records',
            ]);

            return;
        }

        Log::info('ProcessDatabaseRelay job started', [
            'relay_id' => $this->relayId,
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'records_count' => count($this->records),
        ]);

        Log::channel('relay')->info('Relay job started', [
            'relay_id' => $this->relayId,
            'table' => $this->table,
            'records_count' => count($this->records),
            'status' => 'processing',
        ]);

        $conn = DB::connection('sqlsrv');

        try {
            $test = $conn->select('SELECT DB_NAME() as dbname');
            Log::info('Connected to SQL Server', ['database' => $test[0]->dbname]);

            $validColumns = $this->getValidTableColumns();
            Log::info('Resolved target schema columns', [
                'table' => $this->table,
                'columns' => $validColumns,
            ]);

            // Filter each record against the real schema and group rows by their
            // resulting column signature. Rows that share the same column set can
            // be inserted with a single multi-row VALUES statement.
            $groups = [];
            foreach ($this->records as $index => $record) {
                $schemaAwareRecord = $this->filterRecordToSchemaColumns($record, $validColumns);
                $droppedColumns = array_diff_key($record, $schemaAwareRecord);

                if (!empty($droppedColumns)) {
                    Log::debug('Relay dropped columns not present in target schema', [
                        'table' => $this->table,
                        'record_index' => $index,
                        'columns' => array_keys($droppedColumns),
                    ]);
                }

                if (empty($schemaAwareRecord)) {
                    throw new \InvalidArgumentException("Record at index {$index} contains no schema-supported columns for table {$this->table}");
                }

                $signature = implode('|', array_keys($schemaAwareRecord));
                $groups[$signature][] = $schemaAwareRecord;
            }

            // SQL Server: only enable IDENTITY_INSERT when the target table actually has an IDENTITY column.
            // If we blindly SET IDENTITY_INSERT on a non-identity table, SQL Server throws and the job will never succeed.
            $schemaAndObject = $this->parseSchemaAndObject($this->table);
            $hasIdentity = (bool) $conn->selectOne(
                "SELECT 1 as hasIdentity
                 FROM sys.columns c
                 WHERE c.object_id = OBJECT_ID(QUOTENAME(:schema) + '.' + QUOTENAME(:object))
                   AND c.is_identity = 1",
                ['schema' => $schemaAndObject['schema'], 'object' => $schemaAndObject['object']]
            );

            $quotedTable = $this->quoteSqlServerObjectLiteral($schemaAndObject);
            $totalInserted = 0;

            $conn->transaction(function () use ($conn, $groups, $hasIdentity, $quotedTable, &$totalInserted) {
                // IDENTITY_INSERT is session-scoped; toggle once per transaction rather than per row.
                if ($hasIdentity) {
                    $conn->statement("SET IDENTITY_INSERT {$quotedTable} ON");
                }

                try {
                    foreach ($groups as $rows) {
                        $columns = array_keys($rows[0]);
                        $columnCount = count($columns);
                        $columnList = implode(', ', array_map(fn ($col) => $this->quoteSqlServerIdentifier($col), $columns));

                        // SQL Server caps a single statement at 2100 parameters; chunk to stay under that.
                        $maxRowsPerChunk = max(1, intdiv(2000, max(1, $columnCount)));
                        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';

                        foreach (array_chunk($rows, $maxRowsPerChunk) as $chunk) {
                            $valuesClause = implode(', ', array_fill(0, count($chunk), $rowPlaceholder));

                            $bindings = [];
                            foreach ($chunk as $row) {
                                foreach ($columns as $col) {
                                    $bindings[] = $row[$col];
                                }
                            }

                            $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES {$valuesClause}";
                            $conn->statement($sql, $bindings);
                            $totalInserted += count($chunk);
                        }
                    }
                } finally {
                    if ($hasIdentity) {
                        $conn->statement("SET IDENTITY_INSERT {$quotedTable} OFF");
                    }
                }
            });

            Log::info('Relay completed successfully', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'inserted' => $totalInserted,
                'hasIdentity' => $hasIdentity,
            ]);

            Log::channel('relay')->info('Relay completed successfully', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'records_count' => count($this->records),
                'inserted' => $totalInserted,
                'status' => 'success',
            ]);
        } catch (\Throwable $e) {
            Log::error('Bulk insert relay failed', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'primaryKey' => $this->primaryKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Log::channel('relay')->error('Relay processing failed', [
                'relay_id' => $this->relayId,
                'table' => $this->table,
                'records_count' => count($this->records),
                'error' => $e->getMessage(),
                'status' => 'failed',
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessDatabaseRelay job failed permanently', [
            'relay_id' => $this->relayId,
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'records_count' => count($this->records),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        Log::channel('relay')->critical('Relay job failed permanently', [
            'relay_id' => $this->relayId,
            'table' => $this->table,
            'records_count' => count($this->records),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'status' => 'failed_permanently',
        ]);
    }
}
