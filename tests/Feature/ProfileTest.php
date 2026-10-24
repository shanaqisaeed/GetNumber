<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated_while_username_remains_unchanged(): void
    {
        $user = User::factory()->create([
            'username' => 'original_username',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'نام جدید سازمانی',
                'username' => 'updated_username',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('نام جدید سازمانی', $user->name);
        // Requirement 7: User cannot change their username under any circumstances
        $this->assertSame('original_username', $user->username);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_deleting_account_safely_releases_numbers_and_purges_messages(): void
    {
        $user = User::factory()->create();
        $allocationService = app(\App\Contracts\PhoneNumberAllocationServiceInterface::class);

        $phone = $allocationService->allocateStandardNumberToUser($user, '000333444');

        // Add private message
        \App\Models\Message::create([
            'phone_number_id' => $phone->id,
            'destination_number' => $phone->number,
            'sender' => 'CONFIDENTIAL_BANK',
            'body' => 'کد تایید انتقال وجه: ۱۲۳۴۵۶',
            'server_received_at' => now(),
        ]);

        $this->assertSame(1, $phone->messages()->count());

        // Delete account
        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertRedirect('/');

        // Line should be returned to Available pool and its private messages purged
        $freshPhone = $phone->fresh();
        $this->assertSame(\App\Enums\PhoneNumberStatus::Available, $freshPhone->status);
        $this->assertNull($freshPhone->user_id);
        $this->assertSame(0, $freshPhone->messages()->count());
    }
}

