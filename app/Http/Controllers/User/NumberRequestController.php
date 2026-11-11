<?php

namespace App\Http\Controllers\User;

use App\Contracts\NumberRequestServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreNumberRequestRequest;
use App\Models\NumberRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumberRequestController extends Controller
{
    public function __construct(
        protected NumberRequestServiceInterface $requestService
    ) {}

    /**
     * Display a listing of user's service number requests.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

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

        $stats = [
            'total' => NumberRequest::where('user_id', $user->id)->count(),
            'pending' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Pending)->count(),
            'approved' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Approved)->count(),
            'rejected' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Rejected)->count(),
        ];

        $availablePoolNumbers = \App\Models\PhoneNumber::select('id', 'number', 'rond_tier', 'rond_pattern')
            ->where('status', \App\Enums\PhoneNumberStatus::Available)
            ->where('type', \App\Enums\PhoneNumberType::UserService)
            ->orderBy('number')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'length' => strlen($p->number),
                'rond_tier' => $p->rond_tier ?? 'regular',
                'rond_pattern' => $p->rond_pattern,
            ]);

        /** @var \App\Services\Catalog\ServiceRegistry $serviceRegistry */
        $serviceRegistry = app(\App\Contracts\Services\ServiceRegistryInterface::class);
        $serviceCatalog = $serviceRegistry->getCatalogForUser($user, 'enterprise');

        $enterpriseOrders = \App\Models\ServiceOrder::query()
            ->with(['serviceDefinition', 'servicePlan', 'allocation'])
            ->where('user_id', $user->id)
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

        return Inertia::render('User/Requests/Index', [
            'requests' => $requests,
            'service_catalog' => $serviceCatalog,
            'enterprise_orders' => $enterpriseOrders,
            'account_tier' => $user->effectiveServiceTier(),
            'organization_name' => $user->organization_name,
            'enterprise_contract_code' => $user->enterprise_contract_code,
            'filters' => $request->only(['search', 'status']),
            'stats' => $stats,
            'org_tariffs' => \App\Models\SystemSetting::getAllOrgPricing(),
            'wallet' => [
                'balance' => (string) $user->getOrCreateWallet()->balance,
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
                'is_default',
            ]),
        ]);
    }

    /**
     * Submit a new service number request.
     */
    public function store(StoreNumberRequestRequest $request): RedirectResponse
    {
        try {
            $newRequest = $this->requestService->createRequest(
                user: $request->user(),
                requestedLength: (int) $request->input('requested_length'),
                serviceTitle: (string) $request->input('service_title'),
                description: (string) $request->input('description'),
                preferredNumber: $request->input('preferred_number'),
                acceptsIncomingSms: $request->has('accepts_incoming_sms') ? $request->boolean('accepts_incoming_sms') : true,
                phonePrefixId: $request->filled('phone_prefix_id') ? (int) $request->input('phone_prefix_id') : null
            );

            return back()->with('success', __('controllers.user.number_requests.submitted', ['title' => $newRequest->service_title]));
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.user.number_requests.submit_error'));
        }
    }

    /**
     * Cancel a pending service number request.
     */
    public function destroy(Request $request, NumberRequest $numberRequest): RedirectResponse
    {
        $this->authorize('cancel', $numberRequest);

        try {
            $this->requestService->cancelRequest($numberRequest, $request->user());

            return back()->with('success', __('controllers.user.number_requests.cancelled'));
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.user.number_requests.cancel_error'));
        }
    }
}