<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Http;

class PayloadController extends Controller
{
    public function relay(Request $request)
    {
        $validated = $request->validate([
            'table' => ['required', 'string', 'min:1'],
            'primaryKey' => ['required', 'string', 'min:1'],
            'records' => ['required', 'array'],
        ]);

        $table = $validated['table'];
        $primaryKey = $validated['primaryKey'];
        $records = $validated['records'];

        $payloadToForward = [
            'table' => $table,
            'primaryKey' => $primaryKey,
            'records' => $records,
            // preserve any extra fields if the external sender includes them
            'meta' => $request->except(['table', 'primaryKey', 'records']),
        ];

        $backofficeUrl = rtrim(config('services.backoffice.url', ''), '/');
        if ($backofficeUrl === '') {
            return response()->json([
                'message' => 'Back-office destination URL is not configured',
            ], 500);
        }

        $token = config('services.backoffice.token');

        try {
            $http = Http::timeout(30)->acceptJson();
            if (filled($token)) {
                $http = $http->withToken($token);
            }

            $response = $http->post($backofficeUrl . '/api/relay', $payloadToForward);

            return response()->json([
                'message' => 'Relayed to back-office',
                'backoffice' => [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ],
            ], $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to relay to back-office',
                'error' => $e->getMessage(),
            ], 502);
        }
    }
}

