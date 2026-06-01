<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessDatabaseRelay;
use Illuminate\Support\Facades\Log;

class PayloadController extends Controller
{
    public function relay(Request $request)
    {
        $validated = $request->validate([
            'table' => ['required', 'string', 'min:1', 'max:255'],
            'primaryKey' => ['required', 'string', 'min:1', 'max:255'],
            'records' => ['required', 'array', 'min:1'],
            'records.*' => ['required', 'array'],
        ]);

        $table = $validated['table'];
        $primaryKey = $validated['primaryKey'];
        $records = $validated['records'];

        Log::info('Relay request received', [
            'table' => $table,
            'primaryKey' => $primaryKey,
            'records_count' => count($records),
        ]);

        try {
            // Dispatch job to queue
            ProcessDatabaseRelay::dispatch($table, $primaryKey, $records);

            Log::info('Job dispatched successfully', [
                'table' => $table,
                'records_count' => count($records),
            ]);

            return response()->json([
                'message' => 'Relay job queued successfully',
                'table' => $table,
                'records_count' => count($records),
                'status' => 'queued',
            ], 202);

        } catch (\Throwable $e) {
            Log::error('Failed to dispatch relay job', [
                'table' => $table,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to queue relay job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

