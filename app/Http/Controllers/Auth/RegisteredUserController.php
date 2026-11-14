<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            return redirect()->route('login')->with('error', __('controllers.auth.registration_disabled'));
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
            return redirect()->route('login')->with('error', __('controllers.auth.direct_registration_disabled'));
        }

        $regType = $request->input('registration_type', 'username');

        if ($regType === 'email') {
            if (!SystemSetting::isAuthProviderEnabled('email')) {
                return back()->withErrors(['email' => __('controllers.auth.email_registration_disabled')]);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('user_identities', 'provider_id')->where('provider', 'email'),
                ],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ], [
                'name.required' => __('controllers.auth.val_name_required'),
                'email.required' => __('controllers.auth.val_email_required'),
                'email.email' => __('controllers.auth.val_email_invalid'),
                'email.unique' => __('controllers.auth.val_email_unique'),
                'password.required' => __('controllers.auth.val_password_required'),
                'password.confirmed' => __('controllers.auth.val_password_confirmed'),
            ]);

            $cleanEmail = strtolower($validated['email']);
            $baseUsername = Str::before($cleanEmail, '@');
            $uniqueUsername = $this->generateUniqueUsername($baseUsername);

            $user = User::create([
                'name' => $validated['name'],
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            // Save dedicated email identity credential in user_identities
            $user->linkIdentity('email', $cleanEmail, $cleanEmail, ['registered_with' => 'email'], $validated['password']);
            // Save username identity
            $user->linkIdentity('username', $uniqueUsername, $cleanEmail, ['registered_with' => 'email'], $validated['password']);
        } else {
            if (!SystemSetting::isAuthProviderEnabled('username')) {
                return back()->withErrors(['username' => __('controllers.auth.username_registration_disabled')]);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'username' => [
                    'required',
                    'string',
                    'min:3',
                    'max:50',
                    'alpha_dash',
                    Rule::unique('user_identities', 'provider_id')->where('provider', 'username'),
                ],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ], [
                'name.required' => __('controllers.auth.val_name_required'),
                'username.required' => __('controllers.auth.val_username_required'),
                'username.min' => __('controllers.auth.val_username_min'),
                'username.max' => __('controllers.auth.val_username_max'),
                'username.alpha_dash' => __('controllers.auth.val_username_alpha_dash'),
                'username.unique' => __('controllers.auth.val_username_unique'),
                'password.required' => __('controllers.auth.val_password_required'),
                'password.confirmed' => __('controllers.auth.val_password_confirmed'),
            ]);

            $cleanUsername = strtolower($validated['username']);

            $user = User::create([
                'name' => $validated['name'],
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            $user->linkIdentity('username', $cleanUsername, null, ['registered_with' => 'username'], $validated['password']);
        }

        if (SystemSetting::isEventNotificationEnabled('user_registered')) {
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'user_registered',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => __('controllers.auth.audit_user_registered', ['name' => $user->name, 'username' => $user->username]),
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
        while (UserIdentity::where('provider', 'username')->where('provider_id', $candidate)->exists()) {
            $candidate = "{$username}_{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
