<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\RelaySyncJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayloadController extends Controller
{
    /**
     * Receive a relay payload from a POS and queue it for insertion into the
     * remote "server" database. Responds immediately (202); the actual write
     * happens on the queue and is retried on failure.
     *
     * Expected body:
     *   {
     *     "table": "Transaction_Items",
     *     "primaryKey": ["Transaction_ID", "POS_ID", "Branch_ID"], // or "A,B,C"
     *     "payload": [ { ...row... }, { ...row... } ]
     *   }
     */
    public function handleRelay(Request $request): JsonResponse
    {
        $inputLogger = Log::channel('vrpos_queue_input');
        $errorLogger = Log::channel('vrpos_queue_error');

        $inputLogger->info('Relay payload received', [
            'received_at' => now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'headers' => $request->headers->all(),
            'raw_body' => $request->getContent(),
            'parsed_body' => $request->all(),
        ]);

        $validator = Validator::make($request->all(), [
            'table' => 'required|string',
            'primaryKey' => 'required',
            'records' => 'required|array|min:1',
            'records.*' => 'required|array|min:1',
        ]);

        if ($validator->fails()) {
            $context = [
                'received_at' => now()->toIso8601String(),
                'errors' => $validator->errors()->toArray(),
                'raw_body' => $request->getContent(),
                'parsed_body' => $request->all(),
            ];

            $inputLogger->error('Relay payload validation failed', $context);
            $errorLogger->error('Relay payload validation failed', $context);

            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $table = $data['table'];

        // The table name comes from the request, so it must be on the
        // whitelist before we ever build a query against it.
        $allowed = config('sync.tables', []);

        if (! array_key_exists($table, $allowed)) {
            $context = [
                'received_at' => now()->toIso8601String(),
                'table' => $table,
                'raw_body' => $request->getContent(),
                'parsed_body' => $data,
            ];

            $inputLogger->error('Relay payload rejected: table not allowed', $context);
            $errorLogger->error('Relay payload rejected: table not allowed', $context);

            throw ValidationException::withMessages([
                'table' => "Table [{$table}] is not allowed for relay.",
            ]);
        }

        try {
            $rows = $this->sanitizeRows($data['records'], $table);

            RelaySyncJob::dispatch($table, $rows);
        } catch (ValidationException $e) {
            $errorLogger->error('Relay payload rejected during sanitization', [
                'received_at' => now()->toIso8601String(),
                'table' => $table,
                'errors' => $e->errors(),
                'raw_body' => $request->getContent(),
                'parsed_body' => $data,
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $errorLogger->error('Relay payload failed before queue dispatch', [
                'received_at' => now()->toIso8601String(),
                'table' => $table,
                'raw_body' => $request->getContent(),
                'parsed_body' => $data,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        $inputLogger->info('Relay payload queued', [
            'received_at' => now()->toIso8601String(),
            'table' => $table,
            'rows' => count($rows),
            'queue' => config('sync.queue', 'relay'),
            'payload' => $rows,
        ]);

        return response()->json([
            'status' => 'queued',
            'table' => $table,
            'rows' => count($rows),
        ], 202);
    }

    /**
     * Guard column names against the table's known columns so a payload can't
     * smuggle unexpected/unsafe column identifiers into the insert.
     *
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeRows(array $payload, string $table): array
    {
        $allowedColumns = config("sync.columns.{$table}");

        return array_map(function (array $row) use ($allowedColumns, $table) {
            foreach (array_keys($row) as $column) {
                if (! is_string($column) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    throw ValidationException::withMessages([
                        'records' => "Invalid column name [{$column}] for table [{$table}].",
                    ]);
                }

                if (is_array($allowedColumns) && ! in_array($column, $allowedColumns, true)) {
                    throw ValidationException::withMessages([
                        'records' => "Column [{$column}] is not allowed for table [{$table}].",
                    ]);
                }
            }

            return $row;
        }, $payload);
    }
}
