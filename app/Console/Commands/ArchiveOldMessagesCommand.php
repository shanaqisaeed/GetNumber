<?php

namespace App\Console\Commands;

use App\Contracts\MessageArchiveServiceInterface;
use Illuminate\Console\Command;

class ArchiveOldMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'messages:archive
                            {--days=90 : دوره نگهداری پیام‌ها بر حسب روز (پیام‌های قدیمی‌تر آرشیو می‌شوند)}
                            {--chunk=500 : سقف تعداد رکوردهای پردازشی در هر تراکنش}
                            {--dry-run : شبیه‌سازی بدون جابجایی واقعی رکوردها}';

    /**
     * The console command description.
     */
    protected $description = 'انتقال امن پیام‌های تاریخی و قدیمی به فضای ذخیره‌سازی سرد (Cold Storage Archiving) جهت سبک‌سازی دیتابیس';

    /**
     * Execute the console command.
     */
    public function handle(MessageArchiveServiceInterface $archiveService): int
    {
        $days = (int) $this->option('days');
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("🗄️ در حال بررسی و انتقال پیام‌های قدیمی‌تر از {$days} روز به جدول آرشیو...");

        $result = $archiveService->archiveOlderThan($days, $chunk, $dryRun);

        $this->table(
            ['شاخص فرآیند آرشیو', 'مقدار'],
            [
                ['آستانه نگهداری (روز)', $result['days_threshold']],
                ['تاریخ مرز آرشیو', $result['cutoff_date']],
                ['تعداد پیام‌های مشمول', $result['eligible_count']],
                ['تعداد منتقل‌شده به آرشیو', $dryRun ? 'شبیه‌سازی (۰ رکورد جابجا شد)' : $result['archived_count']],
                ['حالت شبیه‌سازی (Dry Run)', $dryRun ? 'فعال' : 'غیرفعال'],
            ]
        );

        if ($dryRun) {
            $this->warn('ℹ️ عملیات در حالت شبیه‌سازی اجرا شد و هیچ داده‌ای حذف یا جابجا نشد.');
        } else {
            $this->info("✅ انتقال {$result['archived_count']} پیام با موفقیت و تایید یکپارچگی انجام شد.");
        }

        return Command::SUCCESS;
    }
}

