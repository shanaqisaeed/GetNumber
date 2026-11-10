<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SessionManagerService
{
    /**
     * Get all active sessions for a user with parsed device and browser metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSessions(User $user, Request $request): array
    {
        $currentSessionId = $request->session()->getId();

        $rawSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get();

        return $rawSessions->map(function ($session) use ($currentSessionId) {
            $isCurrent = $session->id === $currentSessionId;
            $agent = (string) ($session->user_agent ?? '');
            $parsed = $this->parseUserAgent($agent);
            $lastActivity = Carbon::createFromTimestamp($session->last_activity);

            return [
                'id' => $session->id,
                'ip_address' => $session->ip_address ?? '127.0.0.1',
                'is_current_device' => $isCurrent,
                'platform' => $parsed['platform'],
                'browser' => $parsed['browser'],
                'device_type' => $parsed['device_type'], // 'desktop' | 'mobile' | 'tablet'
                'last_active' => $isCurrent ? 'نشست جاری (هم‌اکنون)' : $lastActivity->diffForHumans(),
                'last_activity_date' => $lastActivity->format('Y/m/d H:i:s'),
                'last_activity_timestamp' => (int) $session->last_activity,
            ];
        })->values()->all();
    }

    /**
     * Terminate all active sessions on other devices.
     */
    public function terminateOtherSessions(User $user, Request $request, ?string $password): void
    {
        // If user has a password set, require password verification
        if (!empty($user->password)) {
            if (empty($password) || !Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'کلمه عبور وارد شده نادرست می‌باشد.',
                ]);
            }

            Auth::logoutOtherDevices($password);
        }

        $currentSessionId = $request->session()->getId();

        // Delete all sessions for this user except the current active session
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        try {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'other_sessions_terminated',
                'entity_type' => User::class,
                'entity_id' => $user->id,
                'description' => 'خروج موفق از تمامی نشست‌ها روی سایر دستگاه‌ها',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Terminate a specific session by ID.
     */
    public function terminateSpecificSession(User $user, string $sessionId, Request $request): bool
    {
        if ($sessionId === $request->session()->getId()) {
            throw ValidationException::withMessages([
                'session' => 'امکان قطع نشست جاری از این بخش وجود ندارد. لطفاً از دکمه خروج استفاده فرمایید.',
            ]);
        }

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        if ($deleted > 0) {
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'specific_session_terminated',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => "قطع نشست با شناسه {$sessionId}",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable) {
            }

            return true;
        }

        return false;
    }

    /**
     * Lightweight and accurate User-Agent parser.
     *
     * @return array{platform: string, browser: string, device_type: 'desktop'|'mobile'|'tablet'}
     */
    public function parseUserAgent(string $userAgent): array
    {
        $platform = 'سیستم‌عامل نامشخص';
        $browser = 'مرورگر نامشخص';
        $deviceType = 'desktop';

        // Detect Platform / OS
        if (preg_match('/windows|win32/i', $userAgent)) {
            $platform = 'ویندوز (Windows)';
        } elseif (preg_match('/iphone/i', $userAgent)) {
            $platform = 'آیفون (iOS)';
            $deviceType = 'mobile';
        } elseif (preg_match('/ipad/i', $userAgent)) {
            $platform = 'آیپد (iPadOS)';
            $deviceType = 'tablet';
        } elseif (preg_match('/android/i', $userAgent)) {
            $platform = 'اندروید (Android)';
            $deviceType = preg_match('/mobile/i', $userAgent) ? 'mobile' : 'tablet';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $platform = 'مک (macOS)';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $platform = 'لینوکس (Linux)';
        }

        // Detect Browser
        if (preg_match('/edg/i', $userAgent)) {
            $browser = 'مایکروسافت اج (Edge)';
        } elseif (preg_match('/samsungbrowser/i', $userAgent)) {
            $browser = 'سامسونگ اینترنت (Samsung Internet)';
        } elseif (preg_match('/opr|opera/i', $userAgent)) {
            $browser = 'اپرا (Opera)';
        } elseif (preg_match('/firefox/i', $userAgent)) {
            $browser = 'فایرفاکس (Firefox)';
        } elseif (preg_match('/chrome|crios/i', $userAgent)) {
            $browser = 'گوگل کروم (Chrome)';
        } elseif (preg_match('/safari/i', $userAgent)) {
            $browser = 'سافاری (Safari)';
        }

        return [
            'platform' => $platform,
            'browser' => $browser,
            'device_type' => $deviceType,
        ];
    }
}

