<?php

namespace App\Services\Catalog;

use App\Contracts\Services\BusinessRuleEngineInterface;
use App\Contracts\Services\ServiceRegistryInterface;
use App\DTOs\Services\ServiceOrderRequestData;
use App\DTOs\Services\ServiceProvisionResultData;
use App\DTOs\Services\ServiceValidationResultData;
use App\Models\AuditLog;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\ServiceSubResource;
use App\Models\User;
use App\Services\Catalog\Handlers\Web3EmailPackageServiceHandler;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicePurchaseOrchestrator
{
    public function __construct(
        protected ServiceRegistryInterface $registry,
        protected BusinessRuleEngineInterface $ruleEngine,
        protected WalletService $walletService
    ) {}

    /**
     * Validate a service order request against the registered strategy and business rules engine.
     */
    public function validateOrder(ServiceOrderRequestData $request): ServiceValidationResultData
    {
        $this->registry->ensureCatalogSeeded();

        $definition = ServiceDefinition::query()->where('key', $request->serviceKey)->first();
        if (! $definition) {
            return new ServiceValidationResultData(
                isValid: false,
                isAvailable: false,
                normalizedIdentifier: '',
                errors: [__('catalog.validation.service_not_found')],
            );
        }

        $plan = $this->resolvePlan($definition, $request->planCode, $request->payload);
        $handler = $this->registry->resolve($definition->key);

        return $handler->validateAndQuote(
            user: $request->user,
            definition: $definition,
            payload: $request->payload,
            plan: $plan
        );
    }

    /**
     * Execute an atomic multi-service order (validate, charge/hold wallet, create order, provision resource).
     */
    public function placeOrder(ServiceOrderRequestData $request): ServiceProvisionResultData
    {
        $this->registry->ensureCatalogSeeded();

        $definition = ServiceDefinition::query()->where('key', $request->serviceKey)->first();
        if (! $definition) {
            throw ValidationException::withMessages([
                'service_key' => [__('catalog.validation.service_not_found')],
            ]);
        }

        $plan = $this->resolvePlan($definition, $request->planCode, $request->payload);
        $handler = $this->registry->resolve($definition->key);

        return DB::transaction(function () use ($request, $definition, $plan, $handler) {
            $validation = $handler->validateAndQuote(
                user: $request->user,
                definition: $definition,
                payload: $request->payload,
                plan: $plan
            );

            if (! $validation->canProceed()) {
                throw ValidationException::withMessages([
                    'identifier' => $validation->errors ?: [__('catalog.validation.invalid_format')],
                ]);
            }

            $tier = $this->ruleEngine->resolveTier($request->user);
            $orderNumber = 'SRV-' . strtoupper(Str::random(8)) . '-' . now()->format('His');
            $finalAmount = $validation->finalAmount;
            $requiresApproval = $validation->requiresApproval;

            $order = ServiceOrder::create([
                'order_number' => $orderNumber,
                'user_id' => $request->user->id,
                'service_definition_id' => $definition->id,
                'service_plan_id' => $plan?->id,
                'account_tier' => $tier,
                'order_context' => $request->orderContext,
                'fulfillment_mode' => $requiresApproval ? 'approval' : 'instant',
                'status' => $requiresApproval ? 'pending_approval' : 'completed',
                'requested_identifier' => $validation->normalizedIdentifier,
                'unit_price' => $validation->unitPrice,
                'discount_amount' => $validation->discountAmount,
                'final_amount' => $finalAmount,
                'held_amount' => '0.0000',
                'input_payload' => $request->payload,
                'validation_snapshot' => $validation->toArray(),
                'fulfilled_at' => $requiresApproval ? null : now(),
            ]);

            if ($requiresApproval) {
                $tx = $this->walletService->holdForNumberRequest(
                    user: $request->user,
                    amount: $finalAmount,
                    referenceId: $orderNumber,
                    description: $definition->title . ' (' . $validation->normalizedIdentifier . ')',
                    metadata: [
                        'order_id' => $order->id,
                        'service_key' => $definition->key,
                        'identifier' => $validation->normalizedIdentifier,
                    ]
                );

                $order->update([
                    'held_amount' => $finalAmount,
                    'wallet_reference_id' => $tx->reference_id,
                ]);
            } elseif (bccomp($finalAmount, '0.0000', 4) > 0) {
                $tx = $this->walletService->chargeForNumberPurchase(
                    user: $request->user,
                    amount: $finalAmount,
                    referenceId: $orderNumber,
                    description: $definition->title . ' (' . $validation->normalizedIdentifier . ')',
                    metadata: [
                        'order_id' => $order->id,
                        'service_key' => $definition->key,
                        'identifier' => $validation->normalizedIdentifier,
                        'tier_badge' => $validation->tierBadge,
                    ]
                );

                $order->update(['wallet_reference_id' => $tx->reference_id]);
            }

            $allocation = $handler->provision(
                user: $request->user,
                definition: $definition,
                order: $order,
                validation: $validation,
                payload: $request->payload,
                plan: $plan
            );

            AuditLog::record(
                action: 'service.order_placed',
                entity: $order,
                actor: $request->user,
                payload: [
                    'order_number' => $order->order_number,
                    'service_key' => $definition->key,
                    'identifier' => $validation->normalizedIdentifier,
                    'account_tier' => $tier,
                    'final_amount' => $finalAmount,
                    'fulfillment_mode' => $order->fulfillment_mode,
                ]
            );

            $message = $requiresApproval
                ? __('catalog.orders.order_placed_approval', [
                    'service' => $definition->title,
                    'identifier' => $validation->normalizedIdentifier,
                    'amount' => $finalAmount,
                ])
                : __('catalog.orders.order_placed_instant', [
                    'service' => $definition->title,
                    'identifier' => $validation->normalizedIdentifier,
                ]);

            return new ServiceProvisionResultData(
                order: $order->fresh(),
                allocation: $allocation?->fresh(['subResources']),
                subResources: $allocation?->subResources?->all() ?? [],
                requiresApproval: $requiresApproval,
                message: $message
            );
        });
    }

    /**
     * Provision a new sub-account under a Web3 domain allocation.
     */
    public function createSubAccount(
        User $user,
        ServiceResourceAllocation $allocation,
        string $subIdentifier,
        ?string $displayName = null
    ): ServiceSubResource {
        if ($allocation->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $handler = $this->registry->resolve($allocation->service_key);
        if (! $handler instanceof Web3EmailPackageServiceHandler) {
            throw ValidationException::withMessages([
                'sub_identifier' => [__('catalog.validation.service_not_found')],
            ]);
        }

        return DB::transaction(fn () => $handler->createSubAccount($user, $allocation, $subIdentifier, $displayName));
    }

    /**
     * Delete a sub-account and restore quota.
     */
    public function deleteSubAccount(
        User $user,
        ServiceResourceAllocation $allocation,
        ServiceSubResource $subResource
    ): void {
        if ($allocation->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $handler = $this->registry->resolve($allocation->service_key);
        if ($handler instanceof Web3EmailPackageServiceHandler) {
            DB::transaction(fn () => $handler->deleteSubAccount($user, $allocation, $subResource));
        }
    }

    /**
     * Upgrade/top-up sub-account quota on an existing Web3 domain allocation.
     */
    public function upgradeAllocationQuota(
        User $user,
        ServiceResourceAllocation $allocation,
        int $additionalUnits = 10,
        string $unitPackagePrice = '45.0000'
    ): ServiceResourceAllocation {
        if ($allocation->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $definition = $allocation->serviceDefinition;
        $tier = $this->ruleEngine->resolveTier($user);
        $policy = $this->ruleEngine->resolvePolicy($definition, $tier);
        $priceData = $this->ruleEngine->calculateDiscountedPrice($unitPackagePrice, $policy);

        return DB::transaction(function () use ($user, $allocation, $additionalUnits, $priceData) {
            if (bccomp($priceData['final_amount'], '0.0000', 4) > 0) {
                $this->walletService->chargeForNumberPurchase(
                    user: $user,
                    amount: $priceData['final_amount'],
                    referenceId: 'UPG-' . strtoupper(Str::random(10)),
                    description: $allocation->resource_identifier . ' (+' . $additionalUnits . ' Quota)',
                    metadata: [
                        'allocation_uuid' => $allocation->uuid,
                        'additional_units' => $additionalUnits,
                    ]
                );
            }

            $allocation->increment('total_quota', $additionalUnits);

            return $allocation->fresh(['subResources']);
        });
    }

    /**
     * Release an allocated resource.
     */
    public function releaseAllocation(User $user, ServiceResourceAllocation $allocation): bool
    {
        $handler = $this->registry->resolve($allocation->service_key);

        return DB::transaction(fn () => $handler->release($user, $allocation));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolvePlan(ServiceDefinition $definition, ?string $planCode, array $payload): ?ServicePlan
    {
        $code = $planCode ?: ($payload['plan_code'] ?? null);
        if (! $code) {
            return null;
        }

        return $definition->plans()->where('code', $code)->first();
    }
}

