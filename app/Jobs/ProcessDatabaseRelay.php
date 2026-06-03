<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDatabaseRelay implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    protected string $table;

    /**
     * Can be a single column name ("ID") or a comma-separated composite key
     * ("Transaction_ID,POS_ID,Branch_ID").
     */
    protected string $primaryKey;

    /** @var array<int, string> */
    protected array $primaryKeyColumns;

    protected array $records;

    public function __construct(string $table, string $primaryKey, array $records)
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->primaryKeyColumns = $this->parsePrimaryKeyColumns($primaryKey);
        $this->records = $records;
    }

    /**
     * @return array<int, string>
     */
    protected function parsePrimaryKeyColumns(string $primaryKey): array
    {
        // Support comma-separated composite keys.
        $cols = array_map('trim', explode(',', $primaryKey));
        $cols = array_values(array_filter($cols, fn ($c) => $c !== ''));

        if (count($cols) < 1) {
            throw new \InvalidArgumentException('primaryKey must contain at least one column name');
        }

        return $cols;
    }


    public function handle(): void
    {
        Log::info('ProcessDatabaseRelay job started', [
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'records_count' => count($this->records),
            'records' => $this->records,
        ]);

        $inserted = 0;
        $updated = 0;

        try {
            // Test connection
            $test = DB::connection('sqlsrv')->select('SELECT DB_NAME() as dbname');
            Log::info('Connected to SQL Server', ['database' => $test[0]->dbname]);

            Log::info('Using composite primary key columns', [
                'primaryKey' => $this->primaryKey,
                'primaryKeyColumns' => $this->primaryKeyColumns,
            ]);

            foreach ($this->records as $index => $record) {
                // WHERE clause based on all primary key columns
                $pkWhere = [];
                foreach ($this->primaryKeyColumns as $pkCol) {
                    if (!array_key_exists($pkCol, $record)) {
                        throw new \InvalidArgumentException("Record at index {$index} is missing primary key column: {$pkCol}");
                    }
                    $pkWhere[$pkCol] = $record[$pkCol];
                }

                // Check if exists
                $query = DB::connection('sqlsrv')->table($this->table);
                foreach ($pkWhere as $col => $val) {
                    $query->where($col, $val);
                }
                $exists = $query->exists();

                // Build schema-agnostic column sets from record keys
                $columns = array_keys($record);
                $updateData = $record;
                foreach ($this->primaryKeyColumns as $pkCol) {
                    unset($updateData[$pkCol]);
                }

                if ($exists) {
                    // UPDATE using all provided columns except PK(s)
                    if (!empty($updateData)) {
                        $updateQuery = DB::connection('sqlsrv')->table($this->table);
                        foreach ($pkWhere as $col => $val) {
                            $updateQuery->where($col, $val);
                        }
                        $updateQuery->update($updateData);
                    }

                    $updated++;
                    Log::info('Record updated', ['pkWhere' => $pkWhere]);
                } else {
                    // INSERT
                    $values = array_values($record);
                    $columnList = implode(', ', array_map(fn ($col) => "[{$col}]", $columns));
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));

                    $conn = DB::connection('sqlsrv');

                    // SQL Server: only enable IDENTITY_INSERT when the target table actually has an IDENTITY column.
                    // If we blindly SET IDENTITY_INSERT on a non-identity table, SQL Server throws and the job will never succeed.
                    $hasIdentity = (bool) $conn->selectOne(
                        "SELECT 1 as hasIdentity
                         FROM sys.columns c
                         WHERE c.object_id = OBJECT_ID(:tableName)
                           AND c.is_identity = 1",
                        ['tableName' => $this->table]
                    );

                    if ($hasIdentity) {
                        // Single execution so IDENTITY_INSERT ON/OFF are in the same context.
                        $sql = "SET IDENTITY_INSERT [{$this->table}] ON; " .
                            "INSERT INTO [{$this->table}] ({$columnList}) VALUES ({$placeholders}); " .
                            "SET IDENTITY_INSERT [{$this->table}] OFF;";
                        $conn->statement($sql, $values);
                    } else {
                        $sql = "INSERT INTO [{$this->table}] ({$columnList}) VALUES ({$placeholders});";
                        $conn->statement($sql, $values);
                    }

                    $inserted++;
                    Log::info('Record inserted', ['pkWhere' => $pkWhere, 'hasIdentity' => $hasIdentity]);

                }
            }

            Log::info('COMPLETED successfully', [
                'inserted' => $inserted,
                'updated' => $updated,
            ]);

        } catch (\Throwable $e) {

            Log::error('Job failed', [
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
}





