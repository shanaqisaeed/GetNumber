<?php

namespace Tests\Feature\Dashboard;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PhonePrefix::firstOrCreate(
            ['prefix' => '85'],
            [
                'name' => 'پیشوند سازمانی اصلی',
                'is_active' => true,
                'is_default' => true,
            ]
        );
    }

    public function test_guest_is_redirected_to_login_when_accessing_dashboard(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_user_can_access_dashboard_with_all_mobile_metrics(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $prefix = PhonePrefix::getDefaultPrefix();

        // Create 2 allocated lines for this user
        $p1 = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '85901111111',
            'type' => \App\Enums\PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now()->subDay(),
            'display_title' => 'خط فروش',
        ]);

        $p2 = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '85902222222',
            'type' => \App\Enums\PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
            'display_title' => 'سرشماره سازمانی',
        ]);

        // Create 3 messages for line 1: 2 unread inbound, 1 processed
        Message::create([
            'phone_number_id' => $p1->id,
            'destination_number' => $p1->number,
            'sender' => '9120000001',
            'body' => 'کد تایید ورود: ۱۲۳۴۵۶',
            'processing_status' => MessageProcessingStatus::Received,
            'direction' => MessageDirection::Inbound,
            'server_received_at' => now()->subMinutes(10),
        ]);

        Message::create([
            'phone_number_id' => $p1->id,
            'destination_number' => $p1->number,
            'sender' => '9120000002',
            'body' => 'پیام دوم خوانده نشده',
            'processing_status' => MessageProcessingStatus::Received,
            'direction' => MessageDirection::Inbound,
            'server_received_at' => now()->subMinutes(5),
        ]);

        Message::create([
            'phone_number_id' => $p1->id,
            'destination_number' => $p1->number,
            'sender' => '9120000003',
            'body' => 'پیام پردازش شده قدیمی',
            'processing_status' => MessageProcessingStatus::Processed,
            'direction' => MessageDirection::Inbound,
            'server_received_at' => now()->subHours(2),
        ]);

        // Create a pending request
        NumberRequest::create([
            'user_id' => $user->id,
            'service_title' => 'واحد فروش',
            'description' => 'درخواست سرشماره اختصاصی برای مرکز تماس',
            'requested_length' => 5,
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('quota', fn (Assert $quota) => $quota
                ->where('active', 2)
                ->where('max', 10)
                ->where('remaining', 8)
                ->where('percentage', 20)
            )
            ->has('stats', fn (Assert $stats) => $stats
                ->where('active_numbers', 2)
                ->where('total_messages', 3)
                ->where('unread_messages', 2)
                ->where('pending_requests', 1)
            )
            ->has('recent_numbers', 2)
            ->has('unread_messages', 2)
            ->where('default_prefix', '+85')
        );
    }

    public function test_dashboard_quota_reflects_full_capacity_correctly(): void
    {
        $user = User::factory()->create();
        $prefix = PhonePrefix::getDefaultPrefix();

        // Allocate 10 numbers to user
        for ($i = 0; $i < 10; $i++) {
            PhoneNumber::create([
                'phone_prefix_id' => $prefix->id,
                'number' => '8590' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'type' => \App\Enums\PhoneNumberType::UserStandard,
                'status' => PhoneNumberStatus::Allocated,
                'user_id' => $user->id,
                'allocated_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('quota', fn (Assert $quota) => $quota
                ->where('active', 10)
                ->where('max', 10)
                ->where('remaining', 0)
                ->where('percentage', 100)
            )
        );
    }
}
