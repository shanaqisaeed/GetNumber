<?php

namespace App\Console\Commands;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SweepExpiredNumbersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'numbers:sweep-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'آزادسازی خطوط منقضی‌شده رزرو و به‌روزرسانی وضعیت خطوط به سررسید رسیده';

    /**
     * Execute the console command.
     */
    public function handle(PhoneNumberAllocationServiceInterface $allocationService): int
    {
        $this->info('در حال بررسی و پردازش خطوط منقضی‌شده سامانه...');

        $processedCount = $allocationService->processExpiredNumbers();

        Log::info("Number expiration sweep executed: {$processedCount} numbers processed.");

        $this->info("تعداد {$processedCount} شماره منقضی‌شده با موفقیت پردازش و وضعیت آن‌ها به‌روزرسانی گردید.");

        return Command::SUCCESS;
    }
}
