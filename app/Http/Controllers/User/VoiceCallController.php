<?php

namespace App\Http\Controllers\User;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\VoiceCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceCallController extends Controller
{
    public function __construct(
        protected VoiceCallServiceInterface $voiceCallService
    ) {}

    /**
     * Get user's active lines and current voice call rate.
     */
    public function lines(Request $request): JsonResponse
    {
        $user = $request->user();
        $lines = PhoneNumber::where('user_id', $user->id)
            ->where('status', PhoneNumberStatus::Allocated)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'status' => $p->status->value,
                'is_active' => true,
            ]);

        $ratePerMinute = (float) SystemSetting::get('voice_call_rate_per_minute', 0.1000);
        $walletBalance = (string) ($user->wallet?->balance ?? '0.0000');

        return response()->json([
            'lines' => $lines,
            'rate_per_minute' => $ratePerMinute,
            'wallet_balance' => $walletBalance,
            'currency' => 'COIN',
        ]);
    }

    /**
     * Initiate a voice call.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number_id' => ['required', 'integer', 'exists:phone_numbers,id'],
            'destination' => ['required', 'string', 'max:64'],
        ], [
            'phone_number_id.required' => 'انتخاب خط مبدأ الزامی است.',
            'destination.required' => 'شماره یا شناسه مقصد الزامی است.',
        ]);

        $user = $request->user();
        $fromNumber = PhoneNumber::findOrFail($validated['phone_number_id']);

        $call = $this->voiceCallService->initiateCall($user, $fromNumber, $validated['destination']);

        return response()->json([
            'success' => true,
            'call' => [
                'id' => $call->id,
                'source' => $call->source_identifier,
                'destination' => $call->destination,
                'status' => $call->status,
                'started_at' => $call->started_at?->toIso8601String(),
                'rate_per_minute' => (float) $call->rate_per_minute,
            ],
            'message' => 'تماس صوتی برقرار شد (شبیه‌ساز پروداکشن فعال است).',
        ]);
    }

    /**
     * End an active call session.
     */
    public function end(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $voiceCall->user_id !== $user->id) {
            abort(403, 'شما دسترسی به این تماس را ندارید.');
        }

        $durationSeconds = $request->filled('duration_seconds')
            ? (int) $request->input('duration_seconds')
            : null;

        $endedCall = $this->voiceCallService->endCall($voiceCall, $durationSeconds);

        return response()->json([
            'success' => true,
            'call' => [
                'id' => $endedCall->id,
                'status' => $endedCall->status,
                'duration_seconds' => $endedCall->duration_seconds,
                'billed_minutes' => $endedCall->billed_minutes,
                'cost' => (float) $endedCall->cost,
                'started_at' => $endedCall->started_at?->toIso8601String(),
                'ended_at' => $endedCall->ended_at?->toIso8601String(),
            ],
            'wallet_balance' => (string) ($user->fresh()->wallet?->balance ?? '0.0000'),
            'message' => "مکالمه به پایان رسید. هزینه کسر شده: {$endedCall->cost} کوین.",
        ]);
    }

    /**
     * Check status of an active call.
     */
    public function status(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $voiceCall->user_id !== $user->id) {
            abort(403);
        }

        $status = $this->voiceCallService->getCallStatus($voiceCall);

        return response()->json($status);
    }
}

