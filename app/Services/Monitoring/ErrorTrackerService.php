<?php

namespace App\Services\Monitoring;

use App\Models\SystemErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorTrackerService
{
    /**
     * Report an unhandled or captured server-side Exception.
     */
    public function reportThrowable(Throwable $e, ?Request $request = null): ?SystemErrorLog
    {
        // Don't log normal HTTP 404s or ValidationExceptions to avoid clutter
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
            $e instanceof \Illuminate\Validation\ValidationException) {
            return null;
        }

        try {
            $request = $request ?? (request()->hasHeader('host') ? request() : null);

            $exceptionClass = get_class($e);
            $message = $this->maskSensitiveData($e->getMessage() ?: 'No message');
            $file = $this->maskFilePath($e->getFile());
            $line = $e->getLine();
            $trace = $this->maskSensitiveData($e->getTraceAsString());

            $hash = sha1("backend:{$exceptionClass}:{$file}:{$line}");

            return $this->recordError([
                'error_hash' => $hash,
                'source' => 'backend',
                'level' => ($e instanceof \Error || $e instanceof \ErrorException) ? 'critical' : 'error',
                'exception_class' => $exceptionClass,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'stack_trace' => $trace,
                'url' => $request?->fullUrl(),
                'user_id' => $request?->user()?->id,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'correlation_id' => $request?->attributes->get('correlation_id'),
            ]);
        } catch (Throwable $internalError) {
            // Failsafe: avoid crashing the application during error reporting
            Log::error('ErrorTrackerService internal failure: ' . $internalError->getMessage());
            return null;
        }
    }

    /**
     * Report an error originated from frontend JavaScript / Vue.
     */
    public function reportFrontendError(array $data, ?Request $request = null): ?SystemErrorLog
    {
        try {
            $request = $request ?? (request()->hasHeader('host') ? request() : null);

            $message = $this->maskSensitiveData((string) ($data['message'] ?? 'JavaScript runtime error'));
            $file = $this->maskFilePath((string) ($data['file'] ?? 'unknown'));
            $line = isset($data['line']) ? (int) $data['line'] : null;
            $stack = isset($data['stack']) ? $this->maskSensitiveData((string) $data['stack']) : null;
            $url = isset($data['url']) ? (string) $data['url'] : $request?->fullUrl();

            $hash = sha1("frontend:{$file}:{$line}:" . mb_substr($message, 0, 80));

            return $this->recordError([
                'error_hash' => $hash,
                'source' => 'frontend',
                'level' => 'error',
                'exception_class' => 'JavaScriptError',
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'stack_trace' => $stack,
                'url' => $url,
                'user_id' => $request?->user()?->id,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'correlation_id' => $request?->attributes->get('correlation_id'),
            ]);
        } catch (Throwable $internalError) {
            Log::error('ErrorTrackerService frontend log failure: ' . $internalError->getMessage());
            return null;
        }
    }

    /**
     * Persist or aggregate error record with deduplication.
     */
    protected function recordError(array $attributes): SystemErrorLog
    {
        $attributes['occurrences_count'] = $attributes['occurrences_count'] ?? 1;
        $attributes['last_seen_at'] = $attributes['last_seen_at'] ?? now();

        /** @var SystemErrorLog|null $existing */
        $existing = SystemErrorLog::where('error_hash', $attributes['error_hash'])
            ->whereNull('resolved_at')
            ->where('last_seen_at', '>=', now()->subHours(24))
            ->first();

        if ($existing) {
            $existing->increment('occurrences_count');
            $existing->update([
                'last_seen_at' => now(),
                'url' => $attributes['url'] ?? $existing->url,
            ]);
            return $existing;
        }

        return SystemErrorLog::create($attributes);
    }

    /**
     * Redact sensitive credentials, secrets, tokens, private keys.
     */
    public function maskSensitiveData(string $text): string
    {
        $patterns = [
            '/(["\']?(?:password|token|secret|key|private_key|seed|recovery_code)["\']?\s*[:=]\s*["\'])([^"\']+)(["\'])/i' => '$1[REDACTED]$3',
            '/(Bearer\s+)[a-zA-Z0-9_\-\.]+/i' => '$1[REDACTED_BEARER]',
            '/([a-fA-F0-9]{64})/i' => '[REDACTED_HEX_KEY]', // 256-bit hex private keys
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Normalize file paths for clean storage.
     */
    protected function maskFilePath(string $path): string
    {
        $base = base_path();
        return str_replace([$base, '\\'], ['', '/'], $path);
    }
}
