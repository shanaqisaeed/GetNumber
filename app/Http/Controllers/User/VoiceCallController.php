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
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'status' => $p->status->value,
                'is_active' => true,
                'type' => 'number',
                'type_label' => 'خط تلفن',
            ]);

        $customIdLines = $user->activeServiceAllocations()
            ->where('service_key', 'custom_id')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: ('آیدی اختصاصی (' . $cid->resource_identifier . ')'),
                'status' => 'allocated',
                'is_active' => true,
                'type' => 'custom_id',
                'type_label' => 'آیدی اختصاصی',
            ]);

        $allLines = $lines->concat($customIdLines)->values();

        $selectedKey = $request->input('line_key');
        if (! $selectedKey) {
            if ($request->filled('phone_number_id')) {
                $selectedKey = 'phone_' . (int) $request->input('phone_number_id');
            } elseif ($request->filled('custom_id')) {
                $matchedCid = $customIdLines->firstWhere('number', $request->input('custom_id'));
                if ($matchedCid) {
                    $selectedKey = $matchedCid['key'];
                }
            }
        }

        $selectedLine = $allLines->firstWhere('key', $selectedKey) ?: $allLines->first();
        $selectedLineKey = $selectedLine ? $selectedLine['key'] : null;
        $selectedLineId = $selectedLine ? $selectedLine['id'] : null;

        $initialDestination = $request->input('destination')
            ?: ($request->filled('custom_id') && ! $customIdLines->contains('number', $request->input('custom_id')) ? $request->input('custom_id') : '');

        $callsQuery = VoiceCall::where('user_id', $user->id)
            ->with('phoneNumber');

        if ($selectedLine) {
            if ($selectedLine['type'] === 'number') {
                $lineId = $selectedLine['id'];
                $lineNum = $selectedLine['number'];
                $formatted = $selectedLine['formatted_number'];
                $callsQuery->where(function ($q) use ($lineId, $lineNum, $formatted) {
                    $q->where('phone_number_id', $lineId)
                      ->orWhere('source_identifier', $lineNum)
                      ->orWhere('source_identifier', $formatted)
                      ->orWhere('destination', $lineNum)
                      ->orWhere('destination', $formatted);
                });
            } else {
                $ident = $selectedLine['number'];
                $callsQuery->where(function ($q) use ($ident) {
                    $q->where('source_identifier', $ident)
                      ->orWhere('destination', $ident);
                });
            }
        }

        $calls = $callsQuery->latest('id')
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
            'lines' => $allLines,
            'selected_line_key' => $selectedLineKey,
            'selected_line_id' => $selectedLineId,
            'initial_destination' => $initialDestination,
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
            'phone_number_id' => ['nullable', 'integer'],
            'line_key' => ['nullable', 'string', 'max:64'],
            'destination' => ['required', 'string', 'max:64'],
        ], [
            'destination.required' => 'شماره یا شناسه مقصد الزامی است.',
        ]);

        $user = $request->user();
        $lineKey = $request->input('line_key');
        $fromNumber = null;
        $customCallerHandle = null;

        if ($lineKey) {
            if (str_starts_with($lineKey, 'phone_')) {
                $pid = (int) substr($lineKey, 6);
                $fromNumber = PhoneNumber::where('user_id', $user->id)
                    ->where('status', PhoneNumberStatus::Allocated)
                    ->find($pid);
            } elseif (str_starts_with($lineKey, 'custom_')) {
                $cid = (int) substr($lineKey, 7);
                $cidAlloc = $user->activeServiceAllocations()
                    ->where('service_key', 'custom_id')
                    ->find($cid);
                if ($cidAlloc) {
                    $customCallerHandle = $cidAlloc->resource_identifier;
                    $fromNumber = $cidAlloc->phoneNumber
                        ?? PhoneNumber::where('user_id', $user->id)->where('status', PhoneNumberStatus::Allocated)->first();
                }
            }
        }

        if (! $fromNumber && $request->filled('phone_number_id')) {
            $lineId = (int) $request->input('phone_number_id');
            $fromNumber = PhoneNumber::where('user_id', $user->id)
                ->where('status', PhoneNumberStatus::Allocated)
                ->find($lineId);

            if (! $fromNumber) {
                // Check if user selected their custom ID allocation
                $cidAlloc = $user->activeServiceAllocations()
                    ->where('service_key', 'custom_id')
                    ->find($lineId);
                if ($cidAlloc) {
                    $customCallerHandle = $cidAlloc->resource_identifier;
                    $fromNumber = $cidAlloc->phoneNumber
                        ?? PhoneNumber::where('user_id', $user->id)->where('status', PhoneNumberStatus::Allocated)->first();
                }
            }
        }

        if (! $fromNumber) {
            return response()->json([
                'success' => false,
                'message' => 'خط مبدأ انتخابی یافت نشد یا معتبر نمی‌باشد.',
            ], 422);
        }

        try {
            $call = $this->voiceCallService->initiateCall($user, $fromNumber, $validated['destination']);
            if ($customCallerHandle) {
                $call->source_identifier = $customCallerHandle;
                $call->save();
            }

            return response()->json([
                'success' => true,
                'call' => [
                    'id' => $call->id,
                    'source' => $call->source_identifier,
                    'destination' => $call->destination,
                    'status' => $call->status,
                    'started_at' => $call->started_at?->toIso8601String(),
                    'rate_per_minute' => (float) $call->rate_per_minute,
                    'bridge_session_id' => $call->metadata['bridge_session_id'] ?? null,
                    'bridge_ws_endpoint' => $call->metadata['bridge_ws_endpoint'] ?? null,
                    'is_internal' => $call->metadata['is_internal'] ?? false,
                    'ping_ms' => $call->metadata['ping_ms'] ?? 22,
                ],
                'message' => 'تماس صوتی با موفقیت برقرار شد.',
            ]);
        } catch (\App\Exceptions\InsufficientWalletBalanceException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در برقراری تماس: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if there is an active inbound call ringing for this user.
     */
    public function activeIncoming(Request $request): JsonResponse
    {
        $user = $request->user();
        $incoming = VoiceCall::where('user_id', $user->id)
            ->where('direction', 'inbound')
            ->where('status', 'ringing')
            ->where('created_at', '>=', now()->subSeconds(60))
            ->latest('id')
            ->first();

        return response()->json([
            'has_incoming' => $incoming !== null,
            'call' => $incoming ? [
                'id' => $incoming->id,
                'source' => $incoming->source_identifier,
                'destination' => $incoming->destination,
                'status' => $incoming->status,
                'created_at' => $incoming->created_at->toIso8601String(),
                'bridge_session_id' => $incoming->metadata['bridge_session_id'] ?? null,
                'bridge_ws_endpoint' => $incoming->metadata['bridge_ws_endpoint'] ?? null,
                'ping_ms' => $incoming->metadata['ping_ms'] ?? 22,
            ] : null,
        ]);
    }

    /**
     * Answer an incoming call.
     */
    public function answer(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if ($voiceCall->user_id !== $user->id) {
            abort(403);
        }

        $voiceCall->update([
            'status' => 'in_call',
            'answered_at' => now(),
        ]);

        $peerId = $voiceCall->metadata['peer_call_id'] ?? null;
        if ($peerId) {
            VoiceCall::where('id', $peerId)->update([
                'status' => 'in_call',
                'answered_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تماس پاسخ داده شد.',
            'call' => $voiceCall,
        ]);
    }

    /**
     * Decline an incoming call.
     */
    public function decline(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if ($voiceCall->user_id !== $user->id) {
            abort(403);
        }

        $voiceCall->update([
            'status' => 'declined',
            'ended_at' => now(),
        ]);

        $peerId = $voiceCall->metadata['peer_call_id'] ?? null;
        if ($peerId) {
            VoiceCall::where('id', $peerId)->update([
                'status' => 'declined',
                'ended_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تماس رد شد.',
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
     * Periodic balance & tariff heartbeat during active call.
     * Auto-terminates call if wallet balance is exhausted mid-call.
     */
    public function pulse(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if ($voiceCall->user_id !== $user->id) {
            abort(403);
        }

        if ($voiceCall->status === 'completed' || $voiceCall->status === 'declined') {
            return response()->json([
                'active' => false,
                'can_continue' => false,
                'status' => $voiceCall->status,
                'message' => 'مکالمه پایان یافته است.',
            ]);
        }

        $rate = (float) $voiceCall->rate_per_minute;

        if ($rate <= 0) {
            return response()->json([
                'active' => true,
                'can_continue' => true,
                'is_internal' => (bool) ($voiceCall->metadata['is_internal'] ?? false),
                'rate_per_minute' => 0.0000,
                'wallet_balance' => (string) ($user->wallet?->balance ?? '0.0000'),
            ]);
        }

        $wallet = $user->wallet;
        $balance = (float) ($wallet?->balance ?? 0);
        $elapsedSec = (int) $request->input('elapsed_seconds', 0);

        if ($balance < $rate) {
            $endedCall = $this->voiceCallService->endCall($voiceCall, max(1, $elapsedSec));

            return response()->json([
                'active' => false,
                'can_continue' => false,
                'auto_terminated' => true,
                'reason' => 'insufficient_balance',
                'cost' => (float) $endedCall->cost,
                'wallet_balance' => (string) ($user->fresh()->wallet?->balance ?? '0.0000'),
                'message' => 'موجودی کیف‌پول شما به پایان رسید و مکالمه به صورت خودکار پایان یافت.',
            ]);
        }

        return response()->json([
            'active' => true,
            'can_continue' => true,
            'rate_per_minute' => $rate,
            'wallet_balance' => (string) $balance,
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

    /**
     * Send WebRTC signaling payload (offer, answer, candidate, end_call) to the peer.
     */
    public function signal(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $voiceCall->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:offer,answer,ice_candidate,end_call'],
            'payload' => ['required'],
        ]);

        $peerCallId = $voiceCall->metadata['callee_call_id'] ?? $voiceCall->metadata['peer_call_id'] ?? null;
        if (! $peerCallId) {
            return response()->json(['success' => false, 'message' => 'مخاطب متناظر یافت نشد.'], 404);
        }

        $signalItem = [
            'type' => $validated['type'],
            'payload' => $validated['payload'],
            'from_user_id' => $user->id,
            'timestamp' => microtime(true),
        ];

        // Store in cache queue for the peer call to pick up
        $queueKey = "voice_signals_{$peerCallId}";
        $existing = \Illuminate\Support\Facades\Cache::get($queueKey, []);
        $existing[] = $signalItem;
        if (count($existing) > 30) {
            $existing = array_slice($existing, -30);
        }
        \Illuminate\Support\Facades\Cache::put($queueKey, $existing, now()->addMinutes(5));

        return response()->json(['success' => true]);
    }

    /**
     * Poll pending WebRTC signals and active call status for this peer.
     */
    public function signals(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $voiceCall->user_id !== $user->id) {
            abort(403);
        }

        $queueKey = "voice_signals_{$voiceCall->id}";
        $signals = \Illuminate\Support\Facades\Cache::pull($queueKey, []);

        $isActive = in_array($voiceCall->status, ['in_call', 'ringing']);

        return response()->json([
            'success' => true,
            'status' => $voiceCall->status,
            'is_active' => $isActive,
            'signals' => $signals,
        ]);
    }

    /**
     * Handle incoming webhook events from the Go Voice Bridge Daemon.
     */
    public function bridgeWebhook(Request $request): JsonResponse
    {
        $secret = SystemSetting::get('voice_bridge_secret', '');
        if (! empty($secret)) {
            $provided = $request->header('X-Bridge-Secret') ?: $request->input('secret');
            if ($provided !== $secret) {
                return response()->json(['error' => 'Unauthorized bridge token'], 401);
            }
        }

        $event = $request->input('event');
        $sessionId = $request->input('session_id');
        $durationSeconds = (int) $request->input('duration_seconds', 0);

        if ($event === 'session_ended' && $sessionId) {
            $call = VoiceCall::where('metadata->bridge_session_id', $sessionId)
                ->whereNotIn('status', ['completed', 'declined'])
                ->first();

            if ($call) {
                $this->voiceCallService->endCall($call, $durationSeconds);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Bridge event processed successfully.',
        ]);
    }
}

