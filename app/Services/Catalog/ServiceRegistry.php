<?php

namespace App\Services\Catalog;

use App\Contracts\Services\BusinessRuleEngineInterface;
use App\Contracts\Services\ServiceRegistryInterface;
use App\Contracts\Services\ServiceStrategyInterface;
use App\Enums\PhoneNumberStatus;
use App\Models\PhoneNumber;
use App\Models\ServiceDefinition;
use App\Models\ServiceResourceAllocation;
use App\Models\User;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ServiceRegistry implements ServiceRegistryInterface
{
    /**
     * @var array<string, class-string<ServiceStrategyInterface>|ServiceStrategyInterface>
     */
    protected array $drivers = [];

    protected bool $seededCheckDone = false;

    public function __construct(
        protected Container $container,
        protected BusinessRuleEngineInterface $ruleEngine
    ) {
        foreach (config('service_policies.services', []) as $key => $config) {
            if (! empty($config['driver_class'])) {
                $this->register($key, $config['driver_class']);
            }
        }
    }

    public function register(string $serviceKey, string|ServiceStrategyInterface $driver): void
    {
        $this->drivers[$serviceKey] = $driver;
    }

    public function has(string $serviceKey): bool
    {
        if (isset($this->drivers[$serviceKey])) {
            return true;
        }

        $definition = ServiceDefinition::query()->where('key', $serviceKey)->first();

        return $definition !== null && class_exists($definition->driver_class);
    }

    public function resolve(string $serviceKey): ServiceStrategyInterface
    {
        if (isset($this->drivers[$serviceKey])) {
            $driver = $this->drivers[$serviceKey];

            return $driver instanceof ServiceStrategyInterface
                ? $driver
                : $this->container->make($driver);
        }

        $definition = ServiceDefinition::query()->where('key', $serviceKey)->first();
        if ($definition && class_exists($definition->driver_class)) {
            $instance = $this->container->make($definition->driver_class);
            if ($instance instanceof ServiceStrategyInterface) {
                $this->drivers[$serviceKey] = $instance;

                return $instance;
            }
        }

        throw new InvalidArgumentException(__('catalog.validation.service_not_found'));
    }

    public function ensureCatalogSeeded(): void
    {
        if ($this->seededCheckDone && ServiceDefinition::query()->exists()) {
            return;
        }

        if (! ServiceDefinition::query()->exists()) {
            app(ServiceCatalogSeeder::class)->run();
        }

        $this->seededCheckDone = true;
    }

    /**
     * Ensure any legacy PhoneNumber records owned by the user are represented in ServiceResourceAllocation.
     */
    public function syncUserLegacyPhoneAllocations(User $user): void
    {
        $this->ensureCatalogSeeded();

        $phoneDef = ServiceDefinition::query()->where('key', 'phone_line')->first();
        if (! $phoneDef) {
            return;
        }

        $allocatedPhones = PhoneNumber::query()
            ->where('user_id', $user->id)
            ->where('status', PhoneNumberStatus::Allocated)
            ->get();

        foreach ($allocatedPhones as $phone) {
            ServiceResourceAllocation::firstOrCreate(
                [
                    'service_key' => 'phone_line',
                    'resource_identifier' => $phone->number,
                    'status' => 'active',
                ],
                [
                    'user_id' => $user->id,
                    'service_definition_id' => $phoneDef->id,
                    'channel_type' => 'sms_line',
                    'display_title' => $phone->display_title ?: $phone->number,
                    'account_tier' => $user->effectiveServiceTier(),
                    'tier_badge' => match ($phone->rond_tier) {
                        'golden_rond' => 'golden_rond',
                        'semi_rond' => 'semi_rond',
                        default => 'standard',
                    },
                    'total_quota' => 1,
                    'used_quota' => 1,
                    'phone_number_id' => $phone->id,
                    'capabilities' => [
                        'inbound_sms' => true,
                        'outbound_sms' => true,
                        'webhook_routing' => true,
                    ],
                    'routing_config' => [
                        'inbound_enabled' => true,
                        'outbound_enabled' => true,
                        'schema_version' => '2.0-ready',
                    ],
                    'metadata' => [
                        'formatted_number' => $phone->formatted_number,
                        'rond_tier' => $phone->rond_tier,
                    ],
                    'allocated_at' => $phone->allocated_at ?? now(),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalogForUser(User $user, ?string $context = null): array
    {
        $this->ensureCatalogSeeded();

        $tier = $this->ruleEngine->resolveTier($user);
        $definitions = ServiceDefinition::query()
            ->with(['plans' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $catalog = [];

        foreach ($definitions as $definition) {
            $policy = $this->ruleEngine->resolvePolicy($definition, $tier);
            $enterprisePolicy = $this->ruleEngine->resolvePolicy($definition, 'enterprise');
            $individualPolicy = $this->ruleEngine->resolvePolicy($definition, 'individual');

            $plans = $definition->plans->map(function ($plan) use ($policy, $enterprisePolicy) {
                $planPolicy = $plan->target_tier === 'enterprise' ? $enterprisePolicy : $policy;
                $discounted = $this->ruleEngine->calculateDiscountedPrice((string) $plan->base_price, $planPolicy);

                return [
                    'id' => $plan->id,
                    'code' => $plan->code,
                    'title' => $plan->title,
                    'description' => $plan->description,
                    'target_tier' => $plan->target_tier,
                    'billing_mode' => $plan->billing_mode,
                    'base_price' => (string) $plan->base_price,
                    'final_price' => $discounted['final_amount'],
                    'discount_percent' => $discounted['discount_percent'],
                    'quota_units' => $plan->quota_units,
                    'metadata' => $plan->metadata ?? [],
                ];
            })->values()->all();

            $catalog[] = [
                'id' => $definition->id,
                'key' => $definition->key,
                'category' => $definition->category,
                'channel_type' => $definition->channel_type,
                'icon' => $definition->icon,
                'title' => $definition->title,
                'short_title' => __($definition->translation_prefix . '.short_title'),
                'subtitle' => $definition->subtitle,
                'description' => $definition->description,
                'enterprise_title' => __($definition->translation_prefix . '.enterprise_title'),
                'enterprise_desc' => __($definition->translation_prefix . '.enterprise_desc'),
                'unit_label' => __($definition->translation_prefix . '.unit_label'),
                'is_active' => $definition->is_active,
                'is_purchasable' => $definition->is_purchasable,
                'requires_approval_for_enterprise' => $definition->requires_approval_for_enterprise,
                'available_for_roles' => $definition->available_for_roles ?? ['individual', 'enterprise'],
                'is_authorized_for_user' => $definition->isAvailableForTier($tier),
                'active_policy' => $policy,
                'tier_policies' => [
                    'individual' => $individualPolicy,
                    'enterprise' => $enterprisePolicy,
                ],
                'form_schema' => $this->localizeFormSchema($definition->form_schema ?? [], $tier),
                'plans' => $plans,
                'pricing_config' => $definition->pricing_config ?? [],
            ];
        }

        return $catalog;
    }

    /**
     * Resolve translation keys inside the dynamic form schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function localizeFormSchema(array $schema, string $tier): array
    {
        $fields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $options = [];
            foreach ($field['options'] ?? [] as $opt) {
                $allowedTiers = $opt['tiers'] ?? ['individual', 'enterprise'];
                $options[] = [
                    'value' => $opt['value'],
                    'label' => isset($opt['label_key']) ? __($opt['label_key']) : (string) $opt['value'],
                    'tiers' => $allowedTiers,
                    'enabled_for_current_tier' => in_array($tier, $allowedTiers, true),
                ];
            }

            $fields[] = [
                'name' => $field['name'],
                'type' => $field['type'],
                'required' => (bool) ($field['required'] ?? false),
                'default' => $field['default'] ?? null,
                'dir' => $field['dir'] ?? null,
                'prefix_symbol' => $field['prefix_symbol'] ?? null,
                'visible_when' => $field['visible_when'] ?? null,
                'label' => isset($field['label_key']) ? __($field['label_key']) : $field['name'],
                'placeholder' => isset($field['placeholder_key']) ? __($field['placeholder_key']) : '',
                'hint' => isset($field['hint_key']) ? __($field['hint_key']) : '',
                'options' => $options,
            ];
        }

        return [
            'version' => $schema['version'] ?? '1.0',
            'fields' => $fields,
        ];
    }
}

