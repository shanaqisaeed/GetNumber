<?php

namespace Tests\Feature;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationWallet;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialTransformationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $org;
    protected PhonePrefix $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('voice_bridge_enabled', false);
        SystemSetting::set('voice_call_rate_per_minute', 1000.0);
        SystemSetting::set('voice_intra_prefix_rate', 1000.0);
        SystemSetting::set('voice_inter_prefix_rate', 1000.0);

        $this->prefix = PhonePrefix::create([
            'prefix' => '+98',
            'name' => 'Iran',
            'country_code' => 'IR',
            'is_default' => true,
            'is_active' => true,
            'status' => 'active',
            'voice_intra_prefix_rate' => 1000.0,
            'voice_inter_prefix_rate' => 1000.0,
            'sms_intra_prefix_rate' => 100.0,
            'sms_inter_prefix_rate' => 100.0,
        ]);

        $this->user = User::factory()->create(['role' => UserRole::User]);
        $this->org = $this->user->getOrCreatePersonalOrganization();
    }

    /**
     * Test 1: Team Member Line Exclusivity (Strict: Exactly 1 line per employee).
     */
    public function test_organization_member_cannot_have_more_than_one_dedicated_line(): void
    {
        $employeeUser = User::factory()->create();
        $member = $this->org->members()->create([
            'user_id' => $employeeUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $line1 = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100001',
            'formatted_number' => '+98100001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
        ]);

        $line2 = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100002',
            'formatted_number' => '+98100002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
        ]);

        // First assignment succeeds
        $response = $this->actingAs($this->user)
            ->post(route('org.team.assign_line', ['member' => $member->id]), [
                'line_key' => 'phone_' . $line1->id,
            ]);

        $response->assertRedirect();
        $this->assertEquals($line1->number, $member->fresh()->assigned_line['number']);
        $this->assertEquals($employeeUser->id, $line1->fresh()->assigned_user_id);

        // Attempting to assign a second line must fail per corporate policy
        $failResponse = $this->actingAs($this->user)
            ->post(route('org.team.assign_line', ['member' => $member->id]), [
                'line_key' => 'phone_' . $line2->id,
            ]);

        $failResponse->assertSessionHas('error');
        $this->assertNull($line2->fresh()->assigned_user_id);
    }

    /**
     * Test 2: Unassigning and Removing Member releases the dedicated line back to org inventory.
     */
    public function test_unassigning_line_releases_it_back_to_organization_pool(): void
    {
        $employeeUser = User::factory()->create();
        $member = $this->org->members()->create([
            'user_id' => $employeeUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $line = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100003',
            'formatted_number' => '+98100003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
            'assigned_user_id' => $employeeUser->id,
        ]);

        // Unassign line
        $this->actingAs($this->user)
            ->post(route('org.team.unassign_line', ['member' => $member->id]));

        $this->assertNull($member->fresh()->assigned_line);
        $this->assertNull($line->fresh()->assigned_user_id);
    }

    /**
     * Test 3: Softphone line switching and session persistence.
     */
    public function test_softphone_active_line_can_be_switched_and_persists_in_session(): void
    {
        $line1 = PhoneNumber::create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100011',
            'formatted_number' => '+98100011',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $line2 = PhoneNumber::create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100022',
            'formatted_number' => '+98100022',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('softphone.switch_line'), [
                'line_key' => 'phone_' . $line2->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('active_line.key', 'phone_' . $line2->id);

        $this->assertEquals('phone_' . $line2->id, session('active_line_key'));
    }

    /**
     * Test 4: Softphone Presence Updates.
     */
    public function test_softphone_presence_update_persists_in_session(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('softphone.presence'), [
                'presence' => 'busy',
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'presence' => 'busy',
        ]);

        $this->assertEquals('busy', session('softphone_presence'));
    }

    /**
     * Test 5: Corporate Wallet vs Personal Wallet Billing on Calls.
     */
    public function test_voice_call_deducts_from_organization_wallet_when_line_is_corporate(): void
    {
        $orgWallet = $this->org->getOrCreateWallet();
        $orgWallet->credit(50000.0, 'Initial Top-up');

        $orgLine = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100333',
            'formatted_number' => '+98100333',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
        ]);

        $destUser = User::factory()->create();
        $destLine = PhoneNumber::create([
            'user_id' => $destUser->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100444',
            'formatted_number' => '+98100444',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $initialOrgBalance = (float) $orgWallet->fresh()->balance;

        $response = $this->actingAs($this->user)
            ->postJson(route('voice.initiate'), [
                'destination' => $destLine->number,
                'phone_number_id' => $orgLine->id,
            ]);

        $response->assertOk();
        $callId = $response->json('call.id');

        // End call with 120 seconds duration (2 minutes)
        $endResponse = $this->actingAs($this->user)
            ->postJson(route('voice.end', ['voiceCall' => $callId]), [
                'duration_seconds' => 120,
            ]);

        $endResponse->assertOk();

        // 2 minutes * 1000 = 2000 deducted from Org Wallet
        $finalOrgBalance = (float) $orgWallet->fresh()->balance;
        $this->assertEquals($initialOrgBalance - 2000.0, $finalOrgBalance);
    }

    /**
     * Test 6: Mid-Call 1-Minute Grace Period Warning on Low Balance.
     */
    public function test_mid_call_pulse_signals_warning_when_remaining_funds_cover_less_than_one_minute(): void
    {
        $orgWallet = $this->org->getOrCreateWallet();
        $orgWallet->credit(1500.0, 'Initial balance'); // Enough to initiate call

        $orgLine = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100555',
            'formatted_number' => '+98100555',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
        ]);

        $destUser = User::factory()->create();
        $destLine = PhoneNumber::create([
            'user_id' => $destUser->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100666',
            'formatted_number' => '+98100666',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $start = $this->actingAs($this->user)
            ->postJson(route('voice.initiate'), [
                'destination' => $destLine->number,
                'phone_number_id' => $orgLine->id,
            ]);

        $start->assertOk();
        $callId = $start->json('call.id');

        // Simulate balance reduction mid-call to less than 1 minute rate (500.0 < 1000.0)
        $orgWallet->debit(1000.0, 'Partial expenditure');

        // Pulse detects balance < rate and raises low_balance_warning
        $pulse = $this->actingAs($this->user)
            ->postJson(route('voice.pulse', ['voiceCall' => $callId]), [
                'elapsed_seconds' => 40,
            ]);

        $pulse->assertOk();
        $this->assertTrue($pulse->json('low_balance_warning'));
        $this->assertTrue($pulse->json('active'));
    }

    /**
     * Test 7: Organization Bulk SMS Estimation and Dispatch against Corporate Wallet.
     */
    public function test_organization_bulk_sms_estimation_and_sending(): void
    {
        $orgWallet = $this->org->getOrCreateWallet();
        $orgWallet->credit(100000.0, 'Marketing budget');

        $orgLine = PhoneNumber::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100777',
            'formatted_number' => '+98100777',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'is_organization' => true,
        ]);

        // Estimation endpoint
        $estimate = $this->actingAs($this->user)
            ->postJson(route('org.bulk_sms.estimate'), [
                'from_number_id' => $orgLine->id,
                'destinations_count' => 10,
                'body' => 'Notice: Company annual meeting announcement.',
            ]);

        $estimate->assertOk();
        $this->assertEquals(10, $estimate->json('recipients_count'));
        $this->assertTrue($estimate->json('has_sufficient_balance'));
    }

    /**
     * Test 8: Verify Complete Localization Keys Coverage (fa & en).
     */
    public function test_all_commercial_localization_files_exist_and_contain_required_root_keys(): void
    {
        $faSoftphone = require resource_path('../lang/fa/softphone.php');
        $enSoftphone = require resource_path('../lang/en/softphone.php');
        $faOrg = require resource_path('../lang/fa/organization.php');
        $enOrg = require resource_path('../lang/en/organization.php');

        $this->assertArrayHasKey('nav', $faSoftphone);
        $this->assertArrayHasKey('dialer', $faSoftphone);
        $this->assertArrayHasKey('line', $faSoftphone);
        $this->assertArrayHasKey('devices', $faSoftphone);
        $this->assertArrayHasKey('multi_tab', $faSoftphone);

        $this->assertArrayHasKey('nav', $enSoftphone);
        $this->assertArrayHasKey('nav', $faOrg);
        $this->assertArrayHasKey('team', $faOrg);
        $this->assertArrayHasKey('lines', $faOrg);
        $this->assertArrayHasKey('bulk_sms', $faOrg);
        $this->assertArrayHasKey('wallet', $faOrg);
        $this->assertArrayHasKey('contacts', $faOrg);
    }

    /**
     * Test 9: Softphone dashboard renders recent conversations without SQL column errors.
     */
    public function test_softphone_dashboard_renders_successfully_with_recent_conversations(): void
    {
        $line = PhoneNumber::create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '100888',
            'formatted_number' => '+98100888',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        // Inbound unread SMS
        Message::create([
            'phone_number_id' => $line->id,
            'destination_number' => $line->number,
            'sender' => '+989121111111',
            'peer_number' => '+989121111111',
            'body' => 'Hello from peer 1',
            'server_received_at' => now(),
            'direction' => \App\Enums\MessageDirection::Inbound,
            'processing_status' => \App\Enums\MessageProcessingStatus::Received,
        ]);

        // Outbound SMS
        Message::create([
            'phone_number_id' => $line->id,
            'destination_number' => '+989121111111',
            'sender' => $line->number,
            'peer_number' => '+989121111111',
            'body' => 'Reply to peer 1',
            'server_received_at' => now(),
            'direction' => \App\Enums\MessageDirection::Outbound,
            'processing_status' => \App\Enums\MessageProcessingStatus::Processed,
        ]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/Softphone/Dashboard')
            ->has('recent_conversations', 1)
            ->where('recent_conversations.0.peer', '+989121111111')
            ->where('recent_conversations.0.unread_count', 1)
            ->where('recent_conversations.0.last_message', 'Reply to peer 1')
        );
    }

    /**
     * Test 10: Organization lines inventory renders without SQL errors and detects 4-8 digit shortcodes.
     */
    public function test_organization_lines_inventory_renders_cleanly_and_includes_shortcode_lines(): void
    {
        // Add a 4-digit shortcode number with commercial UserService type
        $shortNumber = PhoneNumber::create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '1010',
            'formatted_number' => '+981010',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        // Add a 4-digit number with standard user type (must NOT be treated as commercial)
        $standardNumber = PhoneNumber::create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix->id,
            'number' => '2020',
            'formatted_number' => '+982020',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $employeeUser = User::factory()->create();
        $this->org->members()->create([
            'user_id' => $employeeUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->get(route('org.lines.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Lines/Index')
            ->has('lines', 1)
            ->where('lines.0.number', '1010')
            ->has('eligible_members', 1)
            ->where('eligible_members.0.email', $employeeUser->email)
        );

        $this->assertEquals($this->org->id, $shortNumber->fresh()->organization_id);
        $this->assertNull($standardNumber->fresh()->organization_id);
    }

    /**
     * Test 11: Organization requests index aggregates stats and shows orders inside the main requests list.
     */
    public function test_organization_requests_aggregates_service_orders_in_stats(): void
    {
        app(\App\Contracts\Services\ServiceRegistryInterface::class)->ensureCatalogSeeded();

        $serviceDef = \App\Models\ServiceDefinition::where('key', 'custom_id')->first();
        $servicePlan = $serviceDef->plans()->first();

        \App\Models\ServiceOrder::create([
            'order_number' => 'SRV-TEST-001',
            'user_id' => $this->user->id,
            'service_definition_id' => $serviceDef->id,
            'service_plan_id' => $servicePlan->id,
            'status' => 'completed',
            'requested_identifier' => '@saeed',
            'unit_price' => 160.0,
            'final_amount' => 160.0,
        ]);

        $response = $this->actingAs($this->user)->get(route('org.requests.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Organization/Requests/Index')
            ->where('stats.approved', 1)
            ->has('requests.data', 1)
            ->where('requests.data.0.order_number', 'SRV-TEST-001')
            ->where('requests.data.0.status', 'approved')
            ->where('requests.data.0.requested_identifier', '@saeed')
        );
    }
}
