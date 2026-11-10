<?php

namespace App\Http\Middleware;

use App\Contracts\ApiTokenServiceInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        protected ApiTokenServiceInterface $tokenService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $requiredAbility = null): Response
    {
        $plainToken = $request->bearerToken() ?? $request->header('X-API-Key');

        if (! $plainToken) {
            return new JsonResponse([
                'success' => false,
                'message' => __('middleware.api_token.missing'),
                'code' => 'MISSING_API_TOKEN',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->tokenService->authenticate($plainToken, $request->ip());

        if (! $token) {
            return new JsonResponse([
                'success' => false,
                'message' => __('middleware.api_token.invalid'),
                'code' => 'INVALID_API_TOKEN',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Verify ability if specified
        if ($requiredAbility && ! $token->can($requiredAbility)) {
            return new JsonResponse([
                'success' => false,
                'message' => __('middleware.api_token.insufficient_ability', ['ability' => $requiredAbility]),
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ], Response::HTTP_FORBIDDEN);
        }

        // Set authenticated user and token
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}