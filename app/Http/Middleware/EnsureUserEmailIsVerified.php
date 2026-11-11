<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If user is suspended/inactive, let EnsureAccountIsActive handle it
        if ($user && ($user->isSuspended() || $user->status === \App\Enums\UserStatus::Inactive)) {
            return $next($request);
        }

        // If user is attempting to access admin routes but is not admin/staff, let admin middleware reject with 403
        if ($user && ($request->is('admin') || $request->is('admin/*')) && ! $user->isStaff()) {
            return $next($request);
        }

        if ($user && $user->needsEmailVerification()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => __('middleware.auth.email_not_verified'),
                    'code' => 'EMAIL_NOT_VERIFIED',
                    'email' => $user->email,
                ], 403);
            }

            // Exclude verification routes, logout and locale switcher
            if (! $request->routeIs('verification.*') && ! $request->routeIs('logout') && ! $request->routeIs('locale.*')) {
                return redirect()->route('verification.notice');
            }
        }

        return $next($request);
    }
}

