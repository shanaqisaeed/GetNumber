<?php

namespace Tests\Feature\Payment;

use App\Contracts\FinancialReconciliationServiceInterface;
use App\Contracts\LedgerServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoubleEntryLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $admin;
    protected WalletServiceInterface $walletService;
    protected LedgerServiceInterface $ledgerService;
    protected FinancialReconciliationServiceInterface $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletServiceInterface::class);
        $this->ledgerService = app(LedgerServiceInterface::class);
        $this->reconciliationService = app(FinancialReconciliationServiceInterface::class);

        SystemSetting::set('trx_to_coin_rate', '1.0000', 'نرخ تبدیل TRX به COIN');
        SystemSetting::set('number_price_coin', '1.0000', 'قیمت پایه هر شماره به COIN');

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_deposit_creates_strictly_balanced_journal_and_ledger_entries(): void
    {
        $tx = $this->walletService->deposit(
            user: $this->user,
            amount: '50.0000',
            referenceId: 'DEP-TEST-001',
            description: 'واریز تستی جهت حسابداری دوطرفه'
        );

        $this->assertEquals('50.0000', (string) $this->user->getOrCreateWallet()->balance);

        // Verify Journal Entry exists
        $journal = JournalEntry::where('reference_id', 'DEP-TEST-001')->first();
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());

        // Verify Ledger Lines:
        // Debit Platform Treasury (Asset +) = 50.0000
        $treasuryAccount = LedgerAccount::findByCode('1010_PLATFORM_TREASURY');
        $this->assertDatabaseHas('ledger_entries', [
            'journal_entry_id' => $journal->id,
            'ledger_account_id' => $treasuryAccount->id,
            'debit' => '50.0000',
            'credit' => '0.0000',
        ]);

        // Credit User Wallets (Liability +) = 50.0000
        $walletAccount = LedgerAccount::findByCode('2010_USER_WALLETS');
        $this->assertDatabaseHas('ledger_entries', [
            'journal_entry_id' => $journal->id,
            'ledger_account_id' => $walletAccount->id,
            'user_id' => $this->user->id,
            'debit' => '0.0000',
            'credit' => '50.0000',
        ]);

        // Ledger balance must match wallet balance exactly
        $calculatedBalance = $this->ledgerService->calculateUserBalance($this->user);
        $this->assertEquals('50.0000', $calculatedBalance);
    }

    public function test_number_purchase_debits_user_wallet_and_credits_revenue(): void
    {
        // Initial deposit
        $this->walletService->deposit($this->user, '100.0000', 'DEP-INIT-001');

        // Number purchase
        $this->walletService->chargeForNumberPurchase(
            user: $this->user,
            amount: '20.0000',
            referenceId: 'PURCHASE-NUM-001',
            description: 'خرید شماره مجازی تستی'
        );

        $this->assertEquals('80.0000', (string) $this->user->getOrCreateWallet()->balance);

        $journal = JournalEntry::where('reference_id', 'PURCHASE-NUM-001')->first();
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());

        $walletAccount = LedgerAccount::findByCode('2010_USER_WALLETS');
        $revenueAccount = LedgerAccount::findByCode('4010_NUMBER_REVENUE');

        // Debit User Wallets (Liability -) = 20.0000
        $this->assertDatabaseHas('ledger_entries', [
            'journal_entry_id' => $journal->id,
            'ledger_account_id' => $walletAccount->id,
            'user_id' => $this->user->id,
            'debit' => '20.0000',
            'credit' => '0.0000',
        ]);

        // Credit Revenue (Operating Revenue +) = 20.0000
        $this->assertDatabaseHas('ledger_entries', [
            'journal_entry_id' => $journal->id,
            'ledger_account_id' => $revenueAccount->id,
            'debit' => '0.0000',
            'credit' => '20.0000',
        ]);

        $this->assertEquals('80.0000', $this->ledgerService->calculateUserBalance($this->user));
    }

    public function test_unbalanced_journal_entry_throws_exception_and_rolls_back(): void
    {
        $this->expectException(UnbalancedJournalEntryException::class);

        // Attempt to create intentionally unbalanced entry (Debit 10, Credit 5)
        $this->ledgerService->recordJournal(
            description: 'سند نامتوازن غیرمجاز',
            lines: [
                [
                    'account_code' => '1010_PLATFORM_TREASURY',
                    'debit' => '10.0000',
                    'credit' => '0.0000',
                ],
                [
                    'account_code' => '2010_USER_WALLETS',
                    'debit' => '0.0000',
                    'credit' => '5.0000',
                    'user_id' => $this->user->id,
                ],
            ]
        );
    }

    public function test_reconciliation_service_detects_wallet_tampering_and_logs_audit(): void
    {
        $this->walletService->deposit($this->user, '50.0000', 'DEP-REC-001');

        // Artificially tamper with cached wallet balance (e.g. simulated DB breach/race bug)
        $wallet = $this->user->getOrCreateWallet();
        $wallet->update(['balance' => '999.0000']);

        // Run reconciliation
        $result = $this->reconciliationService->reconcileUser($this->user, autoFix: false);

        $this->assertFalse($result['is_matched']);
        $this->assertEquals('999.0000', $result['cached_balance']);
        $this->assertEquals('50.0000', $result['ledger_balance']);
        $this->assertEquals('949.0000', $result['difference']);

        // Security Audit Log must be registered
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'financial.reconciliation.discrepancy',
            'user_id' => $this->user->id,
        ]);

        // Now run with autoFix = true
        $fixResult = $this->reconciliationService->reconcileUser($this->user, autoFix: true);
        $this->assertTrue($fixResult['fixed']);
        $this->assertEquals('50.0000', (string) $wallet->fresh()->balance);
    }

    public function test_global_reconciliation_verifies_all_debits_equal_all_credits(): void
    {
        $this->walletService->deposit($this->user, '100.0000', 'DEP-GLOBAL-001');
        $this->walletService->chargeForNumberPurchase($this->user, '30.0000', 'PURCHASE-GLOBAL-001');
        $this->walletService->refund($this->user, '10.0000', 'REFUND-GLOBAL-001');

        $globalReport = $this->reconciliationService->reconcileGlobal();

        $this->assertTrue($globalReport['is_healthy']);
        $this->assertTrue($globalReport['global_ledger']['is_balanced']);
        $this->assertEquals('0.0000', $globalReport['global_ledger']['difference']);
        $this->assertEquals('80.0000', $globalReport['total_wallets_balance']);
        $this->assertEquals('80.0000', $globalReport['total_ledger_liability']);
        $this->assertTrue($globalReport['is_wallets_matched']);
    }
}

