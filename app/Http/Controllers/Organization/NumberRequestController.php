<?php

namespace App\Http\Controllers\Organization;

use App\Contracts\NumberRequestServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreNumberRequestRequest;
use App\Models\NumberRequest;
use App\Services\Numbers\NumberSearchAndSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumberRequestController extends Controller
{
    public function __construct(
        protected NumberRequestServiceInterface $requestService,
        protected NumberSearchAndSuggestionService $searchService
    ) {}

    /**
     * Display a listing of organization's service number requests (4 to 8 digits & short IDs) & Enterprise Hub.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $search = $request->filled('search') ? trim($request->input('search')) : null;
        $statusFilter = $request->filled('status') ? $request->input('status') : null;

        // 1. Query NumberRequests
        $nrQuery = NumberRequest::with(['allocatedNumber.prefix', 'reviewer', 'prefix'])
            ->where('user_id', $user->id);

        if ($search) {
            $nrQuery->where(function ($q) use ($search) {
                $q->where('service_title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('preferred_number', 'like', "%{$search}%");
            });
        }

        if ($statusFilter) {
            $nrQuery->where('status', $statusFilter);
        }

        $nrItems = $nrQuery->get()->map(function ($req) {
            return [
                'id' => 'nr_' . $req->id,
                'raw_id' => $req->id,
                'item_type' => 'number_request',
                'order_number' => 'REQ-' . str_pad((string) $req->id, 5, '0', STR_PAD_LEFT),
                'phone_prefix_id' => $req->phone_prefix_id,
                'prefix' => $req->prefix ? [
                    'id' => $req->prefix->id,
                    'prefix' => $req->prefix->prefix,
                    'country_name' => $req->prefix->country_name ?? $req->prefix->name,
                    'flag' => $req->prefix->flag ?? '🌐',
                ] : null,
                'requested_length' => $req->requested_length,
                'service_title' => $req->service_title,
                'description' => $req->description,
                'preferred_number' => $req->preferred_number,
                'requested_identifier' => $req->preferred_number ?: ($req->requested_length ? $req->requested_length . ' رقمی' : null),
                'is_rond' => (bool) $req->is_rond,
                'rond_tier' => $req->rond_tier ?? 'regular',
                'held_amount' => (string) ($req->held_amount ?? '0.0000'),
                'hold_reference_id' => $req->hold_reference_id,
                'accepts_incoming_sms' => (bool) $req->accepts_incoming_sms,
                'status' => $req->status->value,
                'status_label' => $req->status->label(),
                'status_badge' => $req->status->badgeClasses(),
                'rejection_reason' => $req->rejection_reason,
                'allocated_number' => $req->allocatedNumber ? [
                    'id' => $req->allocatedNumber->id,
                    'number' => $req->allocatedNumber->number,
                    'formatted_number' => $req->allocatedNumber->formatted_number,
                ] : null,
                'reviewer_name' => $req->reviewer?->name,
                'reviewed_at' => $req->reviewed_at?->format('Y/m/d H:i'),
                'created_at' => $req->created_at->format('Y/m/d H:i'),
                'raw_created_at' => $req->created_at->timestamp,
            ];
        });

        // 2. Query ServiceOrders (Enterprise multi-service orders: shortcodes, custom IDs, domains)
        $soQuery = \App\Models\ServiceOrder::query()
            ->with(['serviceDefinition', 'servicePlan', 'allocation'])
            ->where('user_id', $user->id);

        if ($search) {
            $soQuery->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('requested_identifier', 'like', "%{$search}%")
                    ->orWhereHas('serviceDefinition', function ($sub) use ($search) {
                        $sub->where('title', 'like', "%{$search}%");
                    });
            });
        }

        if ($statusFilter) {
            if ($statusFilter === 'approved') {
                $soQuery->whereIn('status', ['completed', 'fulfilled', 'approved', 'active']);
            } elseif ($statusFilter === 'pending') {
                $soQuery->whereIn('status', ['pending', 'pending_approval', 'held', 'reviewing']);
            } elseif ($statusFilter === 'rejected') {
                $soQuery->whereIn('status', ['rejected', 'cancelled', 'failed']);
            }
        }

        $soItems = $soQuery->get()->map(function ($order) {
            $isApproved = in_array($order->status, ['completed', 'fulfilled', 'approved', 'active']);
            $isRejected = in_array($order->status, ['rejected', 'cancelled', 'failed']);

            $normalizedStatus = $isApproved ? 'approved' : ($isRejected ? 'rejected' : 'pending');
            $statusLabel = match ($normalizedStatus) {
                'approved' => __('user.requests.stat_approved'),
                'rejected' => __('user.requests.stat_rejected'),
                default => __('user.requests.stat_pending'),
            };
            $statusBadge = match ($normalizedStatus) {
                'approved' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
                'rejected' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
                default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
            };

            $allocatedDisplay = null;
            if ($isApproved && $order->requested_identifier) {
                $allocatedDisplay = [
                    'id' => $order->id,
                    'number' => $order->requested_identifier,
                    'formatted_number' => $order->requested_identifier,
                ];
            }

            return [
                'id' => 'so_' . $order->id,
                'raw_id' => $order->id,
                'item_type' => 'service_order',
                'order_number' => $order->order_number,
                'phone_prefix_id' => null,
                'prefix' => null,
                'requested_length' => null,
                'service_title' => $order->serviceDefinition?->title ?: __('catalog.service'),
                'description' => $order->servicePlan?->title ? ($order->serviceDefinition?->title . ' - ' . $order->servicePlan->title) : null,
                'preferred_number' => $order->requested_identifier,
                'requested_identifier' => $order->requested_identifier,
                'is_rond' => false,
                'rond_tier' => 'regular',
                'held_amount' => (string) ($order->final_amount ?: '0.0000'),
                'hold_reference_id' => $order->wallet_reference_id,
                'accepts_incoming_sms' => true,
                'status' => $normalizedStatus,
                'status_label' => $statusLabel,
                'status_badge' => $statusBadge,
                'rejection_reason' => $order->rejection_reason,
                'allocated_number' => $allocatedDisplay,
                'reviewer_name' => null,
                'reviewed_at' => $order->reviewed_at?->format('Y/m/d H:i') ?: $order->fulfilled_at?->format('Y/m/d H:i'),
                'created_at' => $order->created_at?->format('Y/m/d H:i') ?: now()->format('Y/m/d H:i'),
                'raw_created_at' => $order->created_at?->timestamp ?: time(),
            ];
        });

        // 3. Merge & Sort by newest first
        $allMerged = $nrItems->concat($soItems)->sortByDesc('raw_created_at')->values();

        // 4. Pagination with LengthAwarePaginator
        $page = (int) $request->input('page', 1);
        $perPage = 10;
        $sliced = $allMerged->slice(($page - 1) * $perPage, $perPage)->values();

        $requests = new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced,
            $allMerged->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $nrPending = NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Pending)->count();
        $nrApproved = NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Approved)->count();
        $nrRejected = NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Rejected)->count();

        $soPending = \App\Models\ServiceOrder::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'pending_approval', 'held', 'reviewing'])
            ->count();
        $soApproved = \App\Models\ServiceOrder::where('user_id', $user->id)
            ->whereIn('status', ['completed', 'fulfilled', 'approved', 'active'])
            ->count();
        $soRejected = \App\Models\ServiceOrder::where('user_id', $user->id)
            ->whereIn('status', ['rejected', 'cancelled', 'failed'])
            ->count();

        $stats = [
            'total' => ($nrPending + $nrApproved + $nrRejected) + ($soPending + $soApproved + $soRejected),
            'pending' => $nrPending + $soPending,
            'approved' => $nrApproved + $soApproved,
            'rejected' => $nrRejected + $soRejected,
        ];

        // Rich shortcode suggestions across all lengths (4 to 8 digits)
        $availablePoolNumbers = [];
        foreach ([4, 5, 6, 7, 8] as $len) {
            $items = $this->searchService->getShortcodeSuggestions(
                prefixId: null,
                length: $len,
                seedNumber: null,
                limit: 8
            );
            foreach ($items as $idx => $item) {
                $availablePoolNumbers[] = [
                    'id' => ($len * 100) + $idx,
                    'number' => $item['number'],
                    'formatted_number' => $item['formatted_number'],
                    'length' => $len,
                    'rond_tier' => $item['rond_tier'] ?? 'regular',
                    'rond_tier_label' => $item['rond_tier_label'] ?? null,
                    'rond_pattern' => $item['rond_pattern'] ?? null,
                    'is_rond' => $item['is_rond'] ?? false,
                    'price' => $item['price'] ?? null,
                    'source' => $item['source'] ?? 'smart',
                ];
            }
        }

        /** @var \App\Services\Catalog\ServiceRegistry $serviceRegistry */
        $serviceRegistry = app(\App\Contracts\Services\ServiceRegistryInterface::class);
        $serviceCatalog = $serviceRegistry->getCatalogForUser($user, 'enterprise');

        $wallet = $org->getOrCreateWallet();

        return Inertia::render('Organization/Requests/Index', [
            'requests' => $requests,
            'service_catalog' => $serviceCatalog,
            'account_tier' => 'enterprise',
            'organization_name' => $org->name,
            'enterprise_contract_code' => $user->enterprise_contract_code,
            'filters' => $request->only(['search', 'status']),
            'stats' => $stats,
            'org_tariffs' => \App\Models\SystemSetting::getAllOrgPricing(),
            'wallet' => [
                'balance' => (string) $wallet->balance,
                'currency' => $wallet->currency,
            ],
            'available_pool_numbers' => $availablePoolNumbers,
            'active_prefixes' => \App\Models\PhonePrefix::active()->get([
                'id',
                'prefix',
                'clean_prefix',
                'name',
                'country_name',
                'country_code',
                'flag',
                'sms_price_per_segment',
                'is_default',
            ]),
        ]);
    }

    /**
     * Check real-time availability of an Enterprise Shortcode (4..8 digits) and return smart suggestions.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $prefixId = $request->input('prefix_id') ?? $request->input('phone_prefix_id');
        $length = (int) ($request->input('requested_length') ?? $request->input('length') ?? 0);
        $number = trim((string) ($request->input('preferred_number') ?? $request->input('number') ?? ''));

        if ($length < 4 || $length > 9) {
            $length = strlen($number) >= 4 && strlen($number) <= 9 ? strlen($number) : 4;
        }

        $result = $this->searchService->checkAndSuggestShortcode(
            prefixId: $prefixId ? (int) $prefixId : null,
            length: $length,
            preferredNumber: $number
        );

        return response()->json($result);
    }

    /**
     * Fetch smart shortcode suggestions for a given digit length and prefix.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $length = (int) ($request->input('requested_length') ?? $request->input('length') ?? 4);
        $prefixId = $request->input('phone_prefix_id') ?? $request->input('prefix_id');
        $seed = $request->input('seed') ?? $request->input('number');

        $suggestions = $this->searchService->getShortcodeSuggestions(
            prefixId: $prefixId ? (int) $prefixId : null,
            length: $length,
            seedNumber: $seed,
            limit: 12
        );

        return response()->json([
            'success' => true,
            'length' => $length,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Store a newly created service number request in storage.
     */
    public function store(StoreNumberRequestRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $numberRequest = $this->requestService->createRequest(
                user: $user,
                validatedData: $request->validated()
            );

            return redirect()->route('org.requests.index')
                ->with('success', __('controllers.user.number_requests.request_registered_success', ['id' => $numberRequest->id]));
        } catch (NumberAllocationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage (cancel pending request).
     */
    public function destroy(Request $request, NumberRequest $numberRequest): RedirectResponse
    {
        if ($numberRequest->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        try {
            $this->requestService->cancelRequest($numberRequest, $request->user());

            return redirect()->route('org.requests.index')
                ->with('success', __('controllers.user.number_requests.request_cancelled_success'));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
