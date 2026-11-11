<?php

namespace Database\Seeders;

use App\Models\ServiceDefinition;
use App\Models\ServicePlan;
use Illuminate\Database\Seeder;

class ServiceCatalogSeeder extends Seeder
{
    /**
     * Seed the multi-service catalog definitions and plans from config/service_policies.php.
     */
    public function run(): void
    {
        $services = config('service_policies.services', []);

        foreach ($services as $key => $config) {
            $definition = ServiceDefinition::updateOrCreate(
                ['key' => $key],
                [
                    'driver_class' => $config['driver_class'],
                    'category' => $config['category'] ?? 'general',
                    'channel_type' => $config['channel_type'] ?? 'generic',
                    'icon' => $config['icon'] ?? 'cube',
                    'translation_prefix' => $config['translation_prefix'] ?? ('catalog.services.' . $key),
                    'is_active' => (bool) ($config['is_active'] ?? true),
                    'is_purchasable' => (bool) ($config['is_purchasable'] ?? true),
                    'requires_approval_for_enterprise' => (bool) ($config['requires_approval_for_enterprise'] ?? false),
                    'available_for_roles' => $config['available_for_roles'] ?? ['individual', 'enterprise'],
                    'form_schema' => $config['form_schema'] ?? [],
                    'validation_policies' => $config['validation_policies'] ?? [],
                    'pricing_config' => $config['pricing_config'] ?? [],
                    'metadata' => $config['metadata'] ?? [],
                    'sort_order' => (int) ($config['sort_order'] ?? 0),
                ]
            );

            foreach ($config['plans'] ?? [] as $planData) {
                ServicePlan::updateOrCreate(
                    ['code' => $planData['code']],
                    [
                        'service_definition_id' => $definition->id,
                        'target_tier' => $planData['target_tier'] ?? 'individual',
                        'billing_mode' => $planData['billing_mode'] ?? 'one_time',
                        'base_price' => (string) ($planData['base_price'] ?? '0.0000'),
                        'quota_units' => (int) ($planData['quota_units'] ?? 1),
                        'is_active' => (bool) ($planData['is_active'] ?? true),
                        'metadata' => $planData['metadata'] ?? [],
                        'sort_order' => (int) ($planData['sort_order'] ?? 0),
                    ]
                );
            }
        }
    }
}

