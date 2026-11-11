<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationAndSmtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_email_user_can_login_but_is_redirected_to_verify_email(): void
    {
        SystemSetting::set('require_email_verification', '1');

        $user = User::factory()->create([
            'email' => 'unverified@example.com',
            'email_verified_at' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        // Attempting to visit dashboard should redirect to verification.notice
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('verification.notice'));

        // Visiting verification notice succeeds
        $noticeResponse = $this->get(route('verification.notice'));
        $noticeResponse->assertOk();
    }

    public function test_verified_email_user_can_access_dashboard_directly(): void
    {
        SystemSetting::set('require_email_verification', '1');

        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertOk();
    }

    public function test_user_without_email_is_not_blocked(): void
    {
        SystemSetting::set('require_email_verification', '1');

        $user = User::factory()->create([
            'email' => null,
            'email_verified_at' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertOk();
    }

    public function test_verification_email_can_be_resent(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'resend@example.com',
            'email_verified_at' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->post(route('verification.send'));
        $response->assertSessionHas('status', 'verification-link-sent');
    }

    public function test_email_can_be_verified_via_signed_url(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->create([
            'email' => 'verify-me@example.com',
            'email_verified_at' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $response = $this->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard').'?verified=1');
    }

    public function test_admin_can_update_smtp_settings(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($admin);

        $response = $this->post(route('admin.settings.smtp.update'), [
            'mail_host' => 'smtp.custom-mail.com',
            'mail_port' => 465,
            'mail_encryption' => 'ssl',
            'mail_username' => 'support@custom-mail.com',
            'mail_password' => 'secret-smtp-password',
            'mail_from_address' => 'system@custom-mail.com',
            'mail_from_name' => 'Custom SMS Gateway',
            'require_email_verification' => true,
        ]);

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'smtp']));

        $this->assertEquals('smtp.custom-mail.com', SystemSetting::get('mail_host'));
        $this->assertEquals('465', SystemSetting::get('mail_port'));
        $this->assertEquals('ssl', SystemSetting::get('mail_encryption'));
        $this->assertEquals('system@custom-mail.com', SystemSetting::get('mail_from_address'));
        $this->assertEquals('1', SystemSetting::get('require_email_verification'));
    }
}

