<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ApiTokenServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function __construct(
        protected ApiTokenServiceInterface $tokenService
    ) {}

    /**
     * Display a listing of all API tokens across the system.
     */
    public function index(Request $request): Response
    {
        $query = ApiToken::with('user');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('token_prefix', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('username', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $tokens = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id,
                'user' => [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'username' => $t->user->username,
                ],
                'name' => $t->name,
                'prefix' => $t->token_prefix,
                'preview' => $t->token_prefix . '...' . substr((string) $t->id, -4),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'status_badge' => $t->status->badgeClasses(),
                'is_active' => $t->isActive(),
                'is_expired' => $t->isExpired(),
                'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at?->format('Y/m/d H:i'),
                'last_used_ip' => $t->last_used_ip,
                'expires_at' => $t->expires_at?->format('Y/m/d'),
                'created_at' => $t->created_at->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => ApiToken::count(),
            'active' => ApiToken::where('status', ApiTokenStatus::Active)->count(),
            'revoked' => ApiToken::where('status', ApiTokenStatus::Revoked)->count(),
        ];

        return Inertia::render('Admin/ApiTokens/Index', [
            'tokens' => $tokens,
            'filters' => $request->only(['search', 'status']),
            'stats' => $stats,
        ]);
    }

    /**
     * Admin revoke token.
     */
    public function revoke(Request $request, ApiToken $token): RedirectResponse
    {
        $this->tokenService->revokeToken($token);

        return back()->with('success', "کلید دسترسی «{$token->name}» متعلق به کاربر {$token->user?->name} باطل شد.");
    }

    /**
     * Admin delete token.
     */
    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        $this->tokenService->deleteToken($token);

        return back()->with('success', "کلید دسترسی «{$token->name}» با موفقیت از سامانه حذف گردید.");
    }
}
