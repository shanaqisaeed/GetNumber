<?php

namespace App\DTOs\Services;

use App\Models\ServiceOrder;
use App\Models\ServiceResourceAllocation;

readonly class ServiceProvisionResultData
{
    /**
     * @param  array<int, mixed>  $subResources
     */
    public function __construct(
        public ServiceOrder $order,
        public ?ServiceResourceAllocation $allocation,
        public array $subResources,
        public bool $requiresApproval,
        public string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'requires_approval' => $this->requiresApproval,
            'final_amount' => (string) $this->order->final_amount,
            'requested_identifier' => $this->order->requested_identifier,
            'allocation' => $this->allocation ? [
                'uuid' => $this->allocation->uuid,
                'service_key' => $this->allocation->service_key,
                'channel_type' => $this->allocation->channel_type,
                'resource_identifier' => $this->allocation->resource_identifier,
                'display_title' => $this->allocation->display_title,
                'tier_badge' => $this->allocation->tier_badge,
                'total_quota' => $this->allocation->total_quota,
                'used_quota' => $this->allocation->used_quota,
                'remaining_quota' => $this->allocation->remainingQuota(),
                'status' => $this->allocation->status,
            ] : null,
            'sub_resources_count' => count($this->subResources),
            'message' => $this->message,
        ];
    }
}

