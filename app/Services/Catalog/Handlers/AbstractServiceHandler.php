<?php

namespace App\Services\Catalog\Handlers;

use App\Contracts\Services\BusinessRuleEngineInterface;
use App\Contracts\Services\ServiceStrategyInterface;
use App\Models\ServiceResourceAllocation;
use App\Models\User;

abstract class AbstractServiceHandler implements ServiceStrategyInterface
{
    public function __construct(
        protected BusinessRuleEngineInterface $ruleEngine
    ) {}

    /**
     * Check if a resource identifier is already actively allocated for this service.
     */
    protected function isIdentifierAllocated(string $normalizedIdentifier): bool
    {
        return ServiceResourceAllocation::query()
            ->where('service_key', $this->serviceKey())
            ->where('resource_identifier', $normalizedIdentifier)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Generate alternative available suggestions when an identifier is taken.
     *
     * @return array<int, string>
     */
    protected function generateSuggestions(string $baseClean, string $prefix = '', string $suffix = ''): array
    {
        $candidates = [
            $baseClean . '24',
            $baseClean . '_hq',
            $baseClean . '360',
            'my_' . $baseClean,
            $baseClean . '_org',
        ];

        $suggestions = [];
        foreach ($candidates as $candidate) {
            $formatted = $prefix . $candidate . $suffix;
            if (! $this->isIdentifierAllocated($formatted)) {
                $suggestions[] = $formatted;
            }
            if (count($suggestions) >= 3) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Default Phase 2 routing configuration for newly provisioned resources.
     *
     * @return array<string, mixed>
     */
    protected function defaultRoutingConfig(): array
    {
        return [
            'inbound_enabled' => true,
            'outbound_enabled' => true,
            'webhook_url' => null,
            'fallback_channel' => 'inbox',
            'schema_version' => '2.0-ready',
        ];
    }

    /**
     * Release/deactivate an allocated resource.
     */
    public function release(User $user, ServiceResourceAllocation $allocation): bool
    {
        if ($allocation->user_id !== $user->id && ! $user->isAdmin()) {
            return false;
        }

        $allocation->update([
            'status' => 'released',
            'released_at' => now(),
        ]);

        return true;
    }
}

