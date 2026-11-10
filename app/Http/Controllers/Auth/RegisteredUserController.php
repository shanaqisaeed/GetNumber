<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response|RedirectResponse
    {
        if (!SystemSetting::isRegistrationAllowed()) {
            return redirect()->route('login')->with('error', 'امکان ثبت‌نام مستقیم کاربر جدید در سامانه در حال حاضر غیرفعال است.');
        }

        return Inertia::render('Auth/Register', [
            'allowRegistration' => true,
            'enabledProviders' => [
                'username' => SystemSetting::isAuthProviderEnabled('username'),
                'email' => SystemSetting::isAuthProviderEnabled('email'),
                'ldap' => SystemSetting::isAuthProviderEnabled('ldap'),
                'keycloak' => SystemSetting::isAuthProviderEnabled('keycloak'),
            ],
            'keycloakLoginUrl' => SystemSetting::isAuthProviderEnabled('keycloak')
                ? route('auth.keycloak.redirect')
                : null,
        ]);
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        if (!SystemSetting::isRegistrationAllowed()) {
            return redirect()->route('login')->with('error', 'ثبت‌نام مستقیم غیرفعال می‌باشد.');
        }

        $regType = $request->input('registration_type', 'username');

        if ($regType === 'email') {
            if (!SystemSetting::isAuthProviderEnabled('email')) {
                return back()->withErrors(['email' => 'ثبت‌نام و ورود با ایمیل در حال حاضر غیرفعال است.']);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class.',email'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ], [
                'name.required' => 'ورود عنوان سازمانی / نام متقاضی الزامی است.',
                'email.required' => 'ورود نشانی پست الکترونیک (ایمیل) الزامی است.',
                'email.email' => 'فرمت نشانی ایمیل وارد شده معتبر نمی‌باشد.',
                'email.unique' => 'این نشانی ایمیل قبلاً در سامانه ثبت شده است.',
                'password.required' => 'ورود کلمه عبور الزامی است.',
                'password.confirmed' => 'تکرار کلمه عبور با مقدار کلمه عبور مطابقت ندارد.',
            ]);

            $cleanEmail = strtolower($validated['email']);
            $baseUsername = Str::before($cleanEmail, '@');
            $uniqueUsername = $this->generateUniqueUsername($baseUsername);

            $user = User::create([
                'name' => $validated['name'],
                'username' => $uniqueUsername,
                'email' => $cleanEmail,
                'password' => Hash::make($validated['password']),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            $user->linkIdentity('email', $cleanEmail, $cleanEmail, ['registered_with' => 'email']);
            $user->linkIdentity('username', $uniqueUsername, $cleanEmail, ['registered_with' => 'email']);
        } else {
            if (!SystemSetting::isAuthProviderEnabled('username')) {
                return back()->withErrors(['username' => 'ثبت‌نام با شناسه کاربری در حال حاضر غیرفعال است.']);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:'.User::class.',username'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ], [
                'name.required' => 'ورود عنوان سازمانی / نام متقاضی الزامی است.',
                'username.required' => 'ورود شناسه کاربری الزامی است.',
                'username.min' => 'شناسه کاربری باید حداقل ۳ نویسه باشد.',
                'username.max' => 'شناسه کاربری نمی‌تواند بیش از ۵۰ نویسه باشد.',
                'username.alpha_dash' => 'شناسه کاربری فقط می‌تواند شامل حروف انگلیسی، ارقام، خط تیره (-) و زیرخط (_) باشد.',
                'username.unique' => 'این شناسه کاربری قبلاً در سامانه ثبت شده است.',
                'password.required' => 'ورود کلمه عبور الزامی است.',
                'password.confirmed' => 'تکرار کلمه عبور با مقدار کلمه عبور مطابقت ندارد.',
            ]);

            $cleanUsername = strtolower($validated['username']);

            $user = User::create([
                'name' => $validated['name'],
                'username' => $cleanUsername,
                'password' => Hash::make($validated['password']),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            $user->linkIdentity('username', $cleanUsername, null, ['registered_with' => 'username']);
        }

        if (SystemSetting::isEventNotificationEnabled('user_registered')) {
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'user_registered',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => "کاربر جدید {$user->name} ({$user->username}) ثبت‌نام نمود.",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable) {
            }
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /**
     * Generate a unique fallback username.
     */
    protected function generateUniqueUsername(string $base): string
    {
        $username = Str::slug($base, '_');
        if (empty($username) || strlen($username) < 3) {
            $username = 'user_' . Str::random(5);
        }

        $candidate = $username;
        $counter = 1;
        while (User::where('username', $candidate)->exists()) {
            $candidate = "{$username}_{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
