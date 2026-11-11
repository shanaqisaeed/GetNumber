<?php

namespace App\Http\Controllers\User;

use App\Contracts\Services\ServiceRegistryInterface;
use App\DTOs\Services\ServiceOrderRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreServiceOrderRequest;
use App\Http\Requests\User\StoreSubResourceRequest;
use App\Http\Requests\User\ValidateServiceOrderRequest;
use App\Models\ServiceResourceAllocation;
use App\Models\ServiceSubResource;
use App\Services\Catalog\ServicePurchaseOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceCatalogController extends Controller
{
    public function __construct(
        protected ServiceRegistryInterface $registry,
        protected ServicePurchaseOrchestrator $orchestrator
    ) {}

    /**
     * Real-time validation & tariff quotation endpoint for the frontend purchase wizard.
     */
    public function validateInput(ValidateServiceOrderRequest $request): JsonResponse
    {
        $dto = new ServiceOrderRequestData(
            user: $request->user(),
            serviceKey: (string) $request->input('service_key'),
            planCode: $request->input('plan_code'),
            orderContext: (string) $request->input('order_context', 'services'),
            payload: $request->normalizedPayload()
        );

        $result = $this->orchestrator->validateOrder($dto);

        return response()->json($result->toArray(), $result->canProceed() ? 200 : 422);
    }

    /**
     * Submit and execute a service purchase or enterprise order.
     */
    public function storeOrder(StoreServiceOrderRequest $request): RedirectResponse|JsonResponse
    {
        $dto = new ServiceOrderRequestData(
            user: $request->user(),
            serviceKey: (string) $request->input('service_key'),
            planCode: $request->input('plan_code'),
            orderContext: (string) $request->input('order_context', 'services'),
            payload: $request->normalizedPayload()
        );

        $result = $this->orchestrator->placeOrder($dto);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result->toArray(), 201);
        }

        return redirect()->back()->with('success', $result->message);
    }

    /**
     * Create a new sub-account under a Web3 domain allocation.
     */
    public function storeSubAccount(
        StoreSubResourceRequest $request,
        ServiceResourceAllocation $allocation
    ): RedirectResponse|JsonResponse {
        $sub = $this->orchestrator->createSubAccount(
            user: $request->user(),
            allocation: $allocation,
            subIdentifier: (string) $request->validated('sub_identifier'),
            displayName: $request->validated('display_name')
        );

        $message = __('catalog.orders.sub_account_created', ['address' => $sub->full_address]);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'sub_resource' => $sub,
                'allocation' => $allocation->fresh(['subResources']),
                'message' => $message,
            ], 201);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Remove a sub-account from a Web3 domain allocation.
     */
    public function destroySubAccount(
        Request $request,
        ServiceResourceAllocation $allocation,
        ServiceSubResource $subResource
    ): RedirectResponse|JsonResponse {
        $this->orchestrator->deleteSubAccount($request->user(), $allocation, $subResource);
        $message = __('catalog.orders.sub_account_deleted');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Upgrade/top-up quota units on a Web3 domain allocation.
     */
    public function upgradeQuota(
        Request $request,
        ServiceResourceAllocation $allocation
    ): RedirectResponse|JsonResponse {
        $addedUnits = max(5, min(500, (int) $request->input('additional_units', 10)));
        $updated = $this->orchestrator->upgradeAllocationQuota($request->user(), $allocation, $addedUnits);

        $message = __('catalog.orders.quota_upgraded', [
            'domain' => $updated->resource_identifier,
            'added' => $addedUnits,
        ]);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'allocation' => $updated,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Release/deactivate a service resource allocation.
     */
    public function destroyAllocation(
        Request $request,
        ServiceResourceAllocation $allocation
    ): RedirectResponse|JsonResponse {
        $this->orchestrator->releaseAllocation($request->user(), $allocation);
        $message = __('catalog.orders.resource_released');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Update user's account tier ('individual' | 'enterprise') and organization profile.
     */
    public function updateAccountTier(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'account_tier' => ['required', 'string', 'in:individual,enterprise'],
            'organization_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $user->update([
            'account_tier' => $validated['account_tier'],
            'organization_name' => $validated['organization_name'] ?? $user->organization_name,
            'enterprise_contract_code' => $validated['account_tier'] === 'enterprise'
                ? ($user->enterprise_contract_code ?: ('ENT-' . strtoupper(substr(md5((string) $user->id), 0, 6))))
                : $user->enterprise_contract_code,
        ]);

        $message = __('catalog.tiers.tier_updated_success');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'account_tier' => $user->account_tier,
                'organization_name' => $user->organization_name,
                'enterprise_contract_code' => $user->enterprise_contract_code,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }
}

