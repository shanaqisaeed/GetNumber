<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckService
{
    /**
     * Run quick Liveness probe.
     */
    public function checkLiveness(): array
    {
        return [
            'status' => 'UP',
            'timestamp' => now()->toIso8601String(),
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * Run comprehensive Readiness probe.
     */
    public function checkReadiness(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'websocket' => $this->checkWebSocket(),
        ];

        // System is ready if database, cache, and storage are healthy
        $isHealthy = $checks['database']['status'] === 'UP'
            && $checks['cache']['status'] === 'UP'
            && $checks['storage']['status'] === 'UP';

        return [
            'status' => $isHealthy ? 'READY' : 'UNHEALTHY',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    protected function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'UP',
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'DOWN',
                'error' => 'Database connection failed',
            ];
        }
    }

    protected function checkCache(): array
    {
        $start = microtime(true);
        try {
            $key = 'health_check_' . uniqid();
            Cache::put($key, 'ok', 10);
            $val = Cache::get($key);
            Cache::forget($key);

            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $val === 'ok' ? 'UP' : 'DOWN',
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable) {
            return [
                'status' => 'DOWN',
                'error' => 'Cache store unavailable',
            ];
        }
    }

    protected function checkStorage(): array
    {
        try {
            $path = storage_path('framework/cache');
            $writable = is_writable($path);

            return [
                'status' => $writable ? 'UP' : 'DOWN',
                'writable' => $writable,
            ];
        } catch (Throwable) {
            return [
                'status' => 'DOWN',
            ];
        }
    }

    protected function checkQueue(): array
    {
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $pendingCount = DB::table('jobs')->count();

            return [
                'status' => $failedCount < 50 ? 'UP' : 'WARNING',
                'pending_jobs' => $pendingCount,
                'failed_jobs' => $failedCount,
            ];
        } catch (Throwable) {
            return [
                'status' => 'UNKNOWN',
            ];
        }
    }

    protected function checkWebSocket(): array
    {
        $driver = config('broadcasting.default');
        $reverbHost = config('reverb.servers.reverb.host', '127.0.0.1');
        $reverbPort = (int) config('reverb.servers.reverb.port', 8080);

        return [
            'status' => 'UP',
            'driver' => $driver,
            'configured_host' => $reverbHost,
            'configured_port' => $reverbPort,
        ];
    }
}

