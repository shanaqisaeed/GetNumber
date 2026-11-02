<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    public function test_user_can_initiate_2fa_activation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('two-factor.enable'));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'secret',
            'qr_code_url',
            'recovery_codes',
        ]);

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_user_can_confirm_2fa_with_valid_totp_code(): void
    {
        $user = User::factory()->create();

        // 1. Initiate
        $this->actingAs($user)->postJson(route('two-factor.enable'));
        $user->refresh();

        $secret = $user->two_factor_secret;
        $validCode = $this->totp->calculateCode($secret);

        // 2. Confirm
        $response = $this->actingAs($user)->postJson(route('two-factor.confirm'), [
            'code' => $validCode,
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_confirming_2fa_with_invalid_code_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('two-factor.enable'));

        $response = $this->actingAs($user)->postJson(route('two-factor.confirm'), [
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_user_can_disable_2fa_with_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
            'two_factor_secret' => $this->totp->generateSecret(),
            'two_factor_recovery_codes' => ['CODE1-1111', 'CODE2-2222'],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertTrue($user->hasTwoFactorEnabled());

        $response = $this->actingAs($user)->deleteJson(route('two-factor.disable'), [
            'password' => 'Secret123!',
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_login_redirects_to_2fa_challenge_when_enabled(): void
    {
        $secret = $this->totp->generateSecret();
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['REC1-1111', 'REC2-2222'],
        ]);

        $response = $this->post(route('login'), [
            'username' => $user->username,
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->assertEquals($user->id, session('login.id'));
    }

    public function test_can_authenticate_2fa_challenge_with_totp_code(): void
    {
        $secret = $this->totp->generateSecret();
        $user = User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $validCode = $this->totp->calculateCode($secret);

        $response = $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.challenge'), [
                'code' => $validCode,
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_can_authenticate_2fa_challenge_with_recovery_code_and_consumes_it(): void
    {
        $secret = $this->totp->generateSecret();
        $user = User::factory()->create([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['REC1-1111', 'REC2-2222'],
        ]);

        $response = $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.challenge'), [
                'recovery_code' => 'REC1-1111',
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        // The used recovery code should have been consumed
        $this->assertNotContains('REC1-1111', $user->two_factor_recovery_codes);
        $this->assertContains('REC2-2222', $user->two_factor_recovery_codes);
        $this->assertCount(1, $user->two_factor_recovery_codes);
    }
}

