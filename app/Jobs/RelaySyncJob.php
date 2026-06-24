<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one relay batch (one table, many rows) to the remote "server"
 * database. Inserts only - existing rows are never updated. Runs on the queue
 * so the POS gets an immediate response, and is retried with backoff on
 * failure (e.g. the remote DB being temporarily unreachable).
 */
class RelaySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $table  Target table (already whitelisted by the controller).
     * @param  array<int, array<string, mixed>>  $rows  Rows to insert.
     */
    public function __construct(
        public string $table,
        public array $rows,
    ) {
        $this->onQueue(config('sync.queue', 'relay'));
    }

    /**
     * Number of attempts before the job is sent to failed_jobs.
     */
    public function tries(): int
    {
        return (int) config('sync.tries', 5);
    }

    /**
     * Seconds to wait between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('sync.backoff', [10, 30, 60, 120, 300]);
    }

    /**
     * Max seconds the job may run before timing out.
     */
    public function timeout(): int
    {
        return (int) config('sync.timeout', 120);
    }

    public function handle(): void
    {
        if (empty($this->rows)) {
            return;
        }

        $connection = config('sync.connection', 'server');
        $db = DB::connection($connection);
       
        $outputLogger = Log::channel('vrpos_queue_output');
        $errorLogger = Log::channel('vrpos_queue_error');
        $settings = config("sync.tables.{$this->table}", []);
        $strategy = $settings['function'] ?? 'insert';
        $key = $settings['primaryKey'] ?? [];

        $inserted = 0;
        $upserted = 0;
        $skipped = 0;
        $skippedRows = [];

        try {
            if($strategy === 'upsert') {
             $upserted = $db->table($this->table)->upsert($this->rows,$key);
            }
            

            else {
            foreach ($this->rows as $row) {
                try {
                    $db->table($this->table)->insert($row);
                    $inserted++;
                } catch (UniqueConstraintViolationException $e) {
                    // Row already present - expected on retries. Skip it.
                    $skipped++;
                    $skippedRows[] = $row;
                }
            }
            }
        } catch (Throwable $e) {
            // Any other error (connection lost, bad column, deadlock, etc.).
            // Log this attempt and rethrow so the queue retries with backoff.
            $errorLogger->error('Relay batch attempt failed', [
                'logged_at' => now()->toIso8601String(),
                'table' => $this->table,
                'connection' => $connection,
                'queue' => $this->queue,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries(),
                'rows' => count($this->rows),
                'inserted_before_failure' => $inserted,
                'skipped_before_failure' => $skipped,
                'payload' => $this->rows,
                'skipped_rows' => $skippedRows,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        $outputLogger->info('Relay batch written', [
            'logged_at' => now()->toIso8601String(),
            'table' => $this->table,
            'connection' => $connection,
            'queue' => $this->queue,
            'attempt' => $this->attempts(),
            'rows_received' => count($this->rows),
            'rows_inserted' => $inserted,
            'rows_skipped' => $skipped,
            'payload' => $this->rows,
            'skipped_rows' => $skippedRows,
        ]);
    }

    /**
     * Called after the final attempt fails.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('vrpos_queue_error')->error('Relay batch permanently failed', [
            'logged_at' => now()->toIso8601String(),
            'table' => $this->table,
            'rows' => count($this->rows),
            'queue' => $this->queue,
            'payload' => $this->rows,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
