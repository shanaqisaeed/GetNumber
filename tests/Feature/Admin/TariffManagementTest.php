<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use App\Services\Communication\SmsService;
use App\Services\Communication\VoiceCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $user;
    protected PhonePrefix $prefix85;
    protected PhonePrefix $prefix98;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->prefix85 = PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'name' => 'پیش‌شماره ۸۵',
                'country_name' => 'اختصاصی',
                'is_active' => true,
                'is_default' => true,
                'sms_price_per_segment' => 0.05,
                'sms_intra_prefix_rate' => 0.02,
                'sms_inter_prefix_rate' => 0.06,
                'voice_intra_prefix_rate' => 0.10,
                'voice_inter_prefix_rate' => 0.25,
            ]
        );

        $this->prefix98 = PhonePrefix::updateOrCreate(
            ['prefix' => '+98'],
            [
                'name' => 'پیش‌شماره ۹۸',
                'country_name' => 'ایران',
                'is_active' => true,
                'is_default' => false,
                'sms_price_per_segment' => 0.08,
                'sms_intra_prefix_rate' => 0.04,
                'sms_inter_prefix_rate' => 0.09,
                'voice_intra_prefix_rate' => 0.15,
                'voice_inter_prefix_rate' => 0.35,
            ]
        );
    }

    public function test_guest_and_user_cannot_access_tariffs(): void
    {
        $this->get(route('admin.tariffs.index'))->assertRedirect('/login');
        $this->actingAs($this->user)->get(route('admin.tariffs.index'))->assertStatus(403);
    }

    public function test_admin_can_view_tariffs_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.tariffs.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Tariffs/Index')
            ->has('prefixes')
            ->has('global_settings')
        );
    }

    public function test_admin_can_update_prefix_tariffs(): void
    {
        $response = $this->actingAs($this->admin)->patch(
            route('admin.tariffs.prefixes.update', $this->prefix85->id),
            [
                'sms_intra_prefix_rate' => 0.015,
                'sms_inter_prefix_rate' => 0.055,
                'voice_intra_prefix_rate' => 0.12,
                'voice_inter_prefix_rate' => 0.28,
            ]
        );

        $response->assertRedirect();
        $this->prefix85->refresh();

        $this->assertEquals(0.015, (float) $this->prefix85->sms_intra_prefix_rate);
        $this->assertEquals(0.055, (float) $this->prefix85->sms_inter_prefix_rate);
        $this->assertEquals(0.12, (float) $this->prefix85->voice_intra_prefix_rate);
        $this->assertEquals(0.28, (float) $this->prefix85->voice_inter_prefix_rate);
    }

    public function test_admin_can_update_global_tariffs(): void
    {
        $response = $this->actingAs($this->admin)->post(
            route('admin.tariffs.global.update'),
            [
                'id_sms_rate' => 0.01,
                'id_voice_rate_per_minute' => 0.05,
            ]
        );

        $response->assertRedirect();
    }

    public function test_sms_service_calculates_intra_and_inter_prefix_tariffs_accurately(): void
    {
        $smsService = app(SmsService::class);

        $line85 = PhoneNumber::factory()->create([
            'user_id' => $this->user->id,
            'phone_prefix_id' => $this->prefix85->id,
            'number' => '851234567',
        ]);

        // Destination with same prefix +85 (Intra-prefix)
        $resultIntra = $smsService->calculateCost('Hello', $line85, 1, '859999999');
        $this->assertEquals(0.02, $resultIntra['tariff_per_part']);

        // Destination with different prefix (Inter-prefix)
        $resultInter = $smsService->calculateCost('Hello', $line85, 1, '+989121111111');
        $this->assertEquals(0.06, $resultInter['tariff_per_part']);

        // Destination is custom ID (@brand)
        $resultHandle = $smsService->calculateCost('Hello', $line85, 1, '@mybrand');
        $this->assertGreaterThan(0, $resultHandle['tariff_per_part']);
    }
}
