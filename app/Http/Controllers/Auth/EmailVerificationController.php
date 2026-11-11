<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (!$user->needsEmailVerification()) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
            'email' => $user->email,
        ]);
    }

    /**
     * Send a new email verification notification.
     */
    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->needsEmailVerification()) {
            return redirect()->intended(route('dashboard'));
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.auth.verification_send_failed', ['error' => $e->getMessage()]));
        }

        return back()->with('status', 'verification-link-sent');
    }

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        // If user ID does not match route ID
        if ((string) $user->getKey() !== (string) $id) {
            abort(403);
        }

        // Validate hash
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard').'?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));

            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'email_verified',
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'description' => __('controllers.auth.audit_email_verified', ['email' => $user->email]),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable) {
            }
        }

        return redirect()->intended(route('dashboard').'?verified=1')->with('success', __('controllers.auth.email_verified_success'));
    }
}

