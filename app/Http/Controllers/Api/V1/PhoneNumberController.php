<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneNumberController extends Controller
{
    /**
     * List authenticated user's allocated numbers.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PhoneNumber::where('user_id', $user->id)
            ->where('status', PhoneNumberStatus::Allocated);

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
                'allocated_at' => $p->allocated_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'فهرست خطوط سازمانی فعال کاربر با موفقیت دریافت شد.',
            'count' => $numbers->count(),
            'data' => $numbers,
        ]);
    }
}
