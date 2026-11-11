<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ServiceRegistryInterface;
use App\DTOs\Services\ServiceOrderRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreServiceOrderRequest;
use App\Http\Requests\User\StoreSubResourceRequest;
use App\Http\Requests\User\ValidateServiceOrderRequest;
use App\Models\ServiceResourceAllocation;
use App\Services\Catalog\ServicePurchaseOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceCatalogApiController extends Controller
{
    public function __construct(
        protected ServiceRegistryInterface $registry,
        protected ServicePurchaseOrchestrator $orchestrator
    ) {}

    /**
     * GET /api/v1/services
     * List all registered services, plans, feature flags, dynamic schemas, and tier policies.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $catalog = $this->registry->getCatalogForUser($user);

        return response()->json([
            'status' => 'ok',
            'account_tier' => $user->effectiveServiceTier(),
            'services' => $catalog,
        ]);
    }

    /**
     * POST /api/v1/services/validate
     * Validate input payload against BusinessRuleEngine & strategy handler and return tariff quote.
     */
    public function validateOrder(ValidateServiceOrderRequest $request): JsonResponse
    {
        $dto = new ServiceOrderRequestData(
            user: $request->user(),
            serviceKey: (string) $request->input('service_key'),
            planCode: $request->input('plan_code'),
            orderContext: (string) $request->input('order_context', 'services'),
            payload: $request->normalizedPayload()
        );

        $result = $this->orchestrator->validateOrder($dto);

        return response()->json([
            'status' => $result->canProceed() ? 'valid' : 'invalid',
            'validation' => $result->toArray(),
        ], $result->canProceed() ? 200 : 422);
    }

    /**
     * POST /api/v1/services/orders
     * Submit and execute a multi-service purchase order.
     */
    public function storeOrder(StoreServiceOrderRequest $request): JsonResponse
    {
        $dto = new ServiceOrderRequestData(
            user: $request->user(),
            serviceKey: (string) $request->input('service_key'),
            planCode: $request->input('plan_code'),
            orderContext: (string) $request->input('order_context', 'services'),
            payload: $request->normalizedPayload()
        );

        $result = $this->orchestrator->placeOrder($dto);

        return response()->json([
            'status' => 'created',
            'data' => $result->toArray(),
        ], 201);
    }

    /**
     * GET /api/v1/services/allocations
     * List active service resource allocations and sub-resources for the authenticated user.
     */
    public function allocations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (method_exists($this->registry, 'syncUserLegacyPhoneAllocations')) {
            $this->registry->syncUserLegacyPhoneAllocations($user);
        }

        $allocations = ServiceResourceAllocation::query()
            ->with(['subResources', 'serviceDefinition'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('allocated_at')
            ->get()
            ->map(fn (ServiceResourceAllocation $item) => [
                'uuid' => $item->uuid,
                'service_key' => $item->service_key,
                'channel_type' => $item->channel_type,
                'resource_identifier' => $item->resource_identifier,
                'display_title' => $item->display_title,
                'account_tier' => $item->account_tier,
                'tier_badge' => $item->tier_badge,
                'tier_label' => __('catalog.tiers_badges.' . $item->tier_badge),
                'total_quota' => $item->total_quota,
                'used_quota' => $item->used_quota,
                'remaining_quota' => $item->remainingQuota(),
                'capabilities' => $item->capabilities ?? [],
                'sub_resources' => $item->subResources->map(fn ($sub) => [
                    'id' => $sub->id,
                    'sub_identifier' => $sub->sub_identifier,
                    'full_address' => $sub->full_address,
                    'display_name' => $sub->display_name,
                    'status' => $sub->status,
                ])->values()->all(),
                'allocated_at' => $item->allocated_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'ok',
            'allocations' => $allocations,
        ]);
    }

    /**
     * POST /api/v1/services/allocations/{allocation:uuid}/sub-accounts
     * Create a new sub-account under a Web3 domain allocation.
     */
    public function storeSubAccount(
        StoreSubResourceRequest $request,
        ServiceResourceAllocation $allocation
    ): JsonResponse {
        $sub = $this->orchestrator->createSubAccount(
            user: $request->user(),
            allocation: $allocation,
            subIdentifier: (string) $request->validated('sub_identifier'),
            displayName: $request->validated('display_name')
        );

        return response()->json([
            'status' => 'created',
            'sub_resource' => [
                'id' => $sub->id,
                'sub_identifier' => $sub->sub_identifier,
                'full_address' => $sub->full_address,
                'display_name' => $sub->display_name,
                'status' => $sub->status,
            ],
            'quota' => [
                'total_quota' => $allocation->fresh()->total_quota,
                'used_quota' => $allocation->fresh()->used_quota,
                'remaining_quota' => $allocation->fresh()->remainingQuota(),
            ],
        ], 201);
    }
}

