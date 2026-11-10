<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    /**
     * Handle an incoming request with Correlation ID tagging and latency logging.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Accept existing correlation/request ID or generate a new UUID
        $correlationId = $request->header('X-Correlation-ID')
            ?: ($request->header('X-Request-ID') ?: (string) Str::uuid());

        $request->attributes->set('correlation_id', $correlationId);

        // Share context with Laravel's structured logger
        Log::shareContext([
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        // Attach correlation header to outgoing response
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Response-Time-Ms', (string) $durationMs);

        // If slow request (> 1500ms), log a performance notice
        if ($durationMs > 1500 && ! $request->is('broadcasting/*')) {
            Log::warning("Slow HTTP request detected ({$durationMs}ms)", [
                'path' => $request->path(),
                'duration_ms' => $durationMs,
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}