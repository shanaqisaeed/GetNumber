<?php

namespace App\Http\Requests\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Auth\IdentityAuthService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $authType = $this->input('auth_type', 'username');

        return match ($authType) {
            'email' => [
                'auth_type' => ['required', 'string', 'in:username,email,ldap'],
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
                'remember' => ['boolean'],
            ],
            'ldap' => [
                'auth_type' => ['required', 'string', 'in:username,email,ldap'],
                'ldap_username' => ['required', 'string'],
                'ldap_password' => ['required', 'string'],
                'remember' => ['boolean'],
            ],
            default => [
                'auth_type' => ['nullable', 'string', 'in:username,email,ldap'],
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
                'remember' => ['boolean'],
            ],
        };
    }

    /**
     * Custom validation messages in formal Persian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => __('requests.auth.login.username_required'),
            'email.required' => __('requests.auth.login.email_required'),
            'email.email' => __('requests.auth.login.email_invalid'),
            'password.required' => __('requests.auth.login.password_required'),
            'ldap_username.required' => __('requests.auth.login.ldap_username_required'),
            'ldap_password.required' => __('requests.auth.login.ldap_password_required'),
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $authType = $this->input('auth_type', 'username');

        // Verify provider is enabled in general settings
        if (!SystemSetting::isAuthProviderEnabled($authType)) {
            RateLimiter::hit($this->throttleKey(), 60);
            $field = match ($authType) {
                'email' => 'email',
                'ldap' => 'ldap_username',
                default => 'username',
            };

            throw ValidationException::withMessages([
                $field => __('requests.auth.login.provider_disabled'),
            ]);
        }

        /** @var IdentityAuthService $identityService */
        $identityService = app(IdentityAuthService::class);

        try {
            /** @var User $user */
            $user = match ($authType) {
                'email' => $identityService->authenticateEmail(
                    $this->string('email')->trim()->toString(),
                    $this->string('password')->toString()
                ),
                'ldap' => $identityService->authenticateLdap(
                    $this->string('ldap_username')->trim()->toString(),
                    $this->string('ldap_password')->toString()
                ),
                default => $identityService->authenticateUsername(
                    $this->string('username')->trim()->toString(),
                    $this->string('password')->toString()
                ),
            };
        } catch (ValidationException $e) {
            RateLimiter::hit($this->throttleKey(), 60);
            throw $e;
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->session()->put('login.id', $user->id);
            $this->session()->put('login.remember', $this->boolean('remember'));
            RateLimiter::clear($this->throttleKey());
            return;
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());
        $authType = $this->input('auth_type', 'username');
        $field = match ($authType) {
            'email' => 'email',
            'ldap' => 'ldap_username',
            default => 'username',
        };

        throw ValidationException::withMessages([
            $field => __('requests.auth.login.throttle', ['seconds' => $seconds]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $identifier = $this->string('username')->toString()
            ?: $this->string('email')->toString()
            ?: $this->string('ldap_username')->toString()
            ?: 'unknown';

        return Str::transliterate(Str::lower($identifier) . '|' . $this->ip());
    }
}