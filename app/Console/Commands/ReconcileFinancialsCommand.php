<?php

namespace App\Console\Commands;

use App\Contracts\FinancialReconciliationServiceInterface;
use App\Models\User;
use Illuminate\Console\Command;

class ReconcileFinancialsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'financial:reconcile 
                            {--user= : شناسه کاربر خاص جهت بررسی مغایرت}
                            {--fix : اصلاح و تطبیق خودکار مانده کش‌شده با دفتر کل}';

    /**
     * The console command description.
     */
    protected $description = 'کنترل یکپارچگی، ممیزی و تطبیق موجودی کیف‌پول کاربران با اسناد حسابداری دفتر کل (Double-Entry Reconciliation)';

    /**
     * Execute the console command.
     */
    public function handle(FinancialReconciliationServiceInterface $reconciliationService): int
    {
        $userId = $this->option('user');
        $autoFix = (bool) $this->option('fix');

        $this->info('🔍 در حال اجرای ممیزی و تطبیق مالی سامانه...');

        if ($userId) {
            $user = User::find($userId);
            if (! $user) {
                $this->error("کاربر با شناسه #{$userId} یافت نشد.");
                return Command::FAILURE;
            }

            $result = $reconciliationService->reconcileUser($user, $autoFix);

            $this->table(
                ['شاخص', 'مقدار'],
                [
                    ['شناسه کاربر', $result['user_id']],
                    ['وضعیت تطبیق', $result['is_matched'] ? '✅ منطبق و سالم' : '❌ دارای مغایرت مالی'],
                    ['موجودی کیف پول (Cached)', $result['cached_balance']],
                    ['موجودی دفتر کل (Ledger)', $result['ledger_balance']],
                    ['اختلاف حساب', $result['difference']],
                    ['اصلاح خودکار شد؟', $result['fixed'] ? 'بله' : 'خیر'],
                ]
            );

            return $result['is_matched'] ? Command::SUCCESS : Command::FAILURE;
        }

        $report = $reconciliationService->reconcileGlobal($autoFix);

        $this->line('');
        $this->info('📊 نتایج ممیزی کلان دفتر کل (Global Ledger Balance):');
        $this->table(
            ['شاخص کلان', 'مقدار'],
            [
                ['توازن دوطرفه کل (بدهکار = بستانکار)', $report['global_ledger']['is_balanced'] ? '✅ کاملاً متوازن (Balanced)' : '❌ نامتوازن!'],
                ['مجموع کل بدهکار', $report['global_ledger']['total_debit']],
                ['مجموع کل بستانکار', $report['global_ledger']['total_credit']],
                ['تعداد اسناد روزنامه', $report['global_ledger']['journal_count']],
                ['تعداد سطرهای ثبتی', $report['global_ledger']['entry_count']],
                ['مجموع مانده کیف‌پول‌ها', $report['total_wallets_balance']],
                ['مجموع تعهدات ثبت‌شده دفتر کل', $report['total_ledger_liability']],
                ['کاربران بررسی‌شده', $report['total_users_checked']],
                ['تعداد کاربران دارای مغایرت', $report['discrepant_users_count']],
            ]
        );

        if (! $report['is_healthy']) {
            $this->error("⚠️ هشدار: تعداد {$report['discrepant_users_count']} مغایرت در سامانه شناسایی شد!");
            return Command::FAILURE;
        }

        $this->info('✅ تمام اسناد مالی و کیف‌پول‌های سامانه در وضعیت توازن و تطبیق ۱۰۰٪ قرار دارند.');
        return Command::SUCCESS;
    }
}

