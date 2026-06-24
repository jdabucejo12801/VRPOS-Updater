<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\RelaySyncJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate([
            'table' => 'required|string',
            'primaryKey' => 'required',
            'records' => 'required|array|min:1',
            'records.*' => 'required|array|min:1',
        ]);

        $table = $data['table'];

        // The table name comes from the request, so it must be on the
        // whitelist before we ever build a query against it.
        $allowed = config('sync.tables', []);

        if (! array_key_exists($table, $allowed)) {
            throw ValidationException::withMessages([
                'table' => "Table [{$table}] is not allowed for relay.",
            ]);
        }

        $rows = $this->sanitizeRows($data['records'], $table);

        RelaySyncJob::dispatch($table, $rows);

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
