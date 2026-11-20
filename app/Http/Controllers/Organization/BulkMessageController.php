<?php

namespace App\Http\Controllers\Organization;

use App\Contracts\MessageServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\MessageBatch;
use App\Models\PhoneNumber;
use App\Services\SmsSegmentCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BulkMessageController extends Controller
{
    public function __construct(
        protected MessageServiceInterface $messageService,
        protected SmsSegmentCalculatorService $calculator
    ) {}

    /**
     * Display Organization Bulk SMS dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        // Sender lines belonging to the organization
        $senderLines = $org->phoneNumbers()
            ->with('phonePrefix')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'sms_rate' => (float) ($p->phonePrefix?->sms_tariff_rate_per_segment ?? 0.05),
            ]);

        // Recent batches for organization lines
        $phoneIds = $org->phoneNumbers()->pluck('id');
        $recentBatches = MessageBatch::whereIn('phone_number_id', $phoneIds)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($b) => [
                'batch_id' => $b->batch_id,
                'total_count' => $b->total_count,
                'processed_count' => $b->processed_count,
                'success_count' => $b->success_count,
                'failed_count' => $b->failed_count,
                'status' => $b->status,
                'created_at' => $b->created_at->format('Y/m/d H:i'),
            ]);

        return Inertia::render('Organization/BulkSms/Index', [
            'sender_lines' => $senderLines,
            'recent_batches' => $recentBatches,
            'org_balance' => (string) $wallet->balance,
            'currency' => $wallet->currency,
        ]);
    }

    /**
     * Server-side cost estimation for bulk SMS campaign against organization wallet.
     */
    public function estimate(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        $validated = $request->validate([
            'from_number_id' => ['required', 'exists:phone_numbers,id'],
            'destinations_count' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'destinations' => ['nullable'],
            'recipients_text' => ['nullable', 'string'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $fromNumber = PhoneNumber::where('organization_id', $org->id)
            ->with('phonePrefix')
            ->findOrFail($validated['from_number_id']);

        $fromPrefix = $fromNumber->phonePrefix ?: \App\Models\PhonePrefix::getDefaultPrefix();

        $calcResult = $this->calculator->calculateSegments($validated['body']);
        $segmentsPerMsg = max(1, $calcResult['segments_count']);

        $rawDest = $validated['destinations'] ?? $validated['recipients_text'] ?? null;
        $destArray = [];
        if (! empty($rawDest)) {
            $parsed = is_string($rawDest) ? preg_split('/[\r\n,;]+/', $rawDest) : (array) $rawDest;
            $destArray = array_values(array_filter(array_map('trim', $parsed), fn($s) => strlen($s) >= 3));
        }

        $recipientsCount = ! empty($destArray) ? count($destArray) : (int) ($validated['destinations_count'] ?? 1);
        $totalSegments = $segmentsPerMsg * $recipientsCount;

        $intraCount = 0;
        $interCount = 0;
        $idCount = 0;
        $totalCost = 0.0;
        $intraRate = (float) $fromPrefix->getIntraSmsRate();
        $interRate = (float) $fromPrefix->getInterSmsRate();
        $idRate = (float) \App\Models\SystemSetting::get('id_sms_rate', '0.0200');

        if (! empty($destArray)) {
            foreach ($destArray as $dest) {
                $rate = $fromPrefix->calculateSmsRateForDestination($dest, $org->id);
                $totalCost += ($rate * $segmentsPerMsg);
                if (str_starts_with($dest, '@') || ! preg_match('/^\+?\d+$/', $dest)) {
                    $idCount++;
                } elseif ($fromPrefix->isIntraPrefix($dest, $org->id)) {
                    $intraCount++;
                } else {
                    $interCount++;
                }
            }
        } else {
            // Default estimation when destinations list is not yet provided: use intra rate
            $intraCount = $recipientsCount;
            $totalCost = $recipientsCount * $segmentsPerMsg * $intraRate;
        }

        $totalCost = round($totalCost, 4);
        $hasEnoughBalance = bccomp((string) $wallet->balance, (string) $totalCost, 4) >= 0;

        return response()->json([
            'success' => true,
            'recipients_count' => $recipientsCount,
            'segments' => $segmentsPerMsg,
            'segments_count' => $segmentsPerMsg,
            'total_segments' => $totalSegments,
            'rate_per_segment' => $intraRate,
            'intra_rate' => $intraRate,
            'inter_rate' => $interRate,
            'id_rate' => $idRate,
            'intra_recipients' => $intraCount,
            'inter_recipients' => $interCount,
            'id_recipients' => $idCount,
            'estimated_cost' => $totalCost,
            'total_cost' => $totalCost,
            'org_balance' => (string) $wallet->balance,
            'has_sufficient_balance' => $hasEnoughBalance,
            'has_enough_balance' => $hasEnoughBalance,
            'currency' => $wallet->currency,
        ]);
    }

    /**
     * Dispatch enterprise bulk SMS campaign.
     */
    public function send(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        $validated = $request->validate([
            'from_number_id' => ['required', 'exists:phone_numbers,id'],
            'destinations' => ['nullable'],
            'recipients_text' => ['nullable', 'string'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $fromNumber = PhoneNumber::where('organization_id', $org->id)
            ->with('phonePrefix')
            ->findOrFail($validated['from_number_id']);

        $fromPrefix = $fromNumber->phonePrefix ?: \App\Models\PhonePrefix::getDefaultPrefix();

        $rawDest = $validated['destinations'] ?? $validated['recipients_text'] ?? '';
        $destArray = is_string($rawDest) ? preg_split('/[\r\n,;]+/', $rawDest) : (array) $rawDest;
        $destArray = array_values(array_filter(array_map('trim', $destArray), fn($s) => strlen($s) >= 3));

        if (empty($destArray)) {
            if ($request->header('X-Inertia')) {
                return back()->with('error', __('system.validation_error'));
            }
            return response()->json([
                'success' => false,
                'message' => __('system.validation_error'),
            ], 422);
        }

        // Calculate and debit organization wallet based on intra vs inter tariffs from admin
        $segmentInfo = $this->calculator->calculateSegments($validated['body']);
        $segmentsCount = max(1, $segmentInfo['segments_count']);

        $totalCost = 0.0;
        $intraCount = 0;
        $interCount = 0;
        $idCount = 0;

        foreach ($destArray as $dest) {
            $itemRate = $fromPrefix->calculateSmsRateForDestination($dest, $org->id);
            $totalCost += ($itemRate * $segmentsCount);
            if (str_starts_with($dest, '@') || ! preg_match('/^\+?\d+$/', $dest)) {
                $idCount++;
            } elseif ($fromPrefix->isIntraPrefix($dest, $org->id)) {
                $intraCount++;
            } else {
                $interCount++;
            }
        }
        $totalCost = round($totalCost, 4);

        if (bccomp((string) $wallet->balance, (string) $totalCost, 4) < 0) {
            if ($request->header('X-Inertia')) {
                return back()->with('error', __('organization.bulk_sms.insufficient_org_balance'));
            }
            return response()->json([
                'success' => false,
                'message' => __('organization.bulk_sms.insufficient_org_balance'),
            ], 422);
        }

        // Queue bulk SMS via message service
        $batch = $this->messageService->queueBulkSms(
            fromNumber: $fromNumber,
            destinationNumbers: $destArray,
            body: $validated['body'],
            senderUser: $user
        );

        // Debit corporate wallet ledger
        $wallet->debit(
            amount: (string) $totalCost,
            type: 'bulk_sms',
            description: __('organization.bulk_sms.cost_source_notice') . " (Intra: {$intraCount}, Inter: {$interCount})",
            userId: $user->id,
            metadata: [
                'batch_id' => $batch->batch_id,
                'recipients_count' => count($destArray),
                'intra_recipients' => $intraCount,
                'inter_recipients' => $interCount,
                'id_recipients' => $idCount,
                'intra_rate' => (float) $fromPrefix->getIntraSmsRate(),
                'inter_rate' => (float) $fromPrefix->getInterSmsRate(),
            ]
        );

        if ($request->header('X-Inertia')) {
            return back()->with('success', __('organization.bulk_sms.campaign_started'));
        }

        return response()->json([
            'success' => true,
            'batch_id' => $batch->batch_id,
            'total_count' => $batch->total_count,
            'status' => $batch->status,
            'status_url' => route('org.bulk_sms.batch_status', $batch->batch_id),
            'message' => __('organization.bulk_sms.campaign_started'),
        ], 202);
    }

    /**
     * Check progress and statistics of a bulk message batch.
     */
    public function batchStatus(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->messageService->getBatchStatus($batchId, $request->user());

        return response()->json([
            'success' => true,
            'batch_id' => $batch->batch_id,
            'status' => $batch->status,
            'total_count' => $batch->total_count,
            'processed_count' => $batch->processed_count,
            'success_count' => $batch->success_count,
            'failed_count' => $batch->failed_count,
            'progress_percentage' => $batch->progressPercentage(),
            'is_finished' => $batch->isFinished(),
            'created_at' => $batch->created_at->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
        ]);
    }
}
