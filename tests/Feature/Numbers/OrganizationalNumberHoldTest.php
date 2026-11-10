<?php

namespace Tests\Feature\Numbers;

use App\Contracts\WalletServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationalNumberHoldTest extends TestCase
{
    use RefreshDatabase;

    private WalletServiceInterface $walletService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletService = app(WalletServiceInterface::class);

        // Ensure default pricing is present in test DB
        SystemSetting::set('org_price_5_regular', '450', 'decimal');
        SystemSetting::set('org_price_5_rond', '1200', 'decimal');
    }

    private function getUserBalance(User $user): float
    {
        return (float) $this->walletService->getWallet($user)->fresh()->balance;
    }

    public function test_user_with_insufficient_funds_cannot_submit_request(): void
    {
        $user = User::factory()->create(); // 0 balance

        $response = $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'خط سازمان توسعه تجارت',
            'description' => 'ارسال پیامک‌های انبوه تراکنشی',
            'preferred_number' => '83192', // regular 5-digit
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('number_requests', [
            'user_id' => $user->id,
            'service_title' => 'خط سازمان توسعه تجارت',
        ]);
    }

    public function test_user_with_sufficient_balance_has_funds_held_on_regular_number_request(): void
    {
        $user = User::factory()->create();
        // Fund user wallet with 500 TRX
        $this->walletService->deposit($user, '500.00000000', 'TEST-DEP-1');

        $this->assertEquals(500.0, $this->getUserBalance($user));

        $response = $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'خط سازمان فناوری',
            'description' => 'ارسال پیامک‌های تراکنشی سیستم احراز',
            'preferred_number' => '83192', // regular, price = 450
        ]);

        $response->assertRedirect('/requests');

        $request = NumberRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertEquals(450.0, (float) $request->held_amount);
        $this->assertFalse((bool) $request->is_rond);
        $this->assertNotNull($request->hold_reference_id);

        // Remaining balance in user wallet must be 50 TRX (500 - 450)
        $this->assertEquals(50.0, $this->getUserBalance($user));
    }

    public function test_rond_number_request_requires_and_holds_higher_rond_tariff(): void
    {
        $user = User::factory()->create();
        // Fund user wallet with 1500 TRX
        $this->walletService->deposit($user, '1500.00000000', 'TEST-DEP-ROND');

        $response = $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'خط طلایی هلدینگ',
            'description' => 'ارسال پیامک‌های تراکنشی سریع و اختصاصی',
            'preferred_number' => '54321', // sequential descending rond, price = 1200
        ]);

        $response->assertRedirect('/requests');

        $request = NumberRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertTrue((bool) $request->is_rond);
        $this->assertEquals(1200.0, (float) $request->held_amount);

        // Remaining balance: 1500 - 1200 = 300 TRX
        $this->assertEquals(300.0, $this->getUserBalance($user));
    }

    public function test_admin_approval_finalizes_hold_and_allocates_line(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->walletService->deposit($user, '500.00000000', 'TEST-DEP-2');

        $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'مرکز تماس مشتریان',
            'description' => 'مرکز پاسخگویی یکپارچه مشتریان',
            'preferred_number' => '83192',
        ]);

        $request = NumberRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertEquals(NumberRequestStatus::Pending, $request->status);

        // Admin approves request
        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/approve", [
            'mode' => 'custom',
            'custom_number' => '83192',
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertEquals(NumberRequestStatus::Approved, $freshReq->status);
        $this->assertNotNull($freshReq->allocated_number_id);

        $allocatedPhone = PhoneNumber::find($freshReq->allocated_number_id);
        $this->assertNotNull($allocatedPhone);
        $this->assertEquals('83192', $allocatedPhone->number);
        $this->assertEquals($user->id, $allocatedPhone->user_id);

        // User balance remains 50 TRX because funds were finalized to revenue
        $this->assertEquals(50.0, $this->getUserBalance($user));
    }

    public function test_admin_rejection_releases_hold_and_refunds_user_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->walletService->deposit($user, '500.00000000', 'TEST-DEP-3');

        $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'سامانه رد شونده',
            'description' => 'تست فرآیند استرداد وجه مسدود شده',
            'preferred_number' => '83192',
        ]);

        $request = NumberRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertEquals(50.0, $this->getUserBalance($user));

        // Admin rejects request
        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/reject", [
            'reason' => 'سرشماره انتخابی در شبکه مخابراتی مسدود است.',
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertEquals(NumberRequestStatus::Rejected, $freshReq->status);

        // User balance must be restored to 500 TRX immediately
        $this->assertEquals(500.0, $this->getUserBalance($user));
    }

    public function test_user_cancellation_releases_hold_and_refunds_user(): void
    {
        $user = User::factory()->create();
        $this->walletService->deposit($user, '600.00000000', 'TEST-DEP-4');

        $this->from('/requests')->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'درخواست لغو شونده',
            'description' => 'شرح درخواست جهت تست لغو توسط کاربر',
            'preferred_number' => '83192',
        ]);

        $request = NumberRequest::where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertEquals(150.0, $this->getUserBalance($user));

        // User cancels pending request
        $response = $this->from('/requests')->actingAs($user)->delete("/requests/{$request->id}");
        $response->assertRedirect('/requests');

        // Request record was cancelled / deleted, and balance fully restored to 600 TRX
        $this->assertEquals(600.0, $this->getUserBalance($user));
    }
}
