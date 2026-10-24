<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneNumberController extends Controller
{
    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService
    ) {}

    /**
     * List numbers.
     * Regular users receive strictly their own active allocated numbers.
     * Admins / Tokens with full access (*) or numbers:manage can view all numbers or filter by user_id/status.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $hasAdminAccess = $user->isAdmin() && ($token->can('*') || $token->can('numbers:manage'));

        $query = PhoneNumber::query();

        if ($hasAdminAccess) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
        } else {
            $query->where('user_id', $user->id)
                ->where('status', PhoneNumberStatus::Allocated);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $numbers = $query->latest('allocated_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title,
                'effective_title' => $p->effective_title,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'user_id' => $p->user_id,
                'allocated_at' => $p->allocated_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'فهرست خطوط سازمانی با موفقیت دریافت شد.',
            'count' => $numbers->count(),
            'data' => $numbers,
        ]);
    }

    /**
     * Admin assign an available number to a user via API.
     */
    public function assign(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $user->isAdmin() || ! ($token->can('*') || $token->can('numbers:manage'))) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی غیرمجاز. تنها کلیدهای راهبر با دسترسی کامل مجاز به تخصیص خط هستند.',
                'code' => 'FORBIDDEN_ADMIN_ACTION',
            ], 403);
        }

        $validated = $request->validate([
            'number' => ['required', 'string'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'display_title' => ['nullable', 'string', 'max:100'],
        ]);

        $phoneNumber = PhoneNumber::where('number', $validated['number'])->first();
        if (! $phoneNumber) {
            return response()->json([
                'success' => false,
                'message' => 'شماره مورد نظر در سامانه یافت نشد.',
                'code' => 'NUMBER_NOT_FOUND',
            ], 404);
        }

        $targetUser = User::findOrFail($validated['user_id']);

        if ($phoneNumber->type === \App\Enums\PhoneNumberType::UserStandard) {
            $allocated = $this->allocationService->allocateStandardNumberToUser(
                user: $targetUser,
                preferredNumber: $phoneNumber->number,
                displayTitle: $validated['display_title'] ?? null,
                actor: $user
            );
        } else {
            $allocated = $this->allocationService->allocateServiceNumberToUser(
                user: $targetUser,
                serviceNumber: $phoneNumber->number,
                displayTitle: $validated['display_title'] ?? 'سرشماره سازمانی',
                actor: $user
            );
        }

        return response()->json([
            'success' => true,
            'message' => "شماره {$allocated->formatted_number} با موفقیت به کاربر {$targetUser->name} تخصیص یافت.",
            'data' => [
                'id' => $allocated->id,
                'number' => $allocated->number,
                'formatted_number' => $allocated->formatted_number,
                'user_id' => $allocated->user_id,
                'status' => $allocated->status->value,
            ],
        ]);
    }
}
