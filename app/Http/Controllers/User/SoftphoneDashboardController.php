<?php

namespace App\Http\Controllers\User;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\ServiceResourceAllocation;
use App\Models\VoiceCall;
use App\Services\Communication\IceServerProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SoftphoneDashboardController extends Controller
{
    public function __construct(
        protected VoiceCallServiceInterface $voiceCallService,
        protected IceServerProvider $iceServerProvider
    ) {}

    /**
     * Display the primary unified Softphone dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. Gather all operable lines (Personal + Dedicated Organization Lines)
        $lines = $this->resolveOperableLines($user);

        // 2. Resolve Active Line Context
        $selectedKey = $request->input('line_key', session('active_line_key'));
        $selectedLine = null;

        if ($lines->isNotEmpty()) {
            if ($lines->count() === 1) {
                $selectedLine = $lines->first();
            } else {
                $selectedLine = $lines->firstWhere('key', $selectedKey) ?: $lines->first();
            }
            session(['active_line_key' => $selectedLine['key']]);
        } else {
            session()->forget('active_line_key');
        }

        // 3. Resolve Effective Wallet (Personal or Corporate depending on active line ownership)
        $walletInfo = $this->resolveWalletForLine($user, $selectedLine);

        // 4. Presence status
        $presence = session('softphone_presence', 'online');

        // 5. Recent calls for the active line
        $recentCalls = [];
        if ($selectedLine) {
            $recentCalls = VoiceCall::where(function ($q) use ($user, $selectedLine) {
                if ($selectedLine['type'] === 'number') {
                    $q->where('phone_number_id', $selectedLine['id'])
                      ->orWhere('source_identifier', $selectedLine['number']);
                } else {
                    $q->where('source_identifier', $selectedLine['number'])
                      ->orWhere('destination', $selectedLine['number']);
                }
            })
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'source' => $c->source_identifier,
                'destination' => $c->destination,
                'direction' => $c->direction,
                'status' => $c->status,
                'started_at' => $c->started_at?->format('Y/m/d H:i'),
                'duration_formatted' => sprintf('%02d:%02d', floor($c->duration_seconds / 60), $c->duration_seconds % 60),
                'cost' => (float) $c->cost,
            ]);
        }

        // 6. Recent conversations for active line
        $recentConversations = [];
        if ($selectedLine && $selectedLine['type'] === 'number') {
            $convs = Message::where('phone_number_id', $selectedLine['id'])
                ->whereNotNull('peer_number')
                ->selectRaw('peer_number as peer, max(id) as last_id, count(case when processing_status = "received" and direction = "inbound" then 1 end) as unread_count')
                ->groupBy('peer_number')
                ->orderByDesc('last_id')
                ->limit(10)
                ->get();

            if ($convs->isNotEmpty()) {
                $lastIds = $convs->pluck('last_id')->filter()->all();
                $lastMessages = Message::whereIn('id', $lastIds)->get()->keyBy('id');

                $recentConversations = $convs->map(function ($row) use ($lastMessages) {
                    $lastMsg = $lastMessages->get($row->last_id);
                    return [
                        'peer' => (string) $row->peer,
                        'unread_count' => (int) $row->unread_count,
                        'last_message' => $lastMsg?->body,
                        'last_at' => $lastMsg?->created_at?->diffForHumans(),
                    ];
                })->all();
            }
        }

        // 7. Contacts (Personal + Shared Corporate Contacts)
        $contacts = $this->resolveContacts($user);

        return Inertia::render('User/Softphone/Dashboard', [
            'has_lines' => $lines->isNotEmpty(),
            'lines' => $lines,
            'active_line' => $selectedLine,
            'wallet' => $walletInfo,
            'presence' => $presence,
            'recent_calls' => $recentCalls,
            'recent_conversations' => $recentConversations,
            'contacts' => $contacts,
            'rtc_config' => $this->iceServerProvider->rtcConfigFor($user),
        ]);
    }

    /**
     * Switch the user's active operating line.
     */
    public function switchLine(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'line_key' => ['required', 'string'],
        ]);

        // Check if there is an ongoing active call
        $hasActiveCall = VoiceCall::where('user_id', $user->id)
            ->whereIn('status', ['initiated', 'ringing', 'in_progress'])
            ->exists();

        if ($hasActiveCall) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('softphone.line.cannot_switch_during_call'),
                ], 422);
            }
            return back()->with('error', __('softphone.line.cannot_switch_during_call'));
        }

        $lines = $this->resolveOperableLines($user);
        $target = $lines->firstWhere('key', $validated['line_key']);

        if (! $target) {
            abort(404, __('softphone.line.no_active_line'));
        }

        session(['active_line_key' => $target['key']]);

        $msg = __('softphone.line.switched_successfully', ['line' => $target['display_title']]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'active_line' => $target,
                'message' => $msg,
            ]);
        }

        return back()->with('success', $msg);
    }

    /**
     * Update softphone presence status.
     */
    public function updatePresence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'presence' => ['required', 'string', 'in:online,busy,dnd,offline'],
        ]);

        session(['softphone_presence' => $validated['presence']]);

        return response()->json([
            'success' => true,
            'presence' => $validated['presence'],
        ]);
    }

    /**
     * Resolve all lines the user is authorized to operate.
     */
    protected function resolveOperableLines($user)
    {
        // Personal phone numbers
        $personalNumbers = PhoneNumber::where('user_id', $user->id)
            ->whereNull('organization_id')
            ->where('status', PhoneNumberStatus::Allocated)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'status' => $p->status->value,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
                'is_organization' => false,
                'organization_name' => null,
            ]);

        // Dedicated organization phone number assigned to this user
        $orgNumbers = PhoneNumber::where('assigned_user_id', $user->id)
            ->whereNotNull('organization_id')
            ->where('status', PhoneNumberStatus::Allocated)
            ->with('organization')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'status' => $p->status->value,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
                'is_organization' => true,
                'organization_name' => $p->organization?->name,
            ]);

        // Personal custom IDs
        $personalCustomIds = $user->activeServiceAllocations()
            ->where('service_key', 'custom_id')
            ->whereNull('organization_id')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'status' => 'allocated',
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
                'is_organization' => false,
                'organization_name' => null,
            ]);

        // Dedicated organization custom IDs assigned to this user
        $orgCustomIds = ServiceResourceAllocation::where('assigned_user_id', $user->id)
            ->whereNotNull('organization_id')
            ->where('service_key', 'custom_id')
            ->where('status', 'active')
            ->with('organization')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'status' => 'allocated',
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
                'is_organization' => true,
                'organization_name' => $cid->organization?->name,
            ]);

        return $personalNumbers
            ->concat($orgNumbers)
            ->concat($personalCustomIds)
            ->concat($orgCustomIds)
            ->values();
    }

    /**
     * Resolve effective wallet and balance based on line ownership.
     */
    protected function resolveWalletForLine($user, ?array $line): array
    {
        if (! $line) {
            return [
                'source' => 'personal',
                'balance' => (string) ($user->wallet?->balance ?? '0.0000'),
                'currency' => 'COIN',
                'is_organization' => false,
            ];
        }

        if ($line['is_organization']) {
            if ($line['type'] === 'number') {
                $phone = PhoneNumber::with('organization.wallet')->find($line['id']);
                $orgWallet = $phone?->organization?->wallet;
            } else {
                $cid = ServiceResourceAllocation::with('organization.wallet')->find($line['id']);
                $orgWallet = $cid?->organization?->wallet;
            }

            return [
                'source' => 'organization',
                'balance' => (string) ($orgWallet?->balance ?? '0.0000'),
                'currency' => 'COIN',
                'is_organization' => true,
                'organization_name' => $line['organization_name'],
            ];
        }

        return [
            'source' => 'personal',
            'balance' => (string) ($user->wallet?->balance ?? '0.0000'),
            'currency' => 'COIN',
            'is_organization' => false,
        ];
    }

    /**
     * Resolve user contacts including shared organization directory.
     */
    protected function resolveContacts($user)
    {
        $personal = Contact::where('user_id', $user->id)
            ->where('is_shared', false)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'avatar_color' => $c->avatar_color,
                'notes' => $c->notes,
                'is_shared' => false,
            ]);

        // Get organization IDs where user is member or owner
        $orgIds = $user->ownedOrganizations()->pluck('id')
            ->concat($user->organizations()->pluck('organizations.id'))
            ->unique();

        $shared = Contact::whereIn('organization_id', $orgIds)
            ->where('is_shared', true)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'avatar_color' => $c->avatar_color,
                'notes' => $c->notes,
                'is_shared' => true,
                'shared_label' => __('organization.contacts.shared_badge'),
            ]);

        return $personal->concat($shared)->sortBy('name')->values();
    }
}
