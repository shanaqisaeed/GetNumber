<?php

namespace Tests\Feature\Payment;

use App\Contracts\PaymentServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Models\AuditLog;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CryptoPaymentSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected User $admin;
    protected PaymentServiceInterface $paymentService;
    protected WalletServiceInterface $walletService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = app(PaymentServiceInterface::class);
        $this->walletService = app(WalletServiceInterface::class);

        SystemSetting::set('trx_to_coin_rate', '1.0000', 'نرخ تبدیل TRX به COIN');
        SystemSetting::set('number_price_coin', '1.0000', 'قیمت پایه هر شماره به COIN');

        // Ensure mock gateway is set as default for deterministic tests
        PaymentGateway::where('driver', 'mock')->update([
            'is_active' => true,
            'is_default' => true,
        ]);
        PaymentGateway::where('driver', '!=', 'mock')->update([
            'is_default' => false,
        ]);

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->otherUser = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_user_can_view_deposit_create_page(): void
    {
        $response = $this->actingAs($this->user)->get(route('wallet.deposit.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('User/Wallet/Deposit')
            ->has('rates')
            ->has('wallet_balance')
            ->has('gateways')
            ->has('recent_deposits')
        );
    }

    public function test_user_can_create_deposit_request_and_receives_address(): void
    {
        $response = $this->actingAs($this->user)->post(route('wallet.deposit.store'), [
            'amount' => '15.0000',
            'network' => 'TRON',
            'gateway' => 'mock',
        ]);

        $deposit = CryptoDeposit::where('user_id', $this->user->id)->first();
        $this->assertNotNull($deposit);

        $response->assertRedirect(route('wallet.deposit.show', $deposit->tracking_code));

        $this->assertEquals('15.0000', (string) $deposit->requested_amount);
        $this->assertEquals('15.0000', (string) $deposit->coin_amount);
        $this->assertEquals('1.0000', (string) $deposit->trx_to_coin_rate);
        $this->assertEquals(PaymentStatus::PendingTransfer, $deposit->status);
        $this->assertNotEmpty($deposit->deposit_address);
        $this->assertStringStartsWith('DEP-', $deposit->tracking_code);
    }

    public function test_user_can_view_deposit_show_page_with_details(): void
    {
        $deposit = $this->paymentService->createDepositRequest(
            user: $this->user,
            trxAmount: '20.0000',
            network: 'TRON',
            gatewayDriver: 'mock'
        );

        $response = $this->actingAs($this->user)->get(route('wallet.deposit.show', $deposit->tracking_code));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('User/Wallet/DepositShow')
            ->has('deposit')
            ->where('deposit.tracking_code', $deposit->tracking_code)
            ->where('deposit.requested_amount', '20.0000')
            ->where('deposit.status', PaymentStatus::PendingTransfer->value)
        );
    }

    public function test_user_cannot_view_or_submit_tx_for_another_users_deposit(): void
    {
        $deposit = $this->paymentService->createDepositRequest(
            user: $this->user,
            trxAmount: '10.0000',
            network: 'TRON',
            gatewayDriver: 'mock'
        );

        // Other user attempts to view deposit
        $viewResponse = $this->actingAs($this->otherUser)->get(route('wallet.deposit.show', $deposit->tracking_code));
        $viewResponse->assertStatus(404);

        // Other user attempts to submit tx_hash
        $submitResponse = $this->actingAs($this->otherUser)->post(route('wallet.deposit.submit-tx', $deposit->tracking_code), [
            'tx_hash' => 'hash_attempt_123',
        ]);
        $submitResponse->assertStatus(404);
    }

    public function test_user_submits_valid_tx_hash_and_triggers_instant_verification_and_crediting(): void
    {
        $deposit = $this->paymentService->createDepositRequest(
            user: $this->user,
            trxAmount: '30.0000',
            network: 'TRON',
            gatewayDriver: 'mock'
        );

        $response = $this->actingAs($this->user)->post(route('wallet.deposit.submit-tx', $deposit->tracking_code), [
            'tx_hash' => 'valid_tron_blockchain_tx_hash_001',
        ]);

        $response->assertRedirect();

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Completed, $freshDeposit->status);
        $this->assertEquals('valid_tron_blockchain_tx_hash_001', $freshDeposit->tx_hash);
        $this->assertNotNull($freshDeposit->completed_at);

        // Verify wallet credited with 30.0000 COIN
        $wallet = $this->user->wallet()->first();
        $this->assertEquals('30.0000', (string) $wallet->balance);

        // Verify ledger entry
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => WalletTransactionType::Deposit->value,
            'amount' => '30.0000',
            'reference_id' => "DEP-{$deposit->id}-valid_tron_blockchain_tx_hash_001",
        ]);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.deposit.completed',
            'entity_type' => CryptoDeposit::class,
            'entity_id' => $deposit->id,
        ]);
    }

    public function test_duplicate_tx_hash_is_prevented_across_the_system(): void
    {
        $deposit1 = $this->paymentService->createDepositRequest($this->user, '10.0000', 'TRON', 'mock');
        $this->paymentService->submitTxHash($deposit1, 'unique_hash_777', $this->user);

        $this->assertEquals(PaymentStatus::Completed, $deposit1->fresh()->status);

        // Second deposit tries to submit same hash
        $deposit2 = $this->paymentService->createDepositRequest($this->otherUser, '10.0000', 'TRON', 'mock');

        $response = $this->actingAs($this->otherUser)->post(route('wallet.deposit.submit-tx', $deposit2->tracking_code), [
            'tx_hash' => 'unique_hash_777',
        ]);

        $response->assertSessionHas('error');
        $this->assertNotEquals(PaymentStatus::Completed, $deposit2->fresh()->status);
    }

    public function test_underpaid_transaction_is_rejected_and_not_credited(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '50.0000', 'TRON', 'mock');

        // Prefix underpaid_ triggers mock simulation for insufficient amount
        $this->paymentService->submitTxHash($deposit, 'underpaid_tx_hash_999', $this->user);

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Failed, $freshDeposit->status);
        $this->assertStringContainsString('کمتر از مبلغ درخواست', (string) $freshDeposit->failure_reason);

        // Wallet balance remains 0
        $this->assertEquals('0.0000', (string) $this->user->getOrCreateWallet()->balance);
    }

    public function test_wrong_destination_transaction_is_rejected_and_not_credited(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '25.0000', 'TRON', 'mock');

        // Prefix wrong_dest_ triggers destination mismatch simulation
        $this->paymentService->submitTxHash($deposit, 'wrong_dest_tx_hash_888', $this->user);

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Failed, $freshDeposit->status);
        $this->assertStringContainsString('آدرس واریز', (string) $freshDeposit->failure_reason);

        // Wallet balance remains 0
        $this->assertEquals('0.0000', (string) $this->user->getOrCreateWallet()->balance);
    }

    public function test_pending_transaction_schedules_backoff_and_stays_verifying(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '10.0000', 'TRON', 'mock');

        // Prefix pending_ triggers pending block confirmation simulation
        $this->paymentService->submitTxHash($deposit, 'pending_tx_hash_555', $this->user);

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Verifying, $freshDeposit->status);
        $this->assertEquals(1, $freshDeposit->attempts);
        $this->assertNotNull($freshDeposit->next_check_at);
        $this->assertTrue($freshDeposit->next_check_at->isFuture());

        // Balance not credited yet
        $this->assertEquals('0.0000', (string) $this->user->getOrCreateWallet()->balance);
    }

    public function test_scheduled_artisan_command_verifies_pending_deposits_and_credits_wallet(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '12.0000', 'TRON', 'mock');
        $deposit->update([
            'tx_hash' => 'auto_verify_tx_hash_333',
            'status' => PaymentStatus::TxSubmitted,
            'next_check_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('payments:verify-pending', ['--limit' => 10]);
        $this->assertEquals(0, $exitCode);

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Completed, $freshDeposit->status);

        $wallet = $this->user->wallet()->first();
        $this->assertEquals('12.0000', (string) $wallet->balance);
    }

    public function test_expire_stale_deposits_marks_expired_records(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '10.0000', 'TRON', 'mock');
        $deposit->update([
            'expires_at' => now()->subMinutes(15),
        ]);

        $expiredCount = $this->paymentService->expireStaleDeposits();
        $this->assertGreaterThanOrEqual(1, $expiredCount);

        $this->assertEquals(PaymentStatus::Expired, $deposit->fresh()->status);
    }

    public function test_admin_can_reverify_deposit_from_panel(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '18.0000', 'TRON', 'mock');
        $deposit->update([
            'tx_hash' => 'reverify_hash_444',
            'status' => PaymentStatus::Verifying,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.payments.verify', $deposit->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals(PaymentStatus::Completed, $deposit->fresh()->status);
        $this->assertEquals('18.0000', (string) $this->user->wallet()->first()->balance);
    }

    public function test_admin_can_manually_complete_deposit_with_mandatory_reason_and_audit_log(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '40.0000', 'TRON', 'mock');

        $response = $this->actingAs($this->admin)->post(route('admin.payments.complete-manually', $deposit->id), [
            'reason' => 'تأیید دستی بر اساس بررسی مستقیم اکسپلورر ترون اسکن',
            'tx_hash' => 'manual_tron_scan_hash_777',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Completed, $freshDeposit->status);
        $this->assertEquals('manual_tron_scan_hash_777', $freshDeposit->tx_hash);

        // Wallet credited
        $this->assertEquals('40.0000', (string) $this->user->wallet()->first()->balance);

        // Audit Log verified
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.payment.manual_completed',
            'entity_type' => CryptoDeposit::class,
            'entity_id' => $deposit->id,
        ]);
    }

    public function test_admin_payments_pages_are_forbidden_to_standard_users(): void
    {
        $userResponse = $this->actingAs($this->user)->get(route('admin.payments.index'));
        $userResponse->assertStatus(403);

        $adminResponse = $this->actingAs($this->admin)->get(route('admin.payments.index'));
        $adminResponse->assertStatus(200);
        $adminResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Payments/Index')
            ->has('deposits')
            ->has('stats')
        );

        $gatewaysResponse = $this->actingAs($this->admin)->get(route('admin.payments.gateways'));
        $gatewaysResponse->assertStatus(200);
        $gatewaysResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Payments/Gateways')
            ->has('gateways')
        );
    }

    public function test_admin_can_update_payment_gateway_settings(): void
    {
        $gateway = PaymentGateway::where('driver', 'nobitex')->first();

        $response = $this->actingAs($this->admin)->patch(route('admin.payments.gateways.update', $gateway->id), [
            'is_active' => true,
            'is_default' => true,
            'config' => [
                'api_key' => 'secret_nobitex_token_test_123',
                'base_url' => 'https://api.nobitex.ir',
                'min_deposit' => '2.5000',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $freshGateway = $gateway->fresh();
        $this->assertTrue($freshGateway->is_active);
        $this->assertTrue($freshGateway->is_default);
        $this->assertEquals('secret_nobitex_token_test_123', $freshGateway->getConfig('api_key'));
        $this->assertEquals('2.5000', $freshGateway->getConfig('min_deposit'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'admin.gateway.updated',
            'entity_type' => PaymentGateway::class,
            'entity_id' => $gateway->id,
        ]);
    }

    public function test_idempotency_guard_prevents_double_crediting_on_reverification(): void
    {
        $deposit = $this->paymentService->createDepositRequest($this->user, '20.0000', 'TRON', 'mock');
        $this->paymentService->submitTxHash($deposit, 'idempotent_hash_111', $this->user);

        $this->assertEquals('20.0000', (string) $this->user->wallet()->first()->balance);

        // Re-run verify on already completed deposit
        $result = $this->paymentService->verifyDeposit($deposit->fresh());
        $this->assertTrue($result->isVerified);

        // Balance MUST remain 20.0000, NOT 40.0000
        $this->assertEquals('20.0000', (string) $this->user->wallet()->first()->balance);
        $this->assertEquals(1, CryptoDeposit::where('tx_hash', 'idempotent_hash_111')->count());
    }
}

