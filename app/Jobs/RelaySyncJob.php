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
 * database. Inserts only — existing rows are never updated. Runs on the queue
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

        $inserted = 0;
        $skipped = 0;

        try {
            // Insert-only, row by row. SQL Server does not support
            // insertOrIgnore, so instead we insert each row and skip any whose
            // primary/unique key already exists. This keeps the job idempotent:
            // a retry that partially landed simply skips the rows already there
            // instead of erroring or overwriting data already on the server.
            foreach ($this->rows as $row) {
                try {
                    $db->table($this->table)->insert($row);
                    $inserted++;
                } catch (UniqueConstraintViolationException $e) {
                    // Row already present — expected on retries. Skip it.
                    $skipped++;
                }
            }
        } catch (Throwable $e) {
            // Any other error (connection lost, bad column, deadlock, etc.).
            // Log this attempt and rethrow so the queue retries with backoff.
            Log::error('Relay batch attempt failed', [
                'table' => $this->table,
                'connection' => $connection,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries(),
                'rows' => count($this->rows),
                'inserted_before_failure' => $inserted,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('Relay batch written', [
            'table' => $this->table,
            'rows_received' => count($this->rows),
            'rows_inserted' => $inserted,
            'rows_skipped' => $skipped,
        ]);
    }

    /**
     * Called after the final attempt fails.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Relay batch permanently failed', [
            'table' => $this->table,
            'rows' => count($this->rows),
            'error' => $e->getMessage(),
        ]);
    }
}
