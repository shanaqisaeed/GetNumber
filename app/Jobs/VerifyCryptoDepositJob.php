<?php

namespace App\Jobs;

use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Models\CryptoDeposit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyCryptoDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the queued job may be attempted.
     */
    public int $tries = 5;

    public function __construct(
        public int $depositId
    ) {}

    /**
     * Execute the job: checks transaction up to 3 times with 30-minute intervals.
     * If not confirmed after 3 attempts, escalates to manual_review.
     */
    public function handle(PaymentServiceInterface $paymentService): void
    {
        /** @var CryptoDeposit|null $deposit */
        $deposit = CryptoDeposit::find($this->depositId);

        if (! $deposit || ! $deposit->tx_hash) {
            return;
        }

        if ($deposit->isCompleted() || $deposit->status === PaymentStatus::Failed || $deposit->status === PaymentStatus::ManualReview) {
            return;
        }

        $currentAttempt = $deposit->attempts + 1;
        Log::info("VerifyCryptoDepositJob: Checking deposit #{$deposit->id} ({$deposit->tracking_code}), attempt {$currentAttempt}/3");

        $verification = $paymentService->verifyDeposit($deposit);
        $deposit->refresh();

        if ($deposit->isCompleted()) {
            Log::info("VerifyCryptoDepositJob: Deposit #{$deposit->id} confirmed and wallet credited successfully.");
            return;
        }

        if ($deposit->status === PaymentStatus::Failed) {
            Log::warning("VerifyCryptoDepositJob: Deposit #{$deposit->id} rejected by network/gateway.");
            return;
        }

        // If 3 attempts completed without confirmation, escalate to manual_review
        if ($deposit->attempts >= 3) {
            $deposit->update([
                'status' => PaymentStatus::ManualReview,
                'failure_reason' => __('jobs.crypto_deposit.manual_review_reason'),
                'next_check_at' => null,
            ]);

            Log::warning("VerifyCryptoDepositJob: Deposit #{$deposit->id} escalated to manual_review after 3 unconfirmed attempts.");
            return;
        }

        // Otherwise release back to queue with 30 minutes delay (1800 seconds)
        Log::info("VerifyCryptoDepositJob: Deposit #{$deposit->id} not confirmed yet. Releasing job for attempt in 30 minutes.");
        $this->release(1800);
    }
}