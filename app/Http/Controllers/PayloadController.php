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