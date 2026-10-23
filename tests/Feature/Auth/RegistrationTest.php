<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'کاربر آزمایشی',
            'username' => 'newuser123',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('username', 'newuser123')->first();
        $this->assertNotNull($user);
        $this->assertSame('کاربر آزمایشی', $user->name);
        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_registration_requires_unique_username(): void
    {
        User::factory()->create([
            'username' => 'existing_user',
        ]);

        $response = $this->post('/register', [
            'name' => 'تلاش تکراری',
            'username' => 'existing_user',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $response->assertSessionHasErrors(['username']);
        $this->assertGuest();
    }
}
