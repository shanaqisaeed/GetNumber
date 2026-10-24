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

        $query = NumberRequest::with(['allocatedNumber.prefix', 'reviewer'])
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
            'total' => NumberRequest::where('user_id', $user->id)->count(),
            'pending' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Pending)->count(),
            'approved' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Approved)->count(),
            'rejected' => NumberRequest::where('user_id', $user->id)->where('status', NumberRequestStatus::Rejected)->count(),
        ];

        return Inertia::render('User/Requests/Index', [
            'requests' => $requests,
            'filters' => $request->only(['search', 'status']),
            'stats' => $stats,
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
                acceptsIncomingSms: $request->has('accepts_incoming_sms') ? $request->boolean('accepts_incoming_sms') : true
            );

            return back()->with('success', "درخواست سرشماره سازمانی «{$newRequest->service_title}» با موفقیت ثبت شد و در صف بررسی مدیریت قرار گرفت.");
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'خطایی در ثبت درخواست رخ داد. لطفاً مجدداً اقدام فرمایید.');
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

            return back()->with('success', 'درخواست سرشماره سازمانی با موفقیت لغو و حذف گردید.');
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'خطا در لغو درخواست. لطفاً بعداً تلاش فرمایید.');
        }
    }
}
