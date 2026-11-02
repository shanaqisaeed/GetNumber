<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\ErrorTrackerService;
use App\Services\Monitoring\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthCheckController extends Controller
{
    public function __construct(
        protected HealthCheckService $healthCheck,
        protected ErrorTrackerService $errorTracker
    ) {}

    /**
     * Liveness probe (Kubernetes/Load Balancer/Docker ping).
     */
    public function liveness(): JsonResponse
    {
        $data = $this->healthCheck->checkLiveness();
        return response()->json($data, 200);
    }

    /**
     * Readiness probe (checks DB, Cache, Queue).
     */
    public function readiness(): JsonResponse
    {
        $data = $this->healthCheck->checkReadiness();
        $statusCode = $data['status'] === 'READY' ? 200 : 503;

        return response()->json($data, $statusCode);
    }

    /**
     * Receive frontend JavaScript errors telemetry.
     */
    public function reportClientError(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'file' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer'],
            'stack' => ['nullable', 'string', 'max:10000'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->errorTracker->reportFrontendError($validated, $request);

        return response()->json(['success' => true]);
    }
}

