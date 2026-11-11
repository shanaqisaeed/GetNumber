<?php

namespace App\Contracts\Services;

use App\Models\User;

interface ServiceRegistryInterface
{
    /**
     * Register a service strategy handler dynamically (Open/Closed Principle).
     *
     * @param  class-string<ServiceStrategyInterface>|ServiceStrategyInterface  $driver
     */
    public function register(string $serviceKey, string|ServiceStrategyInterface $driver): void;

    /**
     * Resolve a registered service strategy handler by key.
     */
    public function resolve(string $serviceKey): ServiceStrategyInterface;

    /**
     * Check if a service strategy is registered.
     */
    public function has(string $serviceKey): bool;

    /**
     * Ensure default catalog definitions exist in the database.
     */
    public function ensureCatalogSeeded(): void;

    /**
     * Build the localized catalog payload for a given user and view context.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCatalogForUser(User $user, ?string $context = null): array;
}

