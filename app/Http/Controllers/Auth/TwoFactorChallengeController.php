<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        protected TotpService $totp
    ) {}

    /**
     * Display the Two-Factor Challenge view.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify the 2FA TOTP code or backup recovery code and authenticate.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        $userId = $request->session()->get('login.id');
        /** @var User $user */
        $user = User::findOrFail($userId);

        if (! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['login.id', 'login.remember']);
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $authenticated = false;
        $usedRecoveryCode = false;

        // 1. Try TOTP Code if provided
        if (! empty($validated['code'])) {
            $cleanCode = preg_replace('/\s+/', '', (string) $validated['code']);
            if ($this->totp->verifyCode($user->two_factor_secret, $cleanCode)) {
                $authenticated = true;
            }
        }

        // 2. Try Recovery Code if provided
        if (! $authenticated && ! empty($validated['recovery_code'])) {
            $cleanRecovery = strtoupper(trim((string) $validated['recovery_code']));
            $recoveryCodes = $user->two_factor_recovery_codes ?? [];

            if (in_array($cleanRecovery, $recoveryCodes, true)) {
                $authenticated = true;
                $usedRecoveryCode = true;

                // Remove the used one-time recovery code
                $updatedCodes = array_values(array_filter($recoveryCodes, fn ($c) => $c !== $cleanRecovery));
                $user->update(['two_factor_recovery_codes' => $updatedCodes]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => '2fa_recovery_code_used',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'ip_address' => $request->ip(),
                ]);
            }
        }

        if (! $authenticated) {
            SecurityIncident::create([
                'user_id' => $user->id,
                'type' => 'failed_2fa_challenge',
                'severity' => 'medium',
                'details' => [
                    'ip' => $request->ip(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'action_taken' => 'logged',
            ]);

            throw ValidationException::withMessages([
                'code' => __('controllers.auth.two_factor_invalid_code'),
            ]);
        }

        $remember = (bool) $request->session()->get('login.remember', false);
        Auth::login($user, $remember);

        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->regenerate();

        if ($user->isAdmin()) {
            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}