<?php

namespace App\Http\Controllers\User;

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
     * Display a listing of user's API tokens.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tokens = ApiToken::where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
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
                'expires_at' => $t->expires_at?->format('Y/m/d'),
                'created_at' => $t->created_at->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => $tokens->count(),
            'active' => $tokens->where('is_active', true)->where('is_expired', false)->count(),
            'revoked' => $tokens->where('status', ApiTokenStatus::Revoked->value)->count(),
        ];

        return Inertia::render('User/ApiTokens/Index', [
            'tokens' => $tokens,
            'stats' => $stats,
            'plainTextToken' => session('plain_text_token'),
        ]);
    }

    /**
     * Generate a new API token for the authenticated user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'expires_days' => ['nullable', 'integer', 'in:30,60,90,180,365'],
        ], [
            'name.required' => 'تعیین عنوان یا نام سیستم متصل الزامی است.',
            'name.min' => 'عنوان کلید دسترسی باید حداقل ۲ کاراکتر باشد.',
            'name.max' => 'عنوان کلید دسترسی نباید بیش از ۱۰۰ کاراکتر باشد.',
        ]);

        $expiresAt = ! empty($validated['expires_days'])
            ? now()->addDays((int) $validated['expires_days'])
            : null;

        $result = $this->tokenService->createToken(
            user: $request->user(),
            name: $validated['name'],
            abilities: ['*'],
            expiresAt: $expiresAt
        );

        return back()
            ->with('success', "کلید دسترسی «{$result['token']->name}» با موفقیت صادر گردید.")
            ->with('plain_text_token', $result['plain_text_token']);
    }

    /**
     * Rotate / regenerate an existing token.
     */
    public function rotate(Request $request, ApiToken $token): RedirectResponse
    {
        if ($token->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $result = $this->tokenService->rotateToken($token);

        return back()
            ->with('success', "کلید دسترسی «{$token->name}» با موفقیت تعویض شد و کلید قبلی منقضی گردید.")
            ->with('plain_text_token', $result['plain_text_token']);
    }

    /**
     * Revoke an API token immediately.
     */
    public function revoke(Request $request, ApiToken $token): RedirectResponse
    {
        if ($token->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $this->tokenService->revokeToken($token);

        return back()->with('success', "کلید دسترسی «{$token->name}» با موفقیت باطل شد و دسترسی‌های آن مسدود گردید.");
    }

    /**
     * Delete an API token completely.
     */
    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        if ($token->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $this->tokenService->deleteToken($token);

        return back()->with('success', "کلید دسترسی «{$token->name}» با موفقیت حذف گردید.");
    }
}
