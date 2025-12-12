<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

class HealthCheckController extends Controller
{
    /**
     * Check the health of the application and its dependencies.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke()
    {
        $status = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
            ],
        ];

        // Determine overall status
        if ($status['services']['database']['status'] !== 'ok' || 
            $status['services']['cache']['status'] !== 'ok') {
            $status['status'] = 'error';
            return response()->json($status, 503);
        }

        return response()->json($status);
    }

    private function checkDatabase()
    {
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => 'ok',
                'latency_ms' => $latency
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Could not connect to the database',
                'error' => config('app.debug') ? $e->getMessage() : null
            ];
        }
    }

    private function checkCache()
    {
        try {
            $startTime = microtime(true);
            Cache::store()->get('health_check'); // Simple read operation
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'ok',
                'driver' => config('cache.default'),
                'latency_ms' => $latency
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Could not connect to cache',
                'error' => config('app.debug') ? $e->getMessage() : null
            ];
        }
    }
}
