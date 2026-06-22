<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDatabaseRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayloadController extends Controller
{
    public function relay(Request $request)
    {
        $validated = $request->validate([
            'table' => ['required', 'string', 'max:255'],
            'records' => ['required', 'array', 'min:1'],
            'records.*' => ['required', 'array'],
            'primaryKey' => ['nullable', 'string'],
        ]);

        $allowedTables = config('relay.allowed_tables', []);
        $normalizedAllowedTables = array_map(
            static fn (string $table): string => strtolower(trim($table)),
            array_filter($allowedTables, static fn ($table): bool => is_string($table) && trim($table) !== '')
        );

        if (!in_array(strtolower(trim($validated['table'])), $normalizedAllowedTables, true)) {
            Log::warning('Rejected relay request for non-allowlisted table', [
                'table' => $validated['table'],
            ]);

            return response()->json([
                'message' => 'Table not permitted',
            ], 403);
        }

        try {
            ProcessDatabaseRelay::dispatch(
                $validated['table'],
                $validated['primaryKey'] ?? null,
                $validated['records']
            );

            Log::info('Relay job queued', [
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'payload' => $validated['records']
            ]);

            return response()->json([
                'message' => 'Relay job queued successfully',
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'status' => 'queued',
            ], 202);

        } catch (\Throwable $e) {

            Log::error('Failed to queue relay job', [
                'table' => $validated['table'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to queue relay job',
            ], 500);
        }
    }
}
