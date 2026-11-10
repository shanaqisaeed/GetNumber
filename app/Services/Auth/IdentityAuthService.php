<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentityAuthService
{
    public function __construct(
        protected LdapAuthService $ldapService,
        protected KeycloakAuthService $keycloakService
    ) {}

    /**
     * Authenticate via Username and Password from table 2 (user_identities).
     */
    public function authenticateUsername(string $username, string $password): User
    {
        $username = trim(strtolower($username));

        // 1. Look up the credential in table 2 (user_identities)
        $identity = UserIdentity::where('provider', 'username')
            ->where('provider_id', $username)
            ->first();

        $user = $identity?->user;

        // Fallback if not yet in table 2 (e.g. direct database insert or legacy record)
        if (!$user) {
            $user = User::where('username', $username)->first();
            if ($user && !empty($user->password)) {
                $identity = $user->linkIdentity('username', $username, $user->email, ['auto_healed' => true], $user->password);
            }
        }

        // Determine password to check: must match the username identity password
        $hashToCheck = $identity?->password ?? $user?->password;

        if (!$user || !$hashToCheck || !Hash::check($password, $hashToCheck)) {
            $this->logLoginFailed('username', $username, 'نام کاربری یا کلمه عبور وارد شده نادرست است.');
            throw ValidationException::withMessages([
                'username' => 'نام کاربری یا کلمه عبور وارد شده صحیح نمی‌باشد.',
            ]);
        }

        $this->ensureUserCanLogin($user, 'username');
        return $user;
    }

    /**
     * Authenticate via Email and Password from table 2 (user_identities).
     */
    public function authenticateEmail(string $email, string $password): User
    {
        $email = trim(strtolower($email));

        // 1. Look up the credential in table 2 (user_identities)
        $identity = UserIdentity::where('provider', 'email')
            ->where('provider_id', $email)
            ->first();

        $user = $identity?->user;

        // Fallback if not yet in table 2
        if (!$user) {
            $user = User::where('email', $email)->first();
            if ($user && !empty($user->password)) {
                $identity = $user->linkIdentity('email', $email, $email, ['auto_healed' => true], $user->password);
            }
        }

        // Determine password to check: must match the email identity password
        $hashToCheck = $identity?->password ?? $user?->password;

        if (!$user || !$hashToCheck || !Hash::check($password, $hashToCheck)) {
            $this->logLoginFailed('email', $email, 'ایمیل یا کلمه عبور وارد شده نادرست است.');
            throw ValidationException::withMessages([
                'email' => 'ایمیل یا کلمه عبور وارد شده صحیح نمی‌باشد.',
            ]);
        }

        $this->ensureUserCanLogin($user, 'email');
        return $user;
    }

    /**
     * Authenticate via LDAP directory.
     */
    public function authenticateLdap(string $username, string $password): User
    {
        $username = trim($username);

        $ldapProfile = $this->ldapService->authenticate($username, $password);
        if (!$ldapProfile) {
            $this->logLoginFailed('ldap', $username, 'اطلاعات ورود در سرور سازمانی LDAP تایید نشد.');
            throw ValidationException::withMessages([
                'ldap_username' => 'نام کاربری یا کلمه عبور در سامانه سازمانی LDAP معتبر نمی‌باشد.',
            ]);
        }

        $uid = strtolower($ldapProfile['uid']);
        $email = !empty($ldapProfile['email']) ? strtolower($ldapProfile['email']) : null;

        // 1. Check if identity is already linked
        $identity = UserIdentity::where('provider', 'ldap')
            ->where('provider_id', $uid)
            ->first();

        if ($identity) {
            $user = $identity->user;
            $this->ensureUserCanLogin($user, 'ldap');
            return $user;
        }

        // 2. Check if existing user matches by email or username
        $user = null;
        if ($email) {
            $user = User::where('email', $email)->first();
        }
        if (!$user) {
            $user = User::where('username', $uid)->first();
        }

        // 3. Auto-provision if allowed or link to matched user
        if ($user) {
            $user->linkIdentity('ldap', $uid, $email, $ldapProfile['attributes']);
        } else {
            $canAutoCreate = (bool) (int) SystemSetting::get('ldap_auto_create_user', '1');
            if (!$canAutoCreate) {
                throw ValidationException::withMessages([
                    'ldap_username' => 'حساب کاربری محلی برای این شناسه LDAP در سامانه وجود ندارد و ایجاد خودکار غیرفعال است.',
                ]);
            }

            $user = User::create([
                'name' => $ldapProfile['name'] ?: $username,
                'username' => $this->generateUniqueUsername($uid),
                'email' => $email,
                'password' => Hash::make(Str::random(32)), // Random secure password for SSO users
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            $user->linkIdentity('ldap', $uid, $email, $ldapProfile['attributes']);

            if (SystemSetting::isEventNotificationEnabled('user_registered')) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'user_registered_via_ldap',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => "کاربر جدید {$user->name} ({$uid}) از طریق ورود سازمانی LDAP ایجاد شد.",
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        }

        $this->ensureUserCanLogin($user, 'ldap');
        return $user;
    }

    /**
     * Handle Keycloak OAuth2 callback user resolution.
     */
    public function authenticateKeycloak(array $keycloakProfile): User
    {
        $sub = $keycloakProfile['sub'];
        $email = !empty($keycloakProfile['email']) ? strtolower($keycloakProfile['email']) : null;
        $username = strtolower($keycloakProfile['username'] ?: 'keycloak_' . Str::substr($sub, 0, 8));

        // 1. Check existing identity
        $identity = UserIdentity::where('provider', 'keycloak')
            ->where('provider_id', $sub)
            ->first();

        if ($identity) {
            $user = $identity->user;
            $this->ensureUserCanLogin($user, 'keycloak');
            return $user;
        }

        // 2. Check existing user by email or username
        $user = null;
        if ($email) {
            $user = User::where('email', $email)->first();
        }
        if (!$user) {
            $user = User::where('username', $username)->first();
        }

        // 3. Auto-provision or link
        if ($user) {
            $user->linkIdentity('keycloak', $sub, $email, $keycloakProfile['raw']);
        } else {
            $canAutoCreate = (bool) (int) SystemSetting::get('keycloak_auto_create_user', '1');
            if (!$canAutoCreate) {
                throw ValidationException::withMessages([
                    'keycloak' => 'حساب کاربری محلی برای این حساب Keycloak یافت نشد.',
                ]);
            }

            $user = User::create([
                'name' => $keycloakProfile['name'] ?: $username,
                'username' => $this->generateUniqueUsername($username),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]);

            $user->linkIdentity('keycloak', $sub, $email, $keycloakProfile['raw']);

            if (SystemSetting::isEventNotificationEnabled('user_registered')) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'user_registered_via_keycloak',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => "کاربر جدید {$user->name} از طریق ورود یکپارچه Keycloak ایجاد شد.",
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        }

        $this->ensureUserCanLogin($user, 'keycloak');
        return $user;
    }

    /**
     * Link an email identity to an authenticated user with an optional dedicated password.
     */
    public function linkEmail(User $user, string $email, ?string $currentPassword = null, ?string $emailPassword = null): UserIdentity
    {
        $email = trim(strtolower($email));

        if ($user->password && !empty($currentPassword) && !Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'کلمه عبور فعلی حساب کاربری شما نادرست است.',
            ]);
        }

        // Verify email not claimed by another user
        $existing = UserIdentity::where('provider', 'email')
            ->where('provider_id', $email)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($existing || User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'این آدرس ایمیل قبلاً به حساب کاربری دیگری متصل شده است.',
            ]);
        }

        $user->email = $email;
        $user->save();

        return $user->linkIdentity('email', $email, $email, [], $emailPassword ?: $user->password);
    }

    /**
     * Link LDAP identity to an authenticated user.
     */
    public function linkLdap(User $user, string $ldapUsername, string $ldapPassword): UserIdentity
    {
        $profile = $this->ldapService->authenticate($ldapUsername, $ldapPassword);
        if (!$profile) {
            throw ValidationException::withMessages([
                'ldap_username' => 'نام کاربری یا کلمه عبور وارد شده در سرور LDAP معتبر نمی‌باشد.',
            ]);
        }

        $uid = strtolower($profile['uid']);
        $existing = UserIdentity::where('provider', 'ldap')
            ->where('provider_id', $uid)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'ldap_username' => 'این حساب کاربری LDAP قبلاً به کاربر دیگری در سامانه متصل شده است.',
            ]);
        }

        return $user->linkIdentity('ldap', $uid, $profile['email'], $profile['attributes']);
    }

    /**
     * Link Keycloak identity to an authenticated user.
     */
    public function linkKeycloak(User $user, array $keycloakProfile): UserIdentity
    {
        $sub = $keycloakProfile['sub'];
        $existing = UserIdentity::where('provider', 'keycloak')
            ->where('provider_id', $sub)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'keycloak' => 'این حساب کاربری Keycloak به کاربر دیگری متصل می‌باشد.',
            ]);
        }

        return $user->linkIdentity('keycloak', $sub, $keycloakProfile['email'], $keycloakProfile['raw']);
    }

    /**
     * Unlink an identity provider from a user safely.
     */
    public function unlinkProvider(User $user, string $provider): void
    {
        $identity = $user->getIdentity($provider);
        if (!$identity) {
            throw ValidationException::withMessages([
                'provider' => 'این شیوه ورود به حساب شما متصل نمی‌باشد.',
            ]);
        }

        // Guard: ensure user has at least one other active way to authenticate
        $totalIdentities = $user->identities()->count();
        $hasPassword = !empty($user->password);

        if ($totalIdentities <= 1 && !$hasPassword) {
            throw ValidationException::withMessages([
                'provider' => 'امکان قطع این اتصال وجود ندارد زیرا هیچ شیوه ورود جایگزین یا گذرواژه‌ای برای حساب شما تعریف نشده است.',
            ]);
        }

        $user->unlinkIdentity($provider);

        if ($provider === 'email' && $user->email === $identity->provider_id) {
            $user->email = null;
            $user->save();
        }
    }

    /**
     * Ensure the user account is active and not suspended.
     */
    protected function ensureUserCanLogin(User $user, string $provider): void
    {
        $field = match ($provider) {
            'email' => 'email',
            'ldap' => 'ldap_username',
            default => 'username',
        };

        if ($user->status === UserStatus::Suspended) {
            $this->logLoginFailed($provider, $user->username ?? $user->email, 'حساب کاربری به دستور راهبر معلق شده است.');
            $msg = 'حساب کاربری شما به دستور راهبر سامانه به حالت تعلیق درآمده است.';
            throw ValidationException::withMessages([
                $field => $msg,
                'login' => $msg,
            ]);
        }

        if ($user->status === UserStatus::Inactive) {
            $this->logLoginFailed($provider, $user->username ?? $user->email, 'حساب کاربری غیرفعال است.');
            $msg = 'حساب کاربری مورد نظر غیرفعال می‌باشد. لطفاً با راهبر سامانه تماس حاصل فرمایید.';
            throw ValidationException::withMessages([
                $field => $msg,
                'login' => $msg,
            ]);
        }
    }

    /**
     * Generate unique username if needed.
     */
    protected function generateUniqueUsername(string $base): string
    {
        $username = Str::slug($base, '_');
        if (empty($username)) {
            $username = 'user_' . Str::random(6);
        }

        $candidate = $username;
        $counter = 1;
        while (User::where('username', $candidate)->exists()) {
            $candidate = "{$username}_{$counter}";
            $counter++;
        }

        return $candidate;
    }

    /**
     * Audit log failed login if enabled in general settings.
     */
    protected function logLoginFailed(string $provider, ?string $identifier, string $reason): void
    {
        if (SystemSetting::isEventNotificationEnabled('login_failed')) {
            try {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'auth_login_failed',
                    'entity_type' => User::class,
                    'entity_id' => null,
                    'description' => "تلاش ناموفق ورود با روش {$provider} ({$identifier}). علت: {$reason}",
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
