<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorAuthenticationController extends Controller
{
    public function __construct(
        protected TotpService $totp
    ) {}

    /**
     * Get current 2FA state for user profile.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'has_recovery_codes' => ! empty($user->two_factor_recovery_codes),
            'recovery_codes_count' => count($user->two_factor_recovery_codes ?? []),
            'trust_tier' => $user->trust_tier,
            'risk_score' => $user->risk_score,
        ]);
    }

    /**
     * Initiate 2FA activation: generate secret and recovery codes.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->totp->generateSecret(20);
        $recoveryCodes = $this->totp->generateRecoveryCodes(8);
        $qrCodeUrl = $this->totp->generateQrCodeUrl('GetNumber', $user->username, $secret);

        // Save unconfirmed secret
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'recovery_codes' => $recoveryCodes,
            'message' => 'کلید امنیتی تولید گردید. لطفاً کد ۶ رقمی برنامه احراز هویت را جهت فعال‌سازی نهایی وارد فرمایید.',
        ]);
    }

    /**
     * Confirm and finalize 2FA activation by verifying a valid TOTP code.
     */
    public function confirm(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (empty($user->two_factor_secret)) {
            throw ValidationException::withMessages([
                'code' => 'فرآیند فعال‌سازی احراز هویت آغاز نشده است.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ], [
            'code.required' => 'ورود کد ۶ رقمی الزامی است.',
            'code.size' => 'کد تایید باید دقیقاً ۶ رقم باشد.',
        ]);

        if (! $this->totp->verifyCode($user->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'کد ۶ رقمی وارد شده نامعتبر یا منقضی شده است.',
            ]);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => '2fa_enabled',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'احراز هویت دومرحله‌ای با موفقیت فعال و تایید گردید.',
            'recovery_codes' => $user->two_factor_recovery_codes,
        ]);
    }

    /**
     * Disable 2FA safely (requires current password).
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'ورود کلمه عبور فعلی الزامی است.',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'کلمه عبور وارد شده نادرست است.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => '2fa_disabled',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'احراز هویت دومرحله‌ای با موفقیت غیرفعال گردید.',
        ]);
    }

    /**
     * Regenerate 2FA recovery codes (requires current password).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'password' => 'احراز هویت دومرحله‌ای فعال نمی‌باشد.',
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'ورود کلمه عبور فعلی الزامی است.',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'کلمه عبور وارد شده نادرست است.',
            ]);
        }

        $newCodes = $this->totp->generateRecoveryCodes(8);
        $user->forceFill([
            'two_factor_recovery_codes' => $newCodes,
        ])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => '2fa_recovery_codes_regenerated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'recovery_codes' => $newCodes,
            'message' => 'کدهای بازیابی جدید با موفقیت صادر گردیدند.',
        ]);
    }

    /**
     * Invalidate all other active sessions across devices (requires current password).
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'ورود کلمه عبور فعلی جهت تایید هویت الزامی است.',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'کلمه عبور وارد شده صحیح نمی‌باشد.',
            ]);
        }

        Auth::logoutOtherDevices($validated['password']);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'other_sessions_terminated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمام نشست‌های فعال روی سایر دستگاه‌ها با موفقیت لغو و خارج شدند.',
        ]);
    }
}

