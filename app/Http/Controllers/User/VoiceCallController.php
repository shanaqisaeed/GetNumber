<?php

namespace App\Http\Controllers\User;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\VoiceCall;
use App\Models\VoiceCallSignal;
use App\Services\Communication\IceServerProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceCallController extends Controller
{
    public function __construct(
        protected VoiceCallServiceInterface $voiceCallService,
        protected IceServerProvider $iceServerProvider
    ) {}

    /**
     * Display the full Voice Calling, Call History, and Contacts page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $allLines = \App\Http\Controllers\User\SoftphoneDashboardController::resolveOperableLines($user);

        $selectedKey = $request->input('line_key');
        if (! $selectedKey) {
            if ($request->filled('phone_number_id')) {
                $selectedKey = 'phone_' . (int) $request->input('phone_number_id');
            } elseif ($request->filled('custom_id')) {
                $matchedCid = $allLines->firstWhere('number', $request->input('custom_id'));
                if ($matchedCid) {
                    $selectedKey = $matchedCid['key'];
                }
            } else {
                $selectedKey = session('active_line_key');
            }
        }

        $selectedLine = $allLines->firstWhere('key', $selectedKey) ?: $allLines->first();
        if ($selectedLine) {
            session(['active_line_key' => $selectedLine['key']]);
        }
        $selectedLineKey = $selectedLine ? $selectedLine['key'] : null;
        $selectedLineId = $selectedLine ? $selectedLine['id'] : null;

        $initialDestination = $request->input('destination', '');

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
            'active_line' => $selectedLine,
            'selected_line_key' => $selectedLineKey,
            'selected_line_id' => $selectedLineId,
            'initial_destination' => $initialDestination,
            'calls' => $calls,
            'contacts' => $contacts,
            'rate_per_minute' => $ratePerMinute,
            'wallet_balance' => $walletBalance,
            'currency' => 'COIN',
            'rtc_config' => $this->iceServerProvider->rtcConfigFor($user),
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
            'name.required' => __('controllers.user.calls.val_contact_name_required'),
            'phone_number.required' => __('controllers.user.calls.val_contact_phone_required'),
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
                'message' => __('controllers.user.calls.contact_added'),
            ], 201);
        }

        return back()->with('success', __('controllers.user.calls.contact_saved'));
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
                'message' => __('controllers.user.calls.contact_deleted'),
            ]);
        }

        return back()->with('success', __('controllers.user.calls.contact_deleted_flash'));
    }

    /**
     * Get user's active lines and current voice call rate.
     */
    public function lines(Request $request): JsonResponse
    {
        $user = $request->user();
        $lines = \App\Http\Controllers\User\SoftphoneDashboardController::resolveOperableLines($user);

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
            'destination.required' => __('controllers.user.calls.val_destination_required'),
        ]);

        $user = $request->user();
        $lineKey = $request->input('line_key');
        $fromNumber = null;
        $customCallerHandle = null;

        if ($lineKey) {
            if (str_starts_with($lineKey, 'phone_')) {
                $pid = (int) substr($lineKey, 6);
                $fromNumber = PhoneNumber::where(function ($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('assigned_user_id', $user->id);
                    })
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
                        ?? PhoneNumber::where(function ($q) use ($user) {
                            $q->where('user_id', $user->id)
                              ->orWhere('assigned_user_id', $user->id);
                        })->where('status', PhoneNumberStatus::Allocated)->first();
                }
            }
        }

        if (! $fromNumber && $request->filled('phone_number_id')) {
            $lineId = (int) $request->input('phone_number_id');
            $fromNumber = PhoneNumber::where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('assigned_user_id', $user->id);
                })
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
                        ?? PhoneNumber::where(function ($q) use ($user) {
                            $q->where('user_id', $user->id)
                              ->orWhere('assigned_user_id', $user->id);
                        })->where('status', PhoneNumberStatus::Allocated)->first();
                }
            }
        }

        if (! $fromNumber) {
            return response()->json([
                'success' => false,
                'message' => __('controllers.user.calls.source_line_invalid'),
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
                    'rtc_config' => $this->iceServerProvider->rtcConfigFor($user),
                ],
                'message' => __('controllers.user.calls.initiated'),
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
                'message' => __('controllers.user.calls.initiate_failed', ['error' => $e->getMessage()]),
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
            'message' => __('controllers.user.calls.answered'),
            'call' => [
                'id' => $voiceCall->id,
                'source' => $voiceCall->source_identifier,
                'destination' => $voiceCall->destination,
                'status' => $voiceCall->status,
                'answered_at' => $voiceCall->answered_at?->toIso8601String(),
                'bridge_session_id' => $voiceCall->metadata['bridge_session_id'] ?? null,
                'bridge_ws_endpoint' => $voiceCall->metadata['bridge_ws_endpoint'] ?? null,
                'ping_ms' => $voiceCall->metadata['ping_ms'] ?? 22,
                'is_internal' => $voiceCall->metadata['is_internal'] ?? true,
                'rtc_config' => $this->iceServerProvider->rtcConfigFor($user),
            ],
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
            'message' => __('controllers.user.calls.declined'),
        ]);
    }

    /**
     * End an active call session.
     */
    public function end(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $voiceCall->user_id !== $user->id) {
            abort(403, __('controllers.user.calls.access_denied'));
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
            'message' => __('controllers.user.calls.ended_with_cost', ['cost' => $endedCall->cost]),
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
                'message' => __('controllers.user.calls.already_ended'),
            ]);
        }

        $rate = (float) $voiceCall->rate_per_minute;

        // Resolve effective wallet: Organization wallet if line belongs to company, else personal wallet
        $phone = $voiceCall->phoneNumber;
        $isOrgLine = $phone && ($phone->is_organization || $phone->organization_id);
        $walletBalance = '0.0000';

        if ($isOrgLine && $phone->organization) {
            $orgWallet = $phone->organization->getOrCreateWallet();
            $walletBalance = (string) $orgWallet->balance;
        } else {
            $walletBalance = (string) ($user->wallet?->balance ?? '0.0000');
        }

        if ($rate <= 0) {
            return response()->json([
                'active' => true,
                'can_continue' => true,
                'is_internal' => (bool) ($voiceCall->metadata['is_internal'] ?? false),
                'rate_per_minute' => 0.0000,
                'wallet_balance' => $walletBalance,
            ]);
        }

        $balance = (float) $walletBalance;
        $elapsedSec = (int) $request->input('elapsed_seconds', $request->input('duration_seconds', 0));

        if ($balance < $rate) {
            $meta = $voiceCall->metadata ?? [];
            $warnedAt = $meta['low_balance_warned_at'] ?? null;

            if (! $warnedAt) {
                // Issue authoritative 1-minute grace warning
                $meta['low_balance_warned_at'] = time();
                $voiceCall->metadata = $meta;
                $voiceCall->save();

                return response()->json([
                    'active' => true,
                    'can_continue' => true,
                    'low_balance_warning' => true,
                    'warning_title' => __('softphone.balance.low_balance_warning_title'),
                    'warning_message' => __('softphone.balance.low_balance_warning_desc'),
                    'seconds_remaining' => 60,
                    'rate_per_minute' => $rate,
                    'wallet_balance' => $walletBalance,
                ]);
            }

            // If 60 seconds have elapsed since warning, terminate call
            if ((time() - (int) $warnedAt) >= 60) {
                $endedCall = $this->voiceCallService->endCall($voiceCall, max(1, $elapsedSec));

                return response()->json([
                    'active' => false,
                    'can_continue' => false,
                    'auto_terminated' => true,
                    'reason' => 'insufficient_balance',
                    'cost' => (float) $endedCall->cost,
                    'wallet_balance' => $walletBalance,
                    'message' => __('controllers.user.calls.insufficient_balance_ended'),
                ]);
            }

            $remainingGrace = max(1, 60 - (time() - (int) $warnedAt));

            return response()->json([
                'active' => true,
                'can_continue' => true,
                'low_balance_warning' => true,
                'warning_title' => __('softphone.balance.low_balance_warning_title'),
                'warning_message' => __('softphone.balance.low_balance_warning_desc'),
                'seconds_remaining' => $remainingGrace,
                'rate_per_minute' => $rate,
                'wallet_balance' => $walletBalance,
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
            'type' => ['required', 'string', 'in:offer,answer,ice_candidate,end_call,renegotiate'],
            'payload' => ['required'],
        ]);

        $peerCallId = $voiceCall->metadata['callee_call_id'] ?? $voiceCall->metadata['peer_call_id'] ?? null;
        if (! $peerCallId) {
            return response()->json(['success' => false, 'message' => __('controllers.user.calls.peer_not_found')], 404);
        }

        // Atomic append-only insert in database mailbox (prevents Cache read-modify-write race conditions)
        $signalRecord = VoiceCallSignal::create([
            'voice_call_id' => $peerCallId,
            'sender_call_id' => $voiceCall->id,
            'type' => $validated['type'],
            'payload' => $validated['payload'],
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'signal_id' => $signalRecord->id,
        ]);
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

        $afterId = (int) $request->input('after_id', 0);

        $query = VoiceCallSignal::where('voice_call_id', $voiceCall->id)
            ->orderBy('id');

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->limit(50)->get();

        // If client did not supply cursor (legacy / single-shot callers), delete retrieved rows like pull()
        if (! $request->has('after_id') && $rows->isNotEmpty()) {
            VoiceCallSignal::whereIn('id', $rows->pluck('id'))->delete();
        }

        $signals = $rows->map(fn (VoiceCallSignal $row) => [
            'id' => $row->id,
            'type' => $row->type,
            'payload' => $row->payload,
            'sender_call_id' => $row->sender_call_id,
            'timestamp' => $row->created_at?->getTimestamp() ?? time(),
        ])->values()->all();

        $lastId = $rows->isNotEmpty() ? (int) $rows->last()->id : $afterId;
        $isActive = in_array($voiceCall->status, ['in_call', 'ringing']);

        return response()->json([
            'success' => true,
            'status' => $voiceCall->status,
            'is_active' => $isActive,
            'last_signal_id' => $lastId,
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
                return response()->json(['error' => __('controllers.user.calls.unauthorized_bridge_token')], 401);
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
            'message' => __('controllers.user.calls.bridge_event_processed'),
        ]);
    }
}

