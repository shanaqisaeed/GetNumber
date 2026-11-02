<?php

namespace App\Console\Commands;

use App\Contracts\BlockchainWatcherServiceInterface;
use Illuminate\Console\Command;

class WatchBlockchainDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blockchain:watch-deposits {--limit=50 : سقف تعداد فاکتورهای معلق جهت بررسی}';

    /**
     * The console command description.
     */
    protected $description = 'پایش خودکار و آنی تراکنش‌های ورودی بلاکچین (Blockchain Watcher) و انتساب خودکار بدون نیاز به ورود دستی هش';

    /**
     * Execute the console command.
     */
    public function handle(BlockchainWatcherServiceInterface $watcherService): int
    {
        $limit = (int) $this->option('limit');

        $this->info("🛰️ در حال اسکن شبکه‌های بلاکچین و درگاه‌ها (حداکثر {$limit} مورد)...");

        $stats = $watcherService->scanAndMatchIncomingDeposits($limit);
        $progressStats = $watcherService->progressPendingConfirmations($limit);

        $this->table(
            ['شاخص واچر بلاکچین', 'مقدار'],
            [
                ['درگاه‌های اسکن‌شده', $stats['scanned_gateways']],
                ['تراکنش‌های ورودی رصدشده', $stats['incoming_transactions_detected']],
                ['واریزی‌های منطبق‌شده خودکار', $stats['auto_matched_deposits']],
                ['تأیید و شارژشده قطعی', $stats['completed'] + ($progressStats['completed'] ?? 0)],
                ['در انتظار رسیدن به حد نصاب تأییدات', $stats['confirming'] + ($progressStats['still_pending'] ?? 0)],
                ['خطاهای واچر', $stats['errors']],
            ]
        );

        return Command::SUCCESS;
    }
}

