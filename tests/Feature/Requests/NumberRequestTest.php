<?php

namespace Tests\Feature\Requests;

use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_user_requests(): void
    {
        $response = $this->get('/requests');
        $response->assertRedirect('/login');
    }

    public function test_user_can_view_their_requests_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/requests');
        $response->assertStatus(200);
    }

    public function test_user_can_submit_valid_number_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/requests', [
            'requested_length' => 5,
            'service_title' => 'سامانه اطلاع‌رسانی پیامکی',
            'description' => 'ارسال هشدارهای آنی به مشترکین پلتفرم در زمان ورود به حساب کاربری',
            'preferred_number' => '50000',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('number_requests', [
            'user_id' => $user->id,
            'requested_length' => 5,
            'service_title' => 'سامانه اطلاع‌رسانی پیامکی',
            'preferred_number' => '50000',
            'status' => NumberRequestStatus::Pending->value,
        ]);
    }

    public function test_number_request_fails_if_length_is_outside_4_to_8_range(): void
    {
        $user = User::factory()->create();

        // 3 digits
        $response1 = $this->actingAs($user)->post('/requests', [
            'requested_length' => 3,
            'service_title' => 'تست نامعتبر',
            'description' => 'شرح کاربرد تستی',
        ]);
        $response1->assertSessionHasErrors('requested_length');

        // 9 digits
        $response2 = $this->actingAs($user)->post('/requests', [
            'requested_length' => 9,
            'service_title' => 'تست نامعتبر ۲',
            'description' => 'شرح کاربرد تستی ۲',
        ]);
        $response2->assertSessionHasErrors('requested_length');
    }

    public function test_number_request_validates_preferred_number_length_matches_requested_length(): void
    {
        $user = User::factory()->create();

        // Requesting 6 digits, but providing 4 digits in preferred
        $response = $this->actingAs($user)->post('/requests', [
            'requested_length' => 6,
            'service_title' => 'سرویس تست عدم تطابق',
            'description' => 'توضیحات تست عدم تطابق',
            'preferred_number' => '4000',
        ]);

        $response->assertSessionHasErrors('preferred_number');
    }

    public function test_user_can_cancel_their_own_pending_request(): void
    {
        $user = User::factory()->create();
        $request = NumberRequest::factory()->create([
            'user_id' => $user->id,
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($user)->delete("/requests/{$request->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('number_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_user_cannot_cancel_another_users_request(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $request = NumberRequest::factory()->create([
            'user_id' => $user1->id,
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($user2)->delete("/requests/{$request->id}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('number_requests', ['id' => $request->id]);
    }

    public function test_user_cannot_cancel_approved_request(): void
    {
        $user = User::factory()->create();
        $request = NumberRequest::factory()->approved()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete("/requests/{$request->id}");
        $response->assertStatus(403);
    }

    public function test_regular_user_cannot_access_admin_requests(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $response = $this->actingAs($user)->get('/admin/requests');
        $response->assertStatus(403);
    }

    public function test_admin_can_view_all_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        NumberRequest::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($admin)->get('/admin/requests');
        $response->assertStatus(200);
    }

    public function test_admin_can_approve_request_with_auto_allocation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $request = NumberRequest::factory()->create([
            'user_id' => $user->id,
            'requested_length' => 4,
            'service_title' => 'سرشماره پشتیبانی',
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/approve", [
            'mode' => 'auto',
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertSame(NumberRequestStatus::Approved, $freshReq->status);
        $this->assertNotNull($freshReq->allocated_number_id);
        $this->assertSame($admin->id, $freshReq->reviewed_by);
        $this->assertNotNull($freshReq->reviewed_at);

        $allocatedPhone = PhoneNumber::find($freshReq->allocated_number_id);
        $this->assertNotNull($allocatedPhone);
        $this->assertSame(4, strlen($allocatedPhone->number));
        $this->assertSame($user->id, $allocatedPhone->user_id);
        $this->assertSame(PhoneNumberType::UserService, $allocatedPhone->type);
        $this->assertSame(PhoneNumberStatus::Allocated, $allocatedPhone->status);
        $this->assertSame('سرشماره پشتیبانی', $allocatedPhone->display_title);
    }

    public function test_admin_can_approve_request_with_custom_number(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $request = NumberRequest::factory()->create([
            'user_id' => $user->id,
            'requested_length' => 5,
            'service_title' => 'سرویس پیامکی اختصاصی',
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/approve", [
            'mode' => 'custom',
            'custom_number' => '55555',
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertSame(NumberRequestStatus::Approved, $freshReq->status);
        $allocatedPhone = PhoneNumber::find($freshReq->allocated_number_id);
        $this->assertSame('55555', $allocatedPhone->number);
        $this->assertSame($user->id, $allocatedPhone->user_id);
        $this->assertSame(PhoneNumberType::UserService, $allocatedPhone->type);
    }

    public function test_admin_can_approve_request_with_pool_number(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $poolNumber = PhoneNumber::create([
            'number' => '7777',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Available,
        ]);

        $request = NumberRequest::factory()->create([
            'user_id' => $user->id,
            'requested_length' => 4,
            'service_title' => 'سرویس اداری',
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/approve", [
            'mode' => 'pool',
            'pool_number_id' => $poolNumber->id,
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertSame($poolNumber->id, $freshReq->allocated_number_id);
        $this->assertSame(PhoneNumberStatus::Allocated, $poolNumber->fresh()->status);
        $this->assertSame($user->id, $poolNumber->fresh()->user_id);
    }

    public function test_admin_can_reject_request_with_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $request = NumberRequest::factory()->create([
            'user_id' => $user->id,
            'service_title' => 'درخواست تستی رد شونده',
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/reject", [
            'reason' => 'عدم تطابق با شرایط ارائه سرشماره ۴ رقمی',
        ]);

        $response->assertRedirect();
        $freshReq = $request->fresh();

        $this->assertSame(NumberRequestStatus::Rejected, $freshReq->status);
        $this->assertSame('عدم تطابق با شرایط ارائه سرشماره ۴ رقمی', $freshReq->rejection_reason);
        $this->assertSame($admin->id, $freshReq->reviewed_by);
        $this->assertNull($freshReq->allocated_number_id);
    }

    public function test_admin_reject_fails_without_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $request = NumberRequest::factory()->create([
            'status' => NumberRequestStatus::Pending,
        ]);

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/reject", [
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertSame(NumberRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_cannot_review_already_approved_request(): void
    {
        $admin = User::factory()->admin()->create();
        $request = NumberRequest::factory()->approved()->create();

        $response = $this->actingAs($admin)->post("/admin/requests/{$request->id}/approve", [
            'mode' => 'auto',
        ]);

        $response->assertStatus(403);
    }
}
