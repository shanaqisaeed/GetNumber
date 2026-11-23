<?php

namespace App\Http\Middleware;

use App\Contracts\ApiTokenServiceInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates mobile/hybrid requests via EITHER:
 * 1. Active Laravel Web Session (when called from Capacitor WebView / Vue SPA), OR
 * 2. Device Bearer Token / X-Device-Token / X-API-Key (when called from Native CallKit / Telecom on Lock Screen).
 */
class AuthenticateMobileOrWeb
{
    public function __construct(
        protected ApiTokenServiceInterface $tokenService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check existing web session authentication
        $sessionUser = Auth::guard('web')->user() ?? $request->user();
        if ($sessionUser && $sessionUser->isActive()) {
            $request->setUserResolver(fn () => $sessionUser);
            return $next($request);
        }

        // 2. Check Device Bearer Token or X-Device-Token
        $plainToken = $request->bearerToken()
            ?? $request->header('X-Device-Token')
            ?? $request->header('X-API-Key');

        if ($plainToken) {
            $token = $this->tokenService->authenticate($plainToken, $request->ip());
            if ($token && ($token->can('mobile:calling') || $token->can('*'))) {
                $request->setUserResolver(fn () => $token->user);
                $request->attributes->set('api_token', $token);
                return $next($request);
            }
        }

        return new JsonResponse([
            'success' => false,
            'message' => __('middleware.auth.mobile_unauthenticated'),
            'code' => 'UNAUTHENTICATED_MOBILE_DEVICE',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
