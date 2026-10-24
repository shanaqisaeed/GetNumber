<?php

namespace Tests\Feature\Admin;

use App\Contracts\ApiTokenServiceInterface;
use App\Contracts\NumberRequestServiceInterface;
use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->regularUser = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_admin_can_access_dashboard_with_all_metrics(): void
    {
        // Create numbers, requests, tokens to populate stats
        PhoneNumber::create([
            'number' => '000123456',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->regularUser->id,
        ]);

        NumberRequest::create([
            'user_id' => $this->regularUser->id,
            'requested_length' => 6,
            'service_title' => 'پشتیبانی مرکز',
            'description' => 'توضیحات تست درخواست جهت بررسی',
            'status' => NumberRequestStatus::Pending,
        ]);

        AuditLog::record('test_action', null, $this->admin, ['foo' => 'bar']);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->has('stats.users')
            ->has('stats.numbers')
            ->has('stats.messages')
            ->has('stats.requests')
            ->has('stats.tokens')
            ->has('recentUsers')
            ->has('pendingRequests', 1)
            ->has('recentAudits', 1)
            ->has('systemHealth')
        );
    }

    public function test_non_admin_cannot_access_admin_dashboard(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('admin.dashboard'));
        $response->assertForbidden();
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_admin_can_view_audit_logs_page(): void
    {
        AuditLog::record('user_created', $this->regularUser, $this->admin, [
            'username' => $this->regularUser->username,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.audit-logs.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('logs.data', 1)
            ->has('stats')
            ->has('actionOptions')
            ->where('logs.data.0.action', 'user_created')
            ->where('logs.data.0.action_label', 'ایجاد حساب کاربری')
        );
    }

    public function test_non_admin_cannot_access_audit_logs(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('admin.audit-logs.index'));
        $response->assertForbidden();
    }

    public function test_audit_logs_can_be_filtered_by_action_and_user(): void
    {
        $otherUser = User::factory()->create(['role' => UserRole::User]);

        AuditLog::record('number_assigned', null, $this->admin, ['number' => '12345']);
        AuditLog::record('token_created', null, $this->admin, ['token' => 'abc']);
        AuditLog::record('user_status_changed', null, $otherUser, ['user' => 'xyz']);

        // Filter by action prefix number_*
        $response = $this->actingAs($this->admin)->get(route('admin.audit-logs.index', [
            'action' => 'number_*',
        ]));
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'number_assigned')
        );

        // Filter by user_id
        $responseUser = $this->actingAs($this->admin)->get(route('admin.audit-logs.index', [
            'user_id' => $otherUser->id,
        ]));
        $responseUser->assertOk();
        $responseUser->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'user_status_changed')
        );
    }

    public function test_audit_log_show_endpoint_returns_json_details(): void
    {
        $log = AuditLog::record('token_rotated', null, $this->admin, [
            'token_name' => 'سیستم حسابداری',
            'rotated_at' => now()->toIso8601String(),
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('admin.audit-logs.show', $log));

        $response->assertOk();
        $response->assertJson([
            'id' => $log->id,
            'action' => 'token_rotated',
            'action_label' => 'تعویض کلید وب‌سرویس',
            'payload' => [
                'token_name' => 'سیستم حسابداری',
            ],
        ]);
    }

    public function test_user_management_actions_record_audit_logs(): void
    {
        // 1. Create User
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'کاربر جدید سازمانی',
            'username' => 'new_corp_user',
            'password' => 'Password123!',
            'role' => UserRole::User->value,
            'status' => UserStatus::Active->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_created',
            'user_id' => $this->admin->id,
        ]);

        $targetUser = User::where('username', 'new_corp_user')->firstOrFail();

        // 2. Suspend User
        $this->actingAs($this->admin)->patch(route('admin.users.status', $targetUser), [
            'status' => UserStatus::Suspended->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_status_changed',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_api_token_admin_actions_record_audit_logs(): void
    {
        $tokenService = app(ApiTokenServiceInterface::class);
        $result = $tokenService->createToken($this->regularUser, 'کلید اولیه');
        $token = $result['token'];

        // Rotate
        $this->actingAs($this->admin)->post(route('admin.api-tokens.rotate', $token));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'token_rotated',
            'user_id' => $this->admin->id,
        ]);

        // Revoke
        $this->actingAs($this->admin)->post(route('admin.api-tokens.revoke', $token));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'token_revoked',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_number_request_rejection_records_audit_log(): void
    {
        $request = NumberRequest::create([
            'user_id' => $this->regularUser->id,
            'requested_length' => 5,
            'service_title' => 'خط ارتباطی ویژه',
            'description' => 'توضیحات تست درخواست جهت رد',
            'status' => NumberRequestStatus::Pending,
        ]);

        $this->actingAs($this->admin)->post(route('admin.requests.reject', $request), [
            'reason' => 'سرشماره مدنظر رزرو سازمانی است',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request_rejected',
            'user_id' => $this->admin->id,
        ]);
    }
}
