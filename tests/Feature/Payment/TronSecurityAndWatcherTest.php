<?php

namespace Tests\Feature\Payment;

use App\Contracts\BlockchainWatcherServiceInterface;
use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Gateways\TronScanGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TronSecurityAndWatcherTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PaymentServiceInterface $paymentService;
    protected BlockchainWatcherServiceInterface $watcherService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = app(PaymentServiceInterface::class);
        $this->watcherService = app(BlockchainWatcherServiceInterface::class);

        SystemSetting::set('trx_to_coin_rate', '1.0000', 'نرخ تبدیل TRX به COIN');
        SystemSetting::set('number_price_coin', '1.0000', 'قیمت پایه هر شماره به COIN');

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_tron_gateway_throws_exception_when_deposit_address_missing_instead_of_fake_address(): void
    {
        $gatewayModel = PaymentGateway::where('driver', 'tron_grid')->first();
        $gatewayModel->update([
            'config' => [
                'base_url' => 'https://api.trongrid.io',
                'deposit_address' => '', // Empty address
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('آدرس والت دریافت ترون (TRX) در تنظیمات درگاه ترون گرید تنظیم نشده است');

        $gateway = new \App\Services\Gateways\TronGridGateway($gatewayModel);
        $deposit = CryptoDeposit::create([
            'user_id' => $this->user->id,
            'gateway_driver' => 'tron_grid',
            'tracking_code' => 'DEP-TEST-NOADDR',
            'requested_amount' => '10.0000',
            'coin_amount' => '10.0000',
            'deposit_address' => 'PENDING',
        ]);

        $gateway->generateDepositAddress($deposit);
    }

    public function test_tron_scan_rejects_invalid_hex_tx_hash(): void
    {
        $gatewayModel = PaymentGateway::where('driver', 'tron_grid')->first();
        $gatewayModel->update([
            'config' => [
                'base_url' => 'https://apilist.tronscanapi.com',
                'deposit_address' => 'TNPeeaaTK7K93GdchkZypSfNJfqVCHqwY8',
            ],
        ]);

        $gateway = new TronScanGateway($gatewayModel);
        $deposit = CryptoDeposit::create([
            'user_id' => $this->user->id,
            'gateway_driver' => 'tron_scan',
            'tracking_code' => 'DEP-TEST-INVALID-HEX',
            'requested_amount' => '20.0000',
            'coin_amount' => '20.0000',
            'deposit_address' => 'TNPeeaaTK7K93GdchkZypSfNJfqVCHqwY8',
        ]);

        // Non-hex string (e.g. contains 'Z' or too short)
        $result = $gateway->verifyTransaction($deposit, 'not_a_valid_64_char_hex_txid_hash_1234567890abcdef');
        $this->assertFalse($result->isVerified);
        $this->assertEquals('Rejected', $result->status);
        $this->assertStringContainsString('۶۴ نویسه هگزادسیمال', $result->failureReason);
    }

    public function test_tron_scan_requires_nineteen_confirmations_before_crediting(): void
    {
        $realDepositAddress = 'TNPeeaaTK7K93GdchkZypSfNJfqVCHqwY8';
        $gatewayModel = PaymentGateway::where('driver', 'tron_grid')->first();
        $gatewayModel->update([
            'config' => [
                'base_url' => 'https://apilist.tronscanapi.com',
                'deposit_address' => $realDepositAddress,
            ],
        ]);

        $validHexHash = 'e82b79e8633390cbe0a4f526b48fb5f0967d6c5bb5d29255855f72cf730e62d2';

        Http::fake([
            'https://apilist.tronscanapi.com/api/transaction-info*' => Http::response([
                'contractRet' => 'SUCCESS',
                'confirmed' => true,
                'confirmations' => 5, // Less than required 19 confirmations
                'contractData' => [
                    'amount' => 50000000, // 50 TRX
                    'to_address' => $realDepositAddress,
                    'owner_address' => 'TSenderAddress77777777777777777777',
                ],
                'timestamp' => now()->getTimestampMs(),
            ], 200),
        ]);

        $gateway = new TronScanGateway($gatewayModel);
        $deposit = CryptoDeposit::create([
            'user_id' => $this->user->id,
            'gateway_driver' => 'tron_scan',
            'tracking_code' => 'DEP-TEST-CONFIRMATIONS',
            'requested_amount' => '50.0000',
            'coin_amount' => '50.0000',
            'deposit_address' => $realDepositAddress,
        ]);

        $result = $gateway->verifyTransaction($deposit, $validHexHash);

        $this->assertFalse($result->isVerified);
        $this->assertEquals('Confirming', $result->status);
        $this->assertStringContainsString('5/19 تأیید', $result->failureReason);
    }

    public function test_blockchain_watcher_auto_matches_incoming_transfer_to_pending_deposit(): void
    {
        // Set up mock gateway to act as an active gateway with mock incoming deposits
        PaymentGateway::where('driver', 'mock')->update([
            'is_active' => true,
            'config' => [
                'auto_confirm' => true,
                'mock_transfers' => [
                    [
                        'tx_hash' => 'mock_auto_detected_tx_hash_888',
                        'amount' => '40.0000',
                        'to_address' => 'TMockAddressToMatch999',
                        'timestamp' => now()->getTimestampMs(),
                    ],
                ],
            ],
        ]);

        // Create pending deposit with matching address and amount
        $deposit = CryptoDeposit::create([
            'user_id' => $this->user->id,
            'payment_gateway_id' => PaymentGateway::where('driver', 'mock')->first()->id,
            'gateway_driver' => 'mock',
            'tracking_code' => 'DEP-WATCHER-001',
            'currency' => 'TRX',
            'network' => 'TRON',
            'requested_amount' => '40.0000',
            'coin_amount' => '40.0000',
            'trx_to_coin_rate' => '1.0000',
            'deposit_address' => 'TMockAddressToMatch999',
            'status' => PaymentStatus::PendingTransfer,
            'expires_at' => now()->addHours(2),
        ]);

        $stats = $this->watcherService->scanAndMatchIncomingDeposits(limit: 10);

        $this->assertEquals(1, $stats['auto_matched_deposits']);
        $this->assertEquals(1, $stats['completed']);

        $freshDeposit = $deposit->fresh();
        $this->assertEquals(PaymentStatus::Completed, $freshDeposit->status);
        $this->assertEquals('mock_auto_detected_tx_hash_888', $freshDeposit->tx_hash);
        $this->assertEquals('40.0000', (string) $this->user->getOrCreateWallet()->balance);
    }
}

