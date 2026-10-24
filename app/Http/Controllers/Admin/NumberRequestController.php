<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\NumberRequestServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveNumberRequestRequest;
use App\Http\Requests\Admin\RejectNumberRequestRequest;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
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
     * Display a listing of all requests in admin panel.
     */
    public function index(Request $request): Response
    {
        $query = NumberRequest::with(['user', 'allocatedNumber.prefix', 'reviewer']);


        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('service_title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('preferred_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('username', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('length')) {
            $query->where('requested_length', (int) $request->input('length'));
        }

        $requests = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($req) => [
                'id' => $req->id,
                'user' => [
                    'id' => $req->user->id,
                    'name' => $req->user->name,
                    'username' => $req->user->username,
                ],
                'requested_length' => $req->requested_length,
                'service_title' => $req->service_title,
                'description' => $req->description,
                'preferred_number' => $req->preferred_number,
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
            'total' => NumberRequest::count(),
            'pending' => NumberRequest::where('status', NumberRequestStatus::Pending)->count(),
            'approved' => NumberRequest::where('status', NumberRequestStatus::Approved)->count(),
            'rejected' => NumberRequest::where('status', NumberRequestStatus::Rejected)->count(),
        ];

        // Available service numbers in pool that can be selected
        $availableServiceNumbers = PhoneNumber::select('id', 'number', 'type')
            ->where('status', PhoneNumberStatus::Available)
            ->where('type', PhoneNumberType::UserService)
            ->orderBy('number')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'length' => strlen($p->number),
            ]);

        return Inertia::render('Admin/Requests/Index', [
            'requests' => $requests,
            'filters' => $request->only(['search', 'status', 'length']),
            'stats' => $stats,
            'availableServiceNumbers' => $availableServiceNumbers,
        ]);
    }

    /**
     * Approve a service number request and allocate number.
     */
    public function approve(ApproveNumberRequestRequest $request, NumberRequest $numberRequest): RedirectResponse
    {
        $this->authorize('review', $numberRequest);

        try {
            $updated = $this->requestService->approveRequest(
                request: $numberRequest,
                admin: $request->user(),
                mode: $request->input('mode', 'auto'),
                customNumber: $request->input('custom_number'),
                poolNumberId: $request->filled('pool_number_id') ? (int) $request->input('pool_number_id') : null
            );

            return back()->with(
                'success',
                "درخواست سرشماره «{$updated->service_title}» با موفقیت تأیید و خط سازمانی {$updated->allocatedNumber?->number} به کاربر اختصاص یافت."
            );
        } catch (NumberAllocationException $e) {
            return back()->withErrors([
                'custom_number' => $e->getMessage(),
                'pool_number_id' => $e->getMessage(),
                'general' => $e->getMessage(),
            ])->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->withErrors([
                'general' => 'خطا در فرآیند تأیید درخواست. لطفاً مجدداً اقدام فرمایید.',
            ])->with('error', 'خطا در فرآیند تأیید درخواست. لطفاً مجدداً اقدام فرمایید.');
        }
    }

    /**
     * Reject a service number request with reason.
     */
    public function reject(RejectNumberRequestRequest $request, NumberRequest $numberRequest): RedirectResponse
    {
        $this->authorize('review', $numberRequest);

        try {
            $this->requestService->rejectRequest(
                request: $numberRequest,
                admin: $request->user(),
                reason: (string) $request->input('reason')
            );

            return back()->with('success', "درخواست سرشماره «{$numberRequest->service_title}» با درج دلیل عدم تأیید، رد گردید.");
        } catch (NumberAllocationException $e) {
            return back()->withErrors([
                'reason' => $e->getMessage(),
                'general' => $e->getMessage(),
            ])->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->withErrors([
                'general' => 'خطا در فرآیند رد درخواست.',
            ])->with('error', 'خطا در فرآیند رد درخواست.');
        }
    }
}
