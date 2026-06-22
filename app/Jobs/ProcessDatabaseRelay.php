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

    public $timeout = 120;
    public $backoff = [10, 30, 60];

    protected string $table;

    /**
     * Can be a single column name ("ID") or a comma-separated composite key
     * ("Transaction_ID,POS_ID,Branch_ID").
     */
    protected ?string $primaryKey;

    /** @var array<int, string> */
    protected array $primaryKeyColumns;

    protected array $records;

    public function __construct(string $table, ?string $primaryKey, array $records)
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->primaryKeyColumns = $this->parsePrimaryKeyColumns($primaryKey);
        $this->records = $records;
    }

    /**
     * @return array<int, string>
     */
    protected function parsePrimaryKeyColumns(?string $primaryKey): array
    {
        if ($primaryKey === null || trim($primaryKey) === '') {
            return [];
        }

        // Support comma-separated composite keys.
        $cols = array_map('trim', explode(',', $primaryKey));
        $cols = array_values(array_filter($cols, fn ($c) => $c !== ''));

        if (count($cols) < 1) {
            throw new \InvalidArgumentException('primaryKey must contain at least one column name');
        }

        return $cols;
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

    protected function resolveColumnName(string $columnName, array $validColumns): ?string
    {
        $normalizedColumnName = strtolower($columnName);

        foreach ($validColumns as $validColumn) {
            if (strtolower((string) $validColumn) === $normalizedColumnName) {
                return (string) $validColumn;
            }
        }

        return null;
    }

    public function tries(): int
    {
        return empty($this->primaryKeyColumns) ? 1 : 3;
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
                'table' => $this->table,
                'primaryKey' => $this->primaryKey,
            ]);

            return;
        }

        Log::info('ProcessDatabaseRelay job started', [
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'primaryKeyColumns' => $this->primaryKeyColumns,
            'records_count' => count($this->records),
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

                foreach ($this->primaryKeyColumns as $primaryKeyColumn) {
                    $resolvedPrimaryKeyColumn = $this->resolveColumnName($primaryKeyColumn, $validColumns);

                    if ($resolvedPrimaryKeyColumn === null) {
                        throw new \InvalidArgumentException("Destination table {$this->table} does not contain primary key column: {$primaryKeyColumn}");
                    }

                    if (!array_key_exists($resolvedPrimaryKeyColumn, $schemaAwareRecord)) {
                        throw new \InvalidArgumentException("Record at index {$index} is missing primary key column: {$primaryKeyColumn}");
                    }
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

            $conn->transaction(function () use ($conn, $groups, $hasIdentity, $quotedTable, $validColumns, &$totalInserted) {
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

                            if (!empty($this->primaryKeyColumns)) {
                                $sql = $this->buildMergeStatement($quotedTable, $columns, $validColumns, $valuesClause);
                            } else {
                                $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES {$valuesClause}";
                            }

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
                'table' => $this->table,
                'inserted' => $totalInserted,
                'hasIdentity' => $hasIdentity,
            ]);
        } catch (\Throwable $e) {
            Log::error('Bulk insert relay failed', [
                'table' => $this->table,
                'primaryKey' => $this->primaryKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessDatabaseRelay job failed permanently', [
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'records_count' => count($this->records),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, string> $validColumns
     */
    protected function buildMergeStatement(string $quotedTable, array $columns, array $validColumns, string $valuesClause): string
    {
        $quotedSourceColumns = implode(', ', array_map(
            fn ($column) => $this->quoteSqlServerIdentifier($column),
            $columns
        ));

        $resolvedPrimaryKeys = array_map(function (string $primaryKeyColumn) use ($validColumns): string {
            $resolvedColumn = $this->resolveColumnName($primaryKeyColumn, $validColumns);

            if ($resolvedColumn === null) {
                throw new \InvalidArgumentException("Destination table {$this->table} does not contain primary key column: {$primaryKeyColumn}");
            }

            return $resolvedColumn;
        }, $this->primaryKeyColumns);

        $onClause = implode(' AND ', array_map(
            fn ($column) => 'target.' . $this->quoteSqlServerIdentifier($column) . ' = source.' . $this->quoteSqlServerIdentifier($column),
            $resolvedPrimaryKeys
        ));

        $updateColumns = array_values(array_filter(
            $columns,
            fn ($column) => !in_array(strtolower($column), array_map('strtolower', $resolvedPrimaryKeys), true)
        ));

        $insertSourceColumnList = implode(', ', array_map(
            fn ($column) => 'source.' . $this->quoteSqlServerIdentifier($column),
            $columns
        ));

        $sql = "MERGE INTO {$quotedTable} AS target "
            . "USING (VALUES {$valuesClause}) AS source ({$quotedSourceColumns}) "
            . "ON {$onClause} ";

        if (!empty($updateColumns)) {
            $updateClause = implode(', ', array_map(
                fn ($column) => 'target.' . $this->quoteSqlServerIdentifier($column)
                    . ' = source.' . $this->quoteSqlServerIdentifier($column),
                $updateColumns
            ));

            $sql .= "WHEN MATCHED THEN UPDATE SET {$updateClause} ";
        }

        $sql .= "WHEN NOT MATCHED THEN INSERT ({$quotedSourceColumns}) VALUES ({$insertSourceColumnList});";

        return $sql;
    }
}
