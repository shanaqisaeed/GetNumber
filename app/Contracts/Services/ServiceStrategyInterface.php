<?php

namespace App\Contracts\Services;

use App\DTOs\Services\ServiceValidationResultData;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\User;

interface ServiceStrategyInterface
{
    /**
     * Unique service key identifier (e.g. 'phone_line', 'custom_id', 'web3_email_package').
     */
    public function serviceKey(): string;

    /**
     * Channel abstraction type for Phase 2 messaging/routing integration.
     */
    public function channelType(): string;

    /**
     * Validate user input against business rules, check availability, and compute tariff quote.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateAndQuote(
        User $user,
        ServiceDefinition $definition,
        array $payload,
        ?ServicePlan $plan = null
    ): ServiceValidationResultData;

    /**
     * Provision/allocate the resource after financial settlement or order approval.
     *
     * @param  array<string, mixed>  $payload
     */
    public function provision(
        User $user,
        ServiceDefinition $definition,
        ServiceOrder $order,
        ServiceValidationResultData $validation,
        array $payload,
        ?ServicePlan $plan = null
    ): ?ServiceResourceAllocation;

    /**
     * Release/deactivate an allocated resource.
     */
    public function release(User $user, ServiceResourceAllocation $allocation): bool;
}

