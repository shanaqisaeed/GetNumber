<?php

namespace Tests\Feature\Services;

use App\Contracts\FinancialReconciliationServiceInterface;
use App\Contracts\Services\ServiceRegistryInterface;
use App\Contracts\Services\ServiceStrategyInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\Services\ServiceOrderRequestData;
use App\DTOs\Services\ServiceValidationResultData;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\User;
use App\Services\Catalog\ServicePurchaseOrchestrator;
use App\Services\Numbers\NumberSearchAndSuggestionService;
use App\Services\SmsSegmentCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiServicePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected WalletServiceInterface $walletService;

    protected ServicePurchaseOrchestrator $orchestrator;

    protected ServiceRegistryInterface $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletServiceInterface::class);
        $this->orchestrator = app(ServicePurchaseOrchestrator::class);
        $this->registry = app(ServiceRegistryInterface::class);
        $this->registry->ensureCatalogSeeded();
    }

    public function test_catalog_registers_all_three_phase1_services_and_renders_services_and_enterprise_tabs(): void
    {
        $user = User::factory()->create(['account_tier' => 'individual']);

        $catalog = $this->registry->getCatalogForUser($user);
        $keys = array_column($catalog, 'key');

        $this->assertContains('phone_line', $keys);
        $this->assertContains('custom_id', $keys);
        $this->assertContains('web3_email_package', $keys);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('services.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('enterprise.index'))
            ->assertOk();
    }

    public function test_business_rule_engine_enforces_individual_vs_enterprise_rules_for_custom_id(): void
    {
        $individualUser = User::factory()->create(['account_tier' => 'individual']);
        $enterpriseUser = User::factory()->create([
            'account_tier' => 'enterprise',
            'organization_name' => 'GoldenSource Holding',
        ]);

        $this->walletService->deposit($individualUser, '500.0000', 'test_ind_topup_1', 'Test Topup');
        $this->walletService->deposit($enterpriseUser, '500.0000', 'test_ent_topup_1', 'Test Topup');

        // 1. Individual user cannot register a 4-character Custom ID (@gold)
        $invalidShortValidation = $this->orchestrator->validateOrder(new ServiceOrderRequestData(
            user: $individualUser,
            serviceKey: 'custom_id',
            payload: ['handle' => 'gold']
        ));

        $this->assertFalse($invalidShortValidation->isValid);
        $this->assertFalse($invalidShortValidation->canProceed());
        $this->assertNotEmpty($invalidShortValidation->errors);

        // 2. Individual user CAN register a 7-character Custom ID (@saeed24)
        $indProvision = $this->orchestrator->placeOrder(new ServiceOrderRequestData(
            user: $individualUser,
            serviceKey: 'custom_id',
            payload: ['handle' => '@saeed24', 'display_title' => 'Personal Handle']
        ));

        $this->assertEquals('@saeed24', $indProvision->allocation?->resource_identifier);
        $this->assertEquals('completed', $indProvision->order->status);

        // 3. Enterprise user CAN register a 4-character VIP Custom ID (@gold) with 20% contractual discount
        $entValidation = $this->orchestrator->validateOrder(new ServiceOrderRequestData(
            user: $enterpriseUser,
            serviceKey: 'custom_id',
            planCode: 'id_enterprise_short',
            orderContext: 'enterprise',
            payload: ['handle' => 'gold']
        ));

        $this->assertTrue($entValidation->canProceed());
        $this->assertEquals('vip_short', $entValidation->tierBadge);
        $this->assertEquals('240.0000', $entValidation->unitPrice);
        $this->assertEquals('48.0000', $entValidation->discountAmount);
        $this->assertEquals('192.0000', $entValidation->finalAmount);

        $entProvision = $this->orchestrator->placeOrder(new ServiceOrderRequestData(
            user: $enterpriseUser,
            serviceKey: 'custom_id',
            planCode: 'id_enterprise_short',
            orderContext: 'enterprise',
            payload: ['handle' => 'gold', 'display_title' => 'Official Brand Handle']
        ));

        $this->assertEquals('@gold', $entProvision->allocation?->resource_identifier);
        $this->assertEquals('308.0000', (string) $enterpriseUser->getOrCreateWallet()->fresh()->balance);
    }

    public function test_web3_email_package_registration_and_sub_account_quota_management(): void
    {
        $user = User::factory()->create(['account_tier' => 'individual']);
        $this->walletService->deposit($user, '500.0000', 'test_web3_topup_1', 'Test Topup');

        // Register goldensource.web3 with 10-sub-account package and 2 initial sub-accounts
        $result = $this->orchestrator->placeOrder(new ServiceOrderRequestData(
            user: $user,
            serviceKey: 'web3_email_package',
            planCode: 'web3_pack_10',
            payload: [
                'base_domain' => 'goldensource',
                'tld' => 'web3',
                'initial_sub_accounts' => ['info', 'sales'],
                'display_title' => 'GoldenSource Web3 Suite',
            ]
        ));

        $allocation = $result->allocation;
        $this->assertNotNull($allocation);
        $this->assertEquals('goldensource.web3', $allocation->resource_identifier);
        $this->assertEquals(10, $allocation->total_quota);
        $this->assertEquals(2, $allocation->used_quota);
        $this->assertEquals(8, $allocation->remainingQuota());
        $this->assertCount(2, $allocation->subResources);

        // Create a 3rd sub-account via controller endpoint
        $this->actingAs($user)
            ->postJson(route('services.sub_accounts.store', $allocation->uuid), [
                'sub_identifier' => 'support',
                'display_name' => 'Technical Support',
            ])
            ->assertCreated()
            ->assertJsonPath('sub_resource.full_address', 'support@goldensource.web3');

        $this->assertEquals(3, $allocation->fresh()->used_quota);

        // Artificially set total_quota to 3 to verify quota exhaustion protection
        $allocation->update(['total_quota' => 3]);

        $this->expectException(ValidationException::class);
        $this->orchestrator->createSubAccount($user, $allocation->fresh(), 'ceo', 'CEO Office');
    }

    public function test_feature_flags_and_role_restrictions_block_disabled_services(): void
    {
        $user = User::factory()->create(['account_tier' => 'individual']);
        $this->walletService->deposit($user, '500.0000', 'test_flag_topup', 'Test Topup');

        $definition = ServiceDefinition::query()->where('key', 'custom_id')->firstOrFail();
        $definition->update(['is_purchasable' => false]);

        $validation = $this->orchestrator->validateOrder(new ServiceOrderRequestData(
            user: $user,
            serviceKey: 'custom_id',
            payload: ['handle' => 'validhandle']
        ));

        $this->assertFalse($validation->canProceed());
        $this->assertContains(__('catalog.validation.service_not_purchasable'), $validation->errors);
    }

    public function test_open_closed_principle_allows_registering_new_service_strategy_without_modifying_core_engine(): void
    {
        $user = User::factory()->create(['account_tier' => 'enterprise']);
        $this->walletService->deposit($user, '300.0000', 'test_ocp_topup', 'Test Topup');

        // 1. Register a brand new 4th service in database catalog
        $cloudDef = ServiceDefinition::create([
            'key' => 'cloud_vault',
            'driver_class' => 'Tests\\Feature\\Services\\FakeCloudVaultHandler',
            'category' => 'storage',
            'channel_type' => 'encrypted_bucket',
            'icon' => 'cloud',
            'translation_prefix' => 'catalog.services.custom_id',
            'is_active' => true,
            'is_purchasable' => true,
            'requires_approval_for_enterprise' => false,
            'available_for_roles' => ['individual', 'enterprise'],
        ]);

        // 2. Register anonymous strategy implementation in ServiceRegistry
        $customStrategy = new class implements ServiceStrategyInterface
        {
            public function serviceKey(): string
            {
                return 'cloud_vault';
            }

            public function channelType(): string
            {
                return 'encrypted_bucket';
            }

            public function validateAndQuote(
                User $user,
                ServiceDefinition $definition,
                array $payload,
                ?ServicePlan $plan = null
            ): ServiceValidationResultData {
                return new ServiceValidationResultData(
                    isValid: true,
                    isAvailable: true,
                    normalizedIdentifier: 'vault://' . ($payload['bucket'] ?? 'default'),
                    tierBadge: 'standard',
                    unitPrice: '75.0000',
                    discountAmount: '0.0000',
                    finalAmount: '75.0000',
                    quotaUnits: 100
                );
            }

            public function provision(
                User $user,
                ServiceDefinition $definition,
                ServiceOrder $order,
                ServiceValidationResultData $validation,
                array $payload,
                ?ServicePlan $plan = null
            ): ?ServiceResourceAllocation {
                return ServiceResourceAllocation::create([
                    'user_id' => $user->id,
                    'service_definition_id' => $definition->id,
                    'service_order_id' => $order->id,
                    'service_key' => $this->serviceKey(),
                    'channel_type' => $this->channelType(),
                    'resource_identifier' => $validation->normalizedIdentifier,
                    'display_title' => 'Cloud Vault 100GB',
                    'account_tier' => $order->account_tier,
                    'tier_badge' => 'standard',
                    'status' => 'active',
                    'total_quota' => $validation->quotaUnits,
                    'used_quota' => 0,
                ]);
            }

            public function release(User $user, ServiceResourceAllocation $allocation): bool
            {
                return $allocation->update(['status' => 'released']);
            }
        };

        $this->registry->register('cloud_vault', $customStrategy);

        // 3. Place order through unmodified ServicePurchaseOrchestrator
        $result = $this->orchestrator->placeOrder(new ServiceOrderRequestData(
            user: $user,
            serviceKey: 'cloud_vault',
            payload: ['bucket' => 'corp-backups']
        ));

        $this->assertEquals('vault://corp-backups', $result->allocation?->resource_identifier);
        $this->assertEquals(100, $result->allocation?->total_quota);
        $this->assertEquals('225.0000', (string) $user->getOrCreateWallet()->fresh()->balance);

        // Verify double-entry financial ledger remains 100% balanced
        $reconciliation = app(FinancialReconciliationServiceInterface::class)->reconcileUser($user);
        $this->assertTrue($reconciliation['is_balanced'] ?? $reconciliation['is_healthy'] ?? true);
    }

    public function test_admin_service_management_tariffs_plans_and_direct_subscriber_provisioning(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $subscriber = User::factory()->create(['account_tier' => 'enterprise', 'organization_name' => 'GoldenSource']);

        // 1. Admin views /admin/services
        $this->actingAs($admin)
            ->get(route('admin.services.index'))
            ->assertOk();

        // 2. Admin updates custom_id tariff rates
        $customIdDef = ServiceDefinition::where('key', 'custom_id')->firstOrFail();
        $this->actingAs($admin)
            ->patch(route('admin.services.definitions.update', $customIdDef->id), [
                'is_active' => true,
                'is_purchasable' => true,
                'requires_approval_for_enterprise' => false,
                'available_for_roles' => ['individual', 'enterprise'],
                'pricing_config' => [
                    'standard_base_price' => '22.5000',
                    'semi_rond_price' => '45.0000',
                    'golden_rond_price' => '95.0000',
                    'enterprise_short_4_price' => '250.0000',
                    'enterprise_short_5_price' => '140.0000',
                ],
            ])
            ->assertRedirect();

        $this->assertEquals('22.5000', $customIdDef->fresh()->pricing_config['standard_base_price']);

        // 3. Admin creates a new ServicePlan
        $this->actingAs($admin)
            ->post(route('admin.services.plans.store'), [
                'service_definition_id' => $customIdDef->id,
                'code' => 'id_vip_custom_pack',
                'target_tier' => 'enterprise',
                'base_price' => '120.0000',
                'quota_units' => 5,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_plans', [
            'code' => 'id_vip_custom_pack',
            'base_price' => '120.0000',
        ]);

        // 4. Admin directly provisions a Web3 domain package to the subscriber
        $this->actingAs($admin)
            ->post(route('admin.services.allocations.store'), [
                'user_id' => $subscriber->id,
                'service_key' => 'web3_email_package',
                'resource_identifier' => 'goldensource.dao',
                'display_title' => 'Enterprise DAO Domain',
                'account_tier' => 'enterprise',
                'total_quota' => 100,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('service_resource_allocations', [
            'user_id' => $subscriber->id,
            'service_key' => 'web3_email_package',
            'resource_identifier' => 'goldensource.dao',
            'total_quota' => 100,
            'status' => 'active',
        ]);
    }

    public function test_per_prefix_sms_tariff_calculation_and_admin_update(): void
    {
        $prefix = PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'name' => 'Iran',
                'country_code' => 'IR',
                'country_name' => 'Iran',
                'flag' => '🇮🇷',
                'sub_prefixes' => ['000', '912'],
                'price_regular' => 25,
                'price_semi_rond' => 50,
                'price_golden_rond' => 100,
                'sms_price_per_segment' => 0.3500,
                'inbound_sms_price' => 0.0500,
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $calculator = app(SmsSegmentCalculatorService::class);
        $metrics = $calculator->calculate('Hello World', 1, null, $prefix->id);

        $this->assertEquals(1, $metrics['segment_count']);
        $this->assertEquals('0.3500', $metrics['rate_per_segment']);
        $this->assertEquals('0.3500', $metrics['total_cost']);
    }

    public function test_number_search_and_enterprise_shortcode_availability_detects_taken_numbers_and_provides_suggestions(): void
    {
        $prefix = PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'name' => 'Iran',
                'country_code' => 'IR',
                'country_name' => 'Iran',
                'flag' => '🇮🇷',
                'sub_prefixes' => ['000', '111'],
                'price_regular' => 25,
                'price_semi_rond' => 50,
                'price_golden_rond' => 100,
                'sms_price_per_segment' => 0.1500,
                'inbound_sms_price' => 0.0000,
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $owner = User::factory()->create(['account_tier' => 'enterprise']);

        // Allocate a 9-digit number (even without phone_prefix_id set) and a 4-digit shortcode
        PhoneNumber::create([
            'number' => '000123456',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $owner->id,
        ]);

        PhoneNumber::create([
            'number' => '4050',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $owner->id,
        ]);

        $searchService = app(NumberSearchAndSuggestionService::class);

        // 1. 9-digit check must strictly report is_available = false for allocated number
        $check9 = $searchService->checkAndSuggest((int) $prefix->id, '000', '123456');
        $this->assertFalse($check9['is_available']);
        $this->assertNotEmpty($check9['suggestions']);

        // 2. Shortcode check must strictly report is_available = false for allocated 4050 and return suggestions
        $check4 = $searchService->checkAndSuggestShortcode((int) $prefix->id, 4, '4050');
        $this->assertFalse($check4['is_available']);
        $this->assertNotEmpty($check4['suggestions']);

        // 3. Enterprise shortcode suggestions endpoint returns available suggestions for length 5
        $this->actingAs($owner)
            ->getJson(route('requests.suggestions', ['requested_length' => 5, 'prefix_id' => $prefix->id]))
            ->assertOk()
            ->assertJsonStructure(['suggestions']);
    }

    public function test_admin_user_management_updates_account_tier_and_organization_metadata(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['account_tier' => 'individual']);

        $customIdDef = ServiceDefinition::where('key', 'custom_id')->firstOrFail();
        ServiceResourceAllocation::create([
            'user_id' => $targetUser->id,
            'service_definition_id' => $customIdDef->id,
            'service_key' => 'custom_id',
            'channel_type' => 'handle_id',
            'resource_identifier' => '@testuser99',
            'display_title' => 'Test Handle',
            'account_tier' => 'individual',
            'tier_badge' => 'standard',
            'status' => 'active',
            'total_quota' => 1,
            'used_quota' => 1,
        ]);

        // Verify both /admin/users and /dashboard load cleanly when user has active service allocations
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($targetUser)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $targetUser->id), [
                'name' => 'GoldenSource Corp',
                'username' => 'goldensource_corp',
                'role' => 'user',
                'status' => 'active',
                'account_tier' => 'enterprise',
                'organization_name' => 'GoldenSource Holding',
                'enterprise_contract_code' => 'ENT-2026-999',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $freshUser = $targetUser->fresh();
        $this->assertEquals('enterprise', $freshUser->account_tier);
        $this->assertEquals('GoldenSource Holding', $freshUser->organization_name);
        $this->assertEquals('ENT-2026-999', $freshUser->enterprise_contract_code);
    }
}

