<?php

namespace Tests\Feature\Numbers;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_when_viewing_numbers(): void
    {
        $response = $this->get('/numbers');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_numbers_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/numbers');
        $response->assertStatus(200);
    }

    public function test_user_can_allocate_a_standard_number_via_http(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateWallet()->update(['balance' => '10.0000']);

        PhoneNumber::create([
            'number' => '000111222',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $response = $this->actingAs($user)->post('/numbers');

        $response->assertRedirect();
        $this->assertDatabaseHas('phone_numbers', [
            'number' => '000111222',
            'user_id' => $user->id,
            'status' => PhoneNumberStatus::Allocated->value,
        ]);
    }

    public function test_user_allocates_generated_number_when_standard_pool_is_empty(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateWallet()->update(['balance' => '10.0000']);

        // Ensure no available standard numbers
        PhoneNumber::where('type', PhoneNumberType::UserStandard)->delete();

        $response = $this->actingAs($user)->post('/numbers');

        $response->assertRedirect();
        $allocated = PhoneNumber::where('user_id', $user->id)
            ->where('type', PhoneNumberType::UserStandard)
            ->first();

        $this->assertNotNull($allocated);
        $this->assertSame(9, strlen($allocated->number));
        $this->assertSame(PhoneNumberStatus::Allocated, $allocated->status);
    }

    public function test_user_can_update_their_own_number_title(): void
    {
        $user = User::factory()->create();
        $phone = PhoneNumber::factory()->allocated($user)->create([
            'display_title' => 'عنوان قدیمی',
        ]);

        $response = $this->actingAs($user)->patch("/numbers/{$phone->id}", [
            'display_title' => 'عنوان جدید',
        ]);

        $response->assertRedirect();
        $this->assertSame('عنوان جدید', $phone->fresh()->display_title);
    }

    public function test_user_cannot_update_another_users_number(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $phone = PhoneNumber::factory()->allocated($user1)->create();

        $response = $this->actingAs($user2)->patch("/numbers/{$phone->id}", [
            'display_title' => 'تلاش غیرمجاز',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_release_their_own_number(): void
    {
        $user = User::factory()->create();
        $phone = PhoneNumber::factory()->allocated($user)->create();

        $response = $this->actingAs($user)->delete("/numbers/{$phone->id}");

        $response->assertRedirect();
        $this->assertSame(PhoneNumberStatus::Available, $phone->fresh()->status);
        $this->assertNull($phone->fresh()->user_id);
    }

    public function test_user_cannot_release_another_users_number(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $phone = PhoneNumber::factory()->allocated($user1)->create();

        $response = $this->actingAs($user2)->delete("/numbers/{$phone->id}");

        $response->assertStatus(403);
        $this->assertSame($user1->id, $phone->fresh()->user_id);
    }

    public function test_releasing_number_permanently_purges_previous_messages_and_records_audit(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $phone = PhoneNumber::factory()->allocated($user1)->create([
            'number' => '000019999',
        ]);

        // Create messages for user1
        \App\Models\Message::create([
            'phone_number_id' => $phone->id,
            'destination_number' => $phone->number,
            'sender' => 'BANK',
            'body' => 'پیامک محرمانه کاربر اول: ۹۹۸۸۷۷',
            'server_received_at' => now(),
            'processing_status' => \App\Enums\MessageProcessingStatus::Received,
        ]);

        $this->assertEquals(1, $phone->messages()->count());

        // user1 releases the number
        $response = $this->actingAs($user1)->delete("/numbers/{$phone->id}");
        $response->assertRedirect();

        // 1. Assert messages are permanently purged
        $this->assertEquals(0, $phone->messages()->count());
        $this->assertDatabaseMissing('messages', [
            'phone_number_id' => $phone->id,
            'body' => 'پیامک محرمانه کاربر اول: ۹۹۸۸۷۷',
        ]);

        // 2. Assert operational audit trail exists without message body
        $this->assertDatabaseHas('phone_number_audits', [
            'phone_number_id' => $phone->id,
            'action' => 'released',
        ]);

        // 3. User2 allocates the same number from available pool
        $allocatedToUser2 = app(\App\Contracts\PhoneNumberAllocationServiceInterface::class)->allocateStandardNumberToUser(
            user: $user2,
            preferredNumber: $phone->number
        );

        $this->assertEquals($user2->id, $allocatedToUser2->user_id);
        // User2 has NO messages from user1
        $this->assertEquals(0, $allocatedToUser2->messages()->count());
    }
}

