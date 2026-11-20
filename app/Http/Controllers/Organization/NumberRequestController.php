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

        $query = NumberRequest::with(['allocatedNumber.prefix', 'reviewer', 'prefix'])
            ->where('user_id', $user->id);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('service_title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('preferred_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $requests = $query->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($req) => [
                'id' => $req->id,
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
            ]);

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

        $soQuery = \App\Models\ServiceOrder::query()
            ->with(['serviceDefinition', 'servicePlan', 'allocation'])
            ->where('user_id', $user->id);

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'approved') {
                $soQuery->whereIn('status', ['completed', 'fulfilled', 'approved', 'active']);
            } elseif ($status === 'pending') {
                $soQuery->whereIn('status', ['pending', 'pending_approval', 'held', 'reviewing']);
            } elseif ($status === 'rejected') {
                $soQuery->whereIn('status', ['rejected', 'cancelled', 'failed']);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $soQuery->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('requested_identifier', 'like', "%{$search}%")
                    ->orWhereHas('serviceDefinition', function ($sub) use ($search) {
                        $sub->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $enterpriseOrders = $soQuery
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (\App\Models\ServiceOrder $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'service_key' => $order->serviceDefinition?->key,
                'service_title' => $order->serviceDefinition?->title,
                'plan_title' => $order->servicePlan?->title,
                'requested_identifier' => $order->requested_identifier,
                'account_tier' => $order->account_tier,
                'order_context' => $order->order_context,
                'fulfillment_mode' => $order->fulfillment_mode,
                'status' => $order->status,
                'unit_price' => (string) $order->unit_price,
                'discount_amount' => (string) $order->discount_amount,
                'final_amount' => (string) $order->final_amount,
                'created_at' => $order->created_at?->format('Y/m/d H:i'),
            ]);

        $wallet = $org->getOrCreateWallet();

        return Inertia::render('Organization/Requests/Index', [
            'requests' => $requests,
            'service_catalog' => $serviceCatalog,
            'enterprise_orders' => $enterpriseOrders,
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
