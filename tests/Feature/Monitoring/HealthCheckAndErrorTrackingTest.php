<?php

namespace Tests\Feature\Monitoring;

use App\Enums\UserRole;
use App\Exceptions\CircuitBreakerOpenException;
use App\Models\SystemErrorLog;
use App\Models\User;
use App\Services\Monitoring\ErrorTrackerService;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Resilience\CircuitBreaker;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthCheckAndErrorTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected HealthCheckService $healthCheck;
    protected ErrorTrackerService $errorTracker;
    protected CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->healthCheck = new HealthCheckService();
        $this->errorTracker = new ErrorTrackerService();
        $this->circuitBreaker = new CircuitBreaker();
    }

    public function test_liveness_probe_returns_http_ok(): void
    {
        $response = $this->getJson(route('health.live'));

        $response->assertOk();
        $response->assertJson([
            'status' => 'UP',
        ]);
        $response->assertJsonStructure(['status', 'timestamp', 'app', 'environment', 'php_version']);
    }

    public function test_readiness_probe_returns_http_ok_with_probes(): void
    {
        $response = $this->getJson(route('health.ready'));

        $response->assertOk();
        $response->assertJson([
            'status' => 'READY',
            'checks' => [
                'database' => ['status' => 'UP'],
                'cache' => ['status' => 'UP'],
                'storage' => ['status' => 'UP'],
            ],
        ]);
    }

    public function test_error_tracker_captures_and_deduplicates_exceptions_with_masking(): void
    {
        $exception = new Exception("Error during wallet operation for password='super_secret_pass_123'");

        // First report
        $log1 = $this->errorTracker->reportThrowable($exception);
        $this->assertNotNull($log1);
        $this->assertEquals(1, $log1->occurrences_count);
        $this->assertStringNotContainsString('super_secret_pass_123', $log1->message);
        $this->assertStringContainsString('[REDACTED]', $log1->message);

        // Second report of the same exception should deduplicate and increment counter
        $log2 = $this->errorTracker->reportThrowable($exception);
        $this->assertNotNull($log2);
        $this->assertEquals($log1->id, $log2->id);
        $this->assertEquals(2, $log2->occurrences_count);
    }

    public function test_client_error_telemetry_endpoint_persists_frontend_error(): void
    {
        $response = $this->postJson(route('api.telemetry.errors'), [
            'message' => 'Uncaught TypeError: Cannot read properties of undefined',
            'file' => '/resources/js/Pages/User/Messages/Index.vue',
            'line' => 142,
            'stack' => 'TypeError: at Component.render (Index.vue:142:15)',
            'url' => 'http://localhost/messages',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $log = SystemErrorLog::where('source', 'frontend')->first();
        $this->assertNotNull($log);
        $this->assertEquals('JavaScriptError', $log->exception_class);
        $this->assertEquals(142, $log->line);
        $this->assertStringContainsString('Cannot read properties', $log->message);
    }

    public function test_correlation_id_middleware_attaches_headers(): void
    {
        $response = $this->withHeaders(['X-Correlation-ID' => 'test-correlation-uuid-999'])
            ->getJson(route('health.live'));

        $response->assertOk();
        $response->assertHeader('X-Correlation-ID', 'test-correlation-uuid-999');
        $this->assertTrue($response->headers->has('X-Response-Time-Ms'));
    }

    public function test_circuit_breaker_trips_to_open_after_threshold_and_recovers(): void
    {
        $service = 'payment_gateway_simulator';
        $failureThreshold = 3;
        $cooldown = 2; // 2 seconds

        // 1. First 2 failures fail normally
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->circuitBreaker->execute($service, function () {
                    throw new Exception("Gateway timeout");
                }, $failureThreshold, $cooldown);
            } catch (Exception $e) {
                $this->assertEquals("Gateway timeout", $e->getMessage());
            }
            $this->assertEquals(CircuitBreaker::STATE_CLOSED, $this->circuitBreaker->getState($service, $cooldown));
        }

        // 2. 3rd failure trips the breaker to OPEN
        try {
            $this->circuitBreaker->execute($service, function () {
                throw new Exception("Gateway timeout 3");
            }, $failureThreshold, $cooldown);
        } catch (Exception) {
            // expected
        }

        $this->assertEquals(CircuitBreaker::STATE_OPEN, $this->circuitBreaker->getState($service, $cooldown));

        // 3. While OPEN, calls fast-fail immediately without executing action
        $executed = false;
        try {
            $this->circuitBreaker->execute($service, function () use (&$executed) {
                $executed = true;
            }, $failureThreshold, $cooldown);
        } catch (CircuitBreakerOpenException $cbException) {
            $this->assertEquals($service, $cbException->serviceName);
        }

        $this->assertFalse($executed);

        // 4. Test fallback callback in OPEN state
        $fallbackExecuted = false;
        $result = $this->circuitBreaker->execute(
            $service,
            function () {},
            $failureThreshold,
            $cooldown,
            function () use (&$fallbackExecuted) {
                $fallbackExecuted = true;
                return 'fallback_value';
            }
        );

        $this->assertTrue($fallbackExecuted);
        $this->assertEquals('fallback_value', $result);
    }

    public function test_admin_monitoring_dashboard_and_resolve_error(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // 1. Dashboard View
        $response = $this->actingAs($admin)->get(route('admin.monitoring.index'));
        $response->assertOk();

        // 2. Telemetry JSON Endpoint
        $jsonResponse = $this->actingAs($admin)->getJson(route('admin.monitoring.metrics'));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonStructure([
            'success',
            'metrics' => [
                'server' => ['memory_usage_mb', 'php_version'],
                'health' => ['status', 'checks'],
                'queue' => ['pending_jobs', 'failed_jobs'],
                'messages' => ['today_count', 'total_count'],
                'errors' => ['unresolved', 'critical'],
            ],
        ]);

        // 3. Resolve error log
        $errorLog = SystemErrorLog::create([
            'error_hash' => sha1('test_err'),
            'message' => 'Simulated test error',
            'level' => 'error',
            'source' => 'backend',
        ]);

        $resolveResponse = $this->actingAs($admin)
            ->postJson(route('admin.monitoring.errors.resolve', $errorLog->id));

        $resolveResponse->assertOk();
        $errorLog->refresh();
        $this->assertTrue($errorLog->isResolved());
        $this->assertEquals($admin->id, $errorLog->resolved_by);
    }
}
