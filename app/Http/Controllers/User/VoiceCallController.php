<?php

namespace App\Http\Controllers\User;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\VoiceCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceCallController extends Controller
{
    public function __construct(
        protected VoiceCallServiceInterface $voiceCallService
    ) {}

    /**
     * Display the full Voice Calling, Call History, and Contacts page.
     */
    public function index(Request $request): Response
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

        $selectedLineId = $request->filled('phone_number_id')
            ? (int) $request->input('phone_number_id')
            : ($lines->first()['id'] ?? null);

        $calls = VoiceCall::where('user_id', $user->id)
            ->with('phoneNumber')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id,
                'phone_number_id' => $c->phone_number_id,
                'source_identifier' => $c->source_identifier,
                'destination' => $c->destination,
                'direction' => $c->direction,
                'status' => $c->status,
                'started_at' => $c->started_at?->format('Y/m/d H:i:s'),
                'ended_at' => $c->ended_at?->format('Y/m/d H:i:s'),
                'duration_seconds' => $c->duration_seconds,
                'duration_formatted' => sprintf('%02d:%02d', floor($c->duration_seconds / 60), $c->duration_seconds % 60),
                'cost' => (float) $c->cost,
                'line_number' => $c->phoneNumber?->formatted_number ?? $c->source_identifier,
            ]);

        $contacts = Contact::where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn ($ct) => [
                'id' => $ct->id,
                'name' => $ct->name,
                'phone_number' => $ct->phone_number,
                'avatar_color' => $ct->avatar_color,
                'notes' => $ct->notes,
                'created_at' => $ct->created_at->format('Y/m/d'),
            ]);

        $ratePerMinute = (float) SystemSetting::get('voice_call_rate_per_minute', 0.1000);
        $walletBalance = (string) ($user->wallet?->balance ?? '0.0000');

        return Inertia::render('User/Calls/Index', [
            'lines' => $lines,
            'selected_line_id' => $selectedLineId,
            'calls' => $calls,
            'contacts' => $contacts,
            'rate_per_minute' => $ratePerMinute,
            'wallet_balance' => $walletBalance,
            'currency' => 'COIN',
        ]);
    }

    /**
     * Store a new contact.
     */
    public function storeContact(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:64'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'نام مخاطب الزامی است.',
            'phone_number.required' => 'شماره تماس مخاطب الزامی است.',
        ]);

        $contact = Contact::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'avatar_color' => $validated['avatar_color'] ?? 'indigo',
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'contact' => $contact,
                'message' => 'مخاطب جدید با موفقیت اضافه شد.',
            ], 201);
        }

        return back()->with('success', 'مخاطب جدید با موفقیت ذخیره شد.');
    }

    /**
     * Remove a contact.
     */
    public function destroyContact(Request $request, Contact $contact): RedirectResponse|JsonResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            abort(403);
        }

        $contact->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'مخاطب حذف شد.',
            ]);
        }

        return back()->with('success', 'مخاطب با موفقیت حذف شد.');
    }

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

