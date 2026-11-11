<?php

namespace App\Http\Controllers\User;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\AllocatePhoneNumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Services\Numbers\NumberSearchAndSuggestionService;
use App\Services\Numbers\RondPatternDetectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PhoneNumberController extends Controller
{
    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService,
        protected WalletServiceInterface $walletService,
        protected NumberSearchAndSuggestionService $searchService,
        protected RondPatternDetectorService $rondDetector
    ) {}

    /**
     * Display a listing of user's allocated phone numbers.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = PhoneNumber::with('prefix')->where('user_id', $user->id);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('display_title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $numbers = $query->latest('allocated_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($phone) => [
                'id' => $phone->id,
                'number' => $phone->number,
                'formatted_number' => $phone->formatted_number,
                'effective_title' => $phone->effective_title,
                'display_title' => $phone->display_title,
                'rond_tier' => $phone->rond_tier ?? 'regular',
                'rond_tier_label' => $phone->rond_tier_label ?? __('controllers.user.phone_numbers.rond_default'),
                'rond_pattern' => $phone->rond_pattern,
                'type' => $phone->type->value,
                'type_label' => $phone->type->label(),
                'type_badge' => $phone->type->badgeClasses(),
                'status' => $phone->status->value,
                'status_label' => $phone->status->label(),
                'status_badge' => $phone->status->badgeClasses(),
                'allocated_at' => $phone->allocated_at?->format('Y/m/d H:i'),
                'created_at' => $phone->created_at->format('Y/m/d'),
            ]);

        $activeCount = app(\App\Contracts\SystemCacheServiceInterface::class)->getUserActiveNumbersCount($user->id);

        /** @var \App\Services\Catalog\ServiceRegistry $serviceRegistry */
        $serviceRegistry = app(\App\Contracts\Services\ServiceRegistryInterface::class);
        $serviceRegistry->syncUserLegacyPhoneAllocations($user);
        $serviceCatalog = $serviceRegistry->getCatalogForUser($user, 'services');

        $serviceAllocations = \App\Models\ServiceResourceAllocation::query()
            ->with(['subResources', 'serviceDefinition'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('allocated_at')
            ->get()
            ->map(fn (\App\Models\ServiceResourceAllocation $item) => [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'service_key' => $item->service_key,
                'service_title' => $item->serviceDefinition?->title ?? $item->service_key,
                'channel_type' => $item->channel_type,
                'resource_identifier' => $item->resource_identifier,
                'display_title' => $item->display_title ?: $item->resource_identifier,
                'account_tier' => $item->account_tier,
                'tier_badge' => $item->tier_badge,
                'tier_label' => __('catalog.tiers_badges.' . $item->tier_badge),
                'total_quota' => $item->total_quota,
                'used_quota' => $item->used_quota,
                'remaining_quota' => $item->remainingQuota(),
                'phone_number_id' => $item->phone_number_id,
                'capabilities' => $item->capabilities ?? [],
                'metadata' => $item->metadata ?? [],
                'sub_resources' => $item->subResources->map(fn ($sub) => [
                    'id' => $sub->id,
                    'sub_identifier' => $sub->sub_identifier,
                    'full_address' => $sub->full_address,
                    'display_name' => $sub->display_name,
                    'status' => $sub->status,
                ])->values()->all(),
                'allocated_at' => $item->allocated_at?->format('Y/m/d H:i'),
            ]);

        return Inertia::render('User/Numbers/Index', [
            'numbers' => $numbers,
            'service_catalog' => $serviceCatalog,
            'service_allocations' => $serviceAllocations,
            'account_tier' => $user->effectiveServiceTier(),
            'organization_name' => $user->organization_name,
            'filters' => $request->only(['search', 'type', 'service_key']),
            'quota' => [
                'active' => $activeCount,
                'max' => (int) \App\Models\SystemSetting::get('max_lines_per_user', \App\Services\PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER),
            ],
            'wallet' => [
                'balance' => (string) $user->getOrCreateWallet()->balance,
                'number_price_coin' => SystemSetting::getNumberPriceCoin(),
            ],
            'default_prefix' => \App\Models\PhonePrefix::getDefaultPrefixString(),
            'active_prefixes' => \App\Models\PhonePrefix::active()->get([
                'id',
                'prefix',
                'clean_prefix',
                'name',
                'country_name',
                'country_code',
                'flag',
                'sub_prefixes',
                'price_regular',
                'price_semi_rond',
                'price_golden_rond',
                'is_default',
            ]),
        ]);
    }

    /**
     * Check real-time availability and retrieve suggestions for a 9-digit line.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefix_id' => ['required', 'integer', 'exists:phone_prefixes,id'],
            'sub_prefix' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
            'suffix' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $result = $this->searchService->checkAndSuggest(
            prefixId: (int) $validated['prefix_id'],
            subPrefix: $validated['sub_prefix'],
            suffix: $validated['suffix'],
            user: $request->user()
        );

        return response()->json($result);
    }

    /**
     * Retrieve suggested free numbers from pool for a given prefix and sub-prefix.
     */
    public function poolSuggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prefix_id' => ['required', 'integer', 'exists:phone_prefixes,id'],
            'sub_prefix' => ['nullable', 'string', 'regex:/^[0-9]{3}$/'],
            'suffix_prefix' => ['nullable', 'string', 'max:6'],
        ]);

        $suggestions = $this->searchService->getPoolSuggestions(
            prefixId: (int) $validated['prefix_id'],
            subPrefix: $validated['sub_prefix'] ?? null,
            suffixPrefix: $validated['suffix_prefix'] ?? null,
            limit: 6
        );

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Allocate a new standard number for the authenticated user with atomic wallet charging.
     */
    public function store(AllocatePhoneNumberRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        // 1. Resolve effective prefix
        $prefix = null;
        if ($request->filled('phone_prefix_id')) {
            $prefix = PhonePrefix::active()->find($request->input('phone_prefix_id'));
        }
        $effectivePrefix = $prefix ?? PhonePrefix::getDefaultPrefix();

        // 2. Resolve preferred number and calculate dynamic pricing based on rond tier
        $preferredNumber = $request->validated('preferred_number');
        $displayTitle = $request->validated('display_title'); // optional/null for 9-digit lines

        if ($preferredNumber !== null && trim($preferredNumber) !== '') {
            $cleanNumber = preg_replace('/\D/', '', PhonePrefix::stripPrefix($preferredNumber));
            $rond = $this->rondDetector->detect($cleanNumber);
            $numberPrice = $effectivePrefix->getPriceForTier($rond['tier']);
            $rondTier = $rond['tier'];
            $rondPattern = $rond['pattern'];
        } else {
            $numberPrice = (string) ($effectivePrefix->price_regular ?? SystemSetting::getNumberPriceCoin());
            $rondTier = 'regular';
            $rondPattern = __('controllers.user.phone_numbers.standard_pattern');
        }

        $purchaseRef = 'PURCHASE-' . Str::uuid();

        try {
            // 3. Atomically charge wallet
            $tx = $this->walletService->chargeForNumberPurchase(
                user: $user,
                amount: $numberPrice,
                referenceId: $purchaseRef,
                description: __('controllers.user.phone_numbers.ledger_desc_purchase', ['prefix' => $effectivePrefix->prefix, 'price' => $numberPrice]),
                metadata: [
                    'prefix_id' => $effectivePrefix->id,
                    'preferred_number' => $preferredNumber,
                    'rond_tier' => $rondTier,
                    'rond_pattern' => $rondPattern,
                ]
            );

            // 4. Allocate number
            try {
                $phone = $this->allocationService->allocateStandardNumberToUser(
                    user: $user,
                    preferredNumber: $preferredNumber,
                    displayTitle: $displayTitle,
                    actor: $user,
                    prefix: $effectivePrefix
                );

                $tx->update([
                    'metadata' => array_merge($tx->metadata ?? [], [
                        'phone_number_id' => $phone->id,
                        'number' => $phone->number,
                    ]),
                ]);

                if ($request->wantsJson()) {
                    return response()->json([
                        'message' => __('controllers.user.phone_numbers.allocated_api_msg', ['number' => $phone->formatted_number]),
                        'phone' => $phone,
                    ]);
                }

                return back()->with('success', __('controllers.user.phone_numbers.allocated_flash_msg', ['number' => $phone->formatted_number, 'price' => $numberPrice]));
            } catch (\Throwable $allocationException) {
                // In case allocation fails, automatically refund coins into the ledger
                $this->walletService->refund(
                    user: $user,
                    amount: $numberPrice,
                    referenceId: 'REFUND-' . $purchaseRef,
                    description: __('controllers.user.phone_numbers.refund_ledger_desc')
                );

                throw $allocationException;
            }
        } catch (InsufficientWalletBalanceException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['wallet' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()->route('wallet.index')->with('error', $e->getMessage() . __('controllers.user.phone_numbers.insufficient_balance_hint', ['price' => $numberPrice]));
        } catch (NumberAllocationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('controllers.user.phone_numbers.system_error')], 500);
            }

            return back()->with('error', __('controllers.user.phone_numbers.allocation_failed_refunded'));
        }
    }

    /**
     * Update display title of an allocated phone number.
     */
    public function update(Request $request, PhoneNumber $number): RedirectResponse
    {
        $this->authorize('update', $number);

        $validated = $request->validate([
            'display_title' => ['nullable', 'string', 'max:100'],
        ], [
            'display_title.max' => __('controllers.user.phone_numbers.val_title_max'),
        ]);

        $number->update([
            'display_title' => $validated['display_title'] ? trim($validated['display_title']) : null,
        ]);

        return back()->with('success', __('controllers.user.phone_numbers.title_updated'));
    }

    /**
     * Release user's allocated number back to the available pool.
     */
    public function destroy(Request $request, PhoneNumber $number): RedirectResponse
    {
        $this->authorize('release', $number);

        try {
            $this->allocationService->releaseNumber(
                phoneNumber: $number,
                actor: $request->user(),
                reason: __('controllers.user.phone_numbers.release_reason')
            );

            return back()->with('success', __('controllers.user.phone_numbers.released_success', ['number' => $number->formatted_number]));
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.user.phone_numbers.release_failed'));
        }
    }
}