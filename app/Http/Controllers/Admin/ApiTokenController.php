<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ApiTokenServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
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
                    'is_admin' => $t->user->isAdmin(),
                ],
                'name' => $t->name,
                'prefix' => $t->token_prefix,
                'preview' => $t->token_prefix . '...' . substr((string) $t->id, -4),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'status_badge' => $t->status->badgeClasses(),
                'is_active' => $t->isActive(),
                'is_expired' => $t->isExpired(),
                'abilities' => $t->abilities ?? ['*'],
                'has_full_access' => in_array('*', $t->abilities ?? ['*'], true),
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

        $availableAbilities = [
            '*' => 'حداکثر دسترسی‌ها (دسترسی نامحدود به تمامی وب‌سرویس‌ها و اختیارات کامل راهبری)',
            'numbers:read' => 'مشاهده و استعلام اطلاعات خطوط',
            'numbers:manage' => 'مدیریت، تخصیص، ویرایش و آزادسازی خطوط سازمانی',
            'messages:read' => 'مشاهده و استعلام پیام‌های دریافتی',
            'messages:write' => 'ثبت و ارسال پیام‌ها به وب‌سرویس',
            'service-numbers:manage' => 'مدیریت و تأیید سرشماره‌های اختصاصی',
            'users:manage' => 'مدیریت و استعلام کاربران سامانه',
        ];

        return Inertia::render('Admin/ApiTokens/Index', [
            'tokens' => $tokens,
            'filters' => $request->only(['search', 'status']),
            'stats' => $stats,
            'availableAbilities' => $availableAbilities,
            'plainTextToken' => session('plain_text_token'),
        ]);
    }

    /**
     * Issue a new API token by Admin (for admin itself with maximum access, or for any user).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'expires_days' => ['nullable', 'integer', 'in:30,60,90,180,365'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:50'],
        ], [
            'name.required' => 'تعیین عنوان یا نام سیستم متصل الزامی است.',
            'name.min' => 'عنوان کلید دسترسی باید حداقل ۲ کاراکتر باشد.',
            'name.max' => 'عنوان کلید دسترسی نباید بیش از ۱۰۰ کاراکتر باشد.',
            'user_id.exists' => 'کاربر انتخاب‌شده در سامانه یافت نشد.',
        ]);

        $targetUser = ! empty($validated['user_id'])
            ? User::findOrFail($validated['user_id'])
            : $request->user();

        // If abilities is not provided or includes '*', default to ['*']
        $abilities = ['*'];
        if (! empty($validated['abilities'])) {
            if (in_array('*', $validated['abilities'], true)) {
                $abilities = ['*'];
            } else {
                $abilities = array_values(array_unique($validated['abilities']));
            }
        }

        $expiresAt = ! empty($validated['expires_days'])
            ? now()->addDays((int) $validated['expires_days'])
            : null;

        $result = $this->tokenService->createToken(
            user: $targetUser,
            name: $validated['name'],
            abilities: $abilities,
            expiresAt: $expiresAt
        );

        $ownerNotice = $targetUser->id === $request->user()->id
            ? 'حساب راهبری شما (حداکثر دسترسی‌ها)'
            : "کاربر «{$targetUser->name}»";

        AuditLog::record('token_created', $result['token'], $request->user(), [
            'name' => $result['token']->name,
            'target_user_id' => $targetUser->id,
            'target_username' => $targetUser->username,
            'abilities' => $abilities,
        ]);

        return back()
            ->with('success', "کلید دسترسی «{$result['token']->name}» برای {$ownerNotice} با موفقیت صادر گردید.")
            ->with('plain_text_token', $result['plain_text_token']);
    }

    /**
     * Admin rotate token.
     */
    public function rotate(Request $request, ApiToken $token): RedirectResponse
    {
        $result = $this->tokenService->rotateToken($token);

        AuditLog::record('token_rotated', $token, $request->user(), [
            'token_id' => $token->id,
            'name' => $token->name,
            'owner_id' => $token->user_id,
        ]);

        return back()
            ->with('success', "کلید دسترسی «{$token->name}» متعلق به کاربر {$token->user?->name} با موفقیت تعویض شد.")
            ->with('plain_text_token', $result['plain_text_token']);
    }

    /**
     * Admin revoke token.
     */
    public function revoke(Request $request, ApiToken $token): RedirectResponse
    {
        $this->tokenService->revokeToken($token);

        AuditLog::record('token_revoked', $token, $request->user(), [
            'token_id' => $token->id,
            'name' => $token->name,
            'owner_id' => $token->user_id,
        ]);

        return back()->with('success', "کلید دسترسی «{$token->name}» متعلق به کاربر {$token->user?->name} باطل شد.");
    }

    /**
     * Admin delete token.
     */
    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        $tokenName = $token->name;
        $tokenId = $token->id;
        $userId = $token->user_id;

        $this->tokenService->deleteToken($token);

        AuditLog::record('token_revoked', null, $request->user(), [
            'token_id' => $tokenId,
            'name' => $tokenName,
            'owner_id' => $userId,
            'action' => 'deleted',
        ]);

        return back()->with('success', "کلید دسترسی «{$tokenName}» با موفقیت از سامانه حذف گردید.");
    }
}
