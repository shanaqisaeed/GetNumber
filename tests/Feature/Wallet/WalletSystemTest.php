<?php

namespace Tests\Feature\Wallet;

use App\Contracts\WalletServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $admin;
    protected WalletServiceInterface $walletService;
    protected PhonePrefix $defaultPrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletServiceInterface::class);

        // Ensure default settings exist
        SystemSetting::set('trx_to_coin_rate', '1.0000', 'نرخ تبدیل TRX به COIN');
        SystemSetting::set('number_price_coin', '1.0000', 'قیمت پایه هر شماره به COIN');

        $this->defaultPrefix = PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'پیش‌شماره پیش‌فرض',
                'country_code' => 'INTL',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_user_wallet_is_created_automatically_with_zero_balance(): void
    {
        $wallet = $this->user->getOrCreateWallet();

        $this->assertNotNull($wallet);
        $this->assertEquals($this->user->id, $wallet->user_id);
        $this->assertEquals('0.0000', (string) $wallet->balance);
        $this->assertEquals('COIN', $wallet->currency);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $this->user->id,
            'balance' => '0.0000',
        ]);
    }

    public function test_deposit_credits_wallet_balance_and_creates_ledger_entry(): void
    {
        $tx = $this->walletService->deposit(
            user: $this->user,
            amount: '25.5000',
            referenceId: 'dep-tx-001',
            description: 'واریز تستی ترون',
            metadata: ['gateway' => 'tron_testnet', 'tx_hash' => 'hash123']
        );

        $this->assertEquals(WalletTransactionType::Deposit, $tx->type);
        $this->assertEquals('25.5000', (string) $tx->amount);
        $this->assertEquals('0.0000', (string) $tx->balance_before);
        $this->assertEquals('25.5000', (string) $tx->balance_after);
        $this->assertEquals('dep-tx-001', $tx->reference_id);

        $freshWallet = $this->user->wallet()->first();
        $this->assertEquals('25.5000', (string) $freshWallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => WalletTransactionType::Deposit->value,
            'reference_id' => 'dep-tx-001',
            'amount' => '25.5000',
        ]);
    }

    public function test_deposit_idempotency_prevents_duplicate_credits(): void
    {
        $firstTx = $this->walletService->deposit(
            user: $this->user,
            amount: '10.0000',
            referenceId: 'same-ref-123'
        );

        $secondTx = $this->walletService->deposit(
            user: $this->user,
            amount: '10.0000',
            referenceId: 'same-ref-123'
        );

        $this->assertEquals($firstTx->id, $secondTx->id);

        $freshWallet = $this->user->wallet()->first();
        $this->assertEquals('10.0000', (string) $freshWallet->balance);
        $this->assertEquals(1, WalletTransaction::where('reference_id', 'same-ref-123')->count());
    }

    public function test_charge_number_purchase_deducts_coin_and_creates_ledger(): void
    {
        // Give user 5 COIN first
        $this->walletService->deposit($this->user, '5.0000', 'dep-initial');

        $tx = $this->walletService->chargeForNumberPurchase(
            user: $this->user,
            amount: '1.0000',
            referenceId: 'num-order-101',
            description: 'خرید شماره اختصاصی'
        );

        $this->assertEquals(WalletTransactionType::NumberPurchase, $tx->type);
        $this->assertEquals('1.0000', (string) $tx->amount);
        $this->assertEquals('5.0000', (string) $tx->balance_before);
        $this->assertEquals('4.0000', (string) $tx->balance_after);

        $freshWallet = $this->user->wallet()->first();
        $this->assertEquals('4.0000', (string) $freshWallet->balance);
    }

    public function test_charge_number_purchase_throws_exception_when_balance_insufficient(): void
    {
        $this->expectException(InsufficientWalletBalanceException::class);

        // User starts with 0 COIN
        $this->walletService->chargeForNumberPurchase(
            user: $this->user,
            amount: '1.0000',
            referenceId: 'num-fail-1'
        );
    }

    public function test_refund_restores_user_balance_and_records_refund_in_ledger(): void
    {
        $this->walletService->deposit($this->user, '5.0000', 'dep-init');
        $this->walletService->chargeForNumberPurchase($this->user, '1.0000', 'order-refund-me');

        $this->assertEquals('4.0000', (string) $this->user->wallet()->first()->balance);

        $refundTx = $this->walletService->refund(
            user: $this->user,
            amount: '1.0000',
            referenceId: 'ref-order-refund-me',
            description: 'عدم پاسخگویی درگاه سیم‌کارت'
        );

        $this->assertEquals(WalletTransactionType::Refund, $refundTx->type);
        $this->assertEquals('5.0000', (string) $refundTx->balance_after);
        $this->assertEquals('5.0000', (string) $this->user->wallet()->first()->balance);
    }

    public function test_admin_credit_increases_balance_and_creates_audit_log(): void
    {
        $tx = $this->walletService->adminCredit(
            user: $this->user,
            amount: '15.0000',
            admin: $this->admin,
            reason: 'پاداش وفاداری سازمان'
        );

        $this->assertEquals(WalletTransactionType::AdminCredit, $tx->type);
        $this->assertEquals('15.0000', (string) $tx->balance_after);
        $this->assertEquals('15.0000', (string) $this->user->wallet()->first()->balance);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.wallet.credit',
            'entity_type' => WalletTransaction::class,
        ]);
    }

    public function test_admin_debit_decreases_balance_and_creates_audit_log(): void
    {
        $this->walletService->deposit($this->user, '20.0000', 'dep-20');

        $tx = $this->walletService->adminDebit(
            user: $this->user,
            amount: '7.5000',
            admin: $this->admin,
            reason: 'جریمه یا بازگشت مبلغ اشتباه'
        );

        $this->assertEquals(WalletTransactionType::AdminDebit, $tx->type);
        $this->assertEquals('12.5000', (string) $tx->balance_after);
        $this->assertEquals('12.5000', (string) $this->user->wallet()->first()->balance);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.wallet.debit',
            'entity_type' => WalletTransaction::class,
        ]);
    }

    public function test_admin_debit_fails_when_amount_exceeds_balance(): void
    {
        $this->expectException(InsufficientWalletBalanceException::class);

        $this->walletService->deposit($this->user, '5.0000', 'dep-5');

        $this->walletService->adminDebit(
            user: $this->user,
            amount: '10.0000',
            admin: $this->admin,
            reason: 'تلاش برای کسر بیش از موجودی'
        );
    }

    public function test_reversal_transaction_inverts_previous_ledger_effect(): void
    {
        // 1. Initial deposit
        $depTx = $this->walletService->deposit($this->user, '10.0000', 'dep-rev');

        // 2. Debit 4 COIN
        $debitTx = $this->walletService->adminDebit($this->user, '4.0000', $this->admin, 'کسر اشتباه');
        $this->assertEquals('6.0000', (string) $this->user->wallet()->first()->balance);

        // 3. Reverse the debit transaction
        $reversalTx = $this->walletService->reverseTransaction($debitTx, $this->admin, 'اصلاح کسر اشتباه');

        $this->assertEquals(WalletTransactionType::Reversal, $reversalTx->type);
        $this->assertEquals('4.0000', (string) $reversalTx->amount);
        $this->assertEquals('10.0000', (string) $reversalTx->balance_after);
        $this->assertEquals('10.0000', (string) $this->user->wallet()->first()->balance);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.wallet.reversal',
        ]);
    }

    public function test_reconcile_balance_from_ledger_verifies_integrity(): void
    {
        $this->walletService->deposit($this->user, '100.0000', 'dep-100');
        $this->walletService->chargeForNumberPurchase($this->user, '1.0000', 'ord-1');
        $this->walletService->chargeForNumberPurchase($this->user, '1.0000', 'ord-2');
        $this->walletService->refund($this->user, '1.0000', 'ord-2-ref');
        $this->walletService->adminDebit($this->user, '20.0000', $this->admin, 'مالیات یا کارمزد');

        // Expected: 100 - 1 - 1 + 1 - 20 = 79.0000
        $wallet = $this->user->wallet()->first();
        $this->assertEquals('79.0000', (string) $wallet->balance);

        $reconciledBalance = $wallet->reconcileBalanceFromLedger();
        $this->assertEquals('79.0000', $reconciledBalance);
    }

    public function test_user_wallet_index_page_returns_ledger_history_and_rates(): void
    {
        $this->walletService->deposit($this->user, '15.0000', 'dep-user-view');

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('User/Wallet/Index')
            ->has('wallet')
            ->has('rates')
            ->has('transactions')
            ->where('wallet.balance', '15.0000')
            ->where('rates.number_price_coin', '1.0000')
        );
    }

    public function test_admin_can_update_financial_settings(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.wallets.settings'), [
            'trx_to_coin_rate' => '2.5000',
            'number_price_coin' => '1.5000',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('2.5000', SystemSetting::getTrxToCoinRate());
        $this->assertEquals('1.5000', SystemSetting::getNumberPriceCoin());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.settings.financial_updated',
        ]);
    }

    public function test_admin_wallet_index_and_transactions_pages_accessible_to_admin_only(): void
    {
        // Regular user should be forbidden (403)
        $userResponse = $this->actingAs($this->user)->get(route('admin.wallets.index'));
        $userResponse->assertStatus(403);

        $adminIndexResponse = $this->actingAs($this->admin)->get(route('admin.wallets.index'));
        $adminIndexResponse->assertStatus(200);
        $adminIndexResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Wallet/Index')
            ->has('users')
            ->has('stats')
            ->has('rates')
        );

        $adminTxResponse = $this->actingAs($this->admin)->get(route('admin.wallets.transactions'));
        $adminTxResponse->assertStatus(200);
        $adminTxResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Wallet/Transactions')
            ->has('transactions')
            ->has('transaction_types')
        );
    }

    public function test_phone_number_allocation_requires_wallet_balance_and_deducts_coin(): void
    {
        // 1. Available number in pool
        PhoneNumber::create([
            'number' => '+859990001',
            'prefix_id' => $this->defaultPrefix->id,
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        // 2. User has 0 balance -> allocate should fail with 422
        $failResponse = $this->actingAs($this->user)->postJson(route('numbers.store'), [
            'display_title' => 'خط جدید',
        ]);

        $failResponse->assertStatus(422);
        $failResponse->assertJsonValidationErrors(['wallet']);

        // 3. Credit user with 2.0000 COIN
        $this->walletService->deposit($this->user, '2.0000', 'dep-buy-num');

        // 4. Now allocation should succeed
        $successResponse = $this->actingAs($this->user)->post(route('numbers.store'), [
            'display_title' => 'خط کاری',
        ]);

        $successResponse->assertRedirect();

        // 5. User balance should be 1.0000 COIN now
        $freshWallet = $this->user->wallet()->first();
        $this->assertEquals('1.0000', (string) $freshWallet->balance);

        // 6. Ledger should record NumberPurchase
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => WalletTransactionType::NumberPurchase->value,
            'amount' => '1.0000',
        ]);
    }

    public function test_phone_number_allocation_auto_refunds_if_allocation_fails(): void
    {
        // Create a number already allocated to another user (clean 9-digit number)
        PhoneNumber::create([
            'number' => '912345678',
            'phone_prefix_id' => $this->defaultPrefix->id,
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->admin->id,
            'allocated_at' => now(),
        ]);

        // Credit user with 2.0000 COIN
        $this->walletService->deposit($this->user, '2.0000', 'dep-buy-occupied');

        // Attempt to allocate the already occupied number
        $response = $this->actingAs($this->user)->post(route('numbers.store'), [
            'preferred_number' => '+85912345678',
            'display_title' => 'تست خط اشغال‌شده',
        ]);

        $response->assertSessionHas('error');

        // User balance should be restored to 2.0000 COIN!
        $freshWallet = $this->user->wallet()->first();
        $this->assertEquals('2.0000', (string) $freshWallet->balance);

        // Ledger should have both NumberPurchase and Refund entries
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => WalletTransactionType::NumberPurchase->value,
            'amount' => '1.0000',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => WalletTransactionType::Refund->value,
            'amount' => '1.0000',
        ]);
    }
}
