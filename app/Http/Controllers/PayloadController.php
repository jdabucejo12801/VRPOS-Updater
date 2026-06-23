<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDatabaseRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            $relayId = (string) Str::uuid();

            ProcessDatabaseRelay::dispatch(
                $validated['table'],
                $validated['primaryKey'] ?? null,
                $validated['records'],
                $relayId
            );

            Log::info('Relay job queued', [
                'relay_id' => $relayId,
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'payload' => $validated['records']
            ]);

            Log::channel('relay')->info('Relay job queued', [
                'relay_id' => $relayId,
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'status' => 'queued',
            ]);

            return response()->json([
                'message' => 'Relay job queued successfully',
                'relay_id' => $relayId,
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'status' => 'queued',
            ], 202);

        } catch (\Throwable $e) {

            Log::error('Failed to queue relay job', [
                'table' => $validated['table'],
                'error' => $e->getMessage(),
            ]);

            Log::channel('relay')->error('Relay job queueing failed', [
                'table' => $validated['table'],
                'records_count' => count($validated['records']),
                'error' => $e->getMessage(),
                'status' => 'queue_failed',
            ]);

            return response()->json([
                'message' => 'Failed to queue relay job',
            ], 500);
        }
    }
}
