<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    /**
     * Test connection and token authentication.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return response()->json([
            'success' => true,
            'message' => 'ارتباط با وب‌سرویس سامانه مدیریت شماره‌های مجازی با موفقیت برقرار است.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role->value,
                ],
                'token' => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'prefix' => $token->token_prefix,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ],
                'server_time' => now()->toIso8601String(),
                'api_version' => 'v1',
            ],
        ]);
    }
}
