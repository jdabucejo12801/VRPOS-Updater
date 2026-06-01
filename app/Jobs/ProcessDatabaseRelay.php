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
    protected string $primaryKey;
    protected array $records;

    public function __construct(string $table, string $primaryKey, array $records)
    {
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->records = $records;
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

            foreach ($this->records as $index => $record) {
                $pkValue = $record[$this->primaryKey];
                
                // Check if exists
                $exists = DB::connection('sqlsrv')
                    ->table($this->table)
                    ->where($this->primaryKey, $pkValue)
                    ->exists();

                if ($exists) {
                    // UPDATE
                    $updateData = $record;
                    unset($updateData[$this->primaryKey]);
                    
                    DB::connection('sqlsrv')
                        ->table($this->table)
                        ->where($this->primaryKey, $pkValue)
                        ->update($updateData);
                    $updated++;
                    
                    Log::info('Record updated', ['pk' => $pkValue]);
                } else {
                    // INSERT with IDENTITY_INSERT in same statement
                    $columns = array_keys($record);
                    $values = array_values($record);
                    
                    $columnList = implode(', ', array_map(fn($col) => "[$col]", $columns));
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    
                    $sql = "
                        SET IDENTITY_INSERT [{$this->table}] ON;
                        INSERT INTO [{$this->table}] ($columnList) VALUES ($placeholders);
                        SET IDENTITY_INSERT [{$this->table}] OFF;
                    ";
                    
                    DB::connection('sqlsrv')->insert($sql, $values);
                    $inserted++;
                    
                    Log::info('Record inserted', ['pk' => $pkValue]);
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





