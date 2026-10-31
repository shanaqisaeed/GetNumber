<?php

namespace App\Console\Commands;

use App\Contracts\PaymentServiceInterface;
use Illuminate\Console\Command;

class VerifyPendingCryptoDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:verify-pending {--limit=50 : تعداد حداکثر تراکنش‌ها جهت پردازش در هر نوبت}';

    /**
     * The console command description.
     */
    protected $description = 'بررسی و استعلام دوره‌ای تراکنش‌های معلق کریپتو از شبکه و صرافی‌ها و شارژ خودکار کیف پول';

    /**
     * Execute the console command.
     */
    public function handle(PaymentServiceInterface $paymentService): int
    {
        $limit = (int) $this->option('limit');

        $this->info("در حال بررسی تراکنش‌های معلق کریپتو (حداکثر {$limit} مورد)...");

        $stats = $paymentService->processPendingDeposits($limit);
        $expiredCount = $paymentService->expireStaleDeposits();

        $this->info("تراکنش‌های بررسی‌شده: {$stats['checked']}");
        $this->info("تأیید و شارژشده: {$stats['completed']}");
        $this->info("ردشده/ناموفق: {$stats['failed']}");
        $this->info("همچنان در صف تایید: {$stats['still_pending']}");

        if ($expiredCount > 0) {
            $this->warn("تراکنش‌های منقضی‌شده: {$expiredCount}");
        }

        return Command::SUCCESS;
    }
}

