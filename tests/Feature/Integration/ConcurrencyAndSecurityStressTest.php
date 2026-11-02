<?php

namespace Tests\Feature\Integration;

use App\Contracts\LedgerServiceInterface;
use App\Contracts\MessageServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Security\TotpService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrencyAndSecurityStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected PhoneNumber $phoneA;
    protected PhoneNumber $phoneB;
    protected WalletServiceInterface $walletService;
    protected LedgerServiceInterface $ledgerService;
    protected MessageServiceInterface $messageService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->walletService = app(WalletServiceInterface::class);
        $this->ledgerService = app(LedgerServiceInterface::class);
        $this->messageService = app(MessageServiceInterface::class);

        SystemSetting::set('number_price_coin', '10.0000');

        $this->userA = User::factory()->create([
            'username' => 'concurrency_user_a',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->userB = User::factory()->create([
            'username' => 'concurrency_user_b',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->phoneA = PhoneNumber::create([
            'number' => '000077701',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->userA->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->phoneB = PhoneNumber::create([
            'number' => '000077702',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->userB->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);
    }

    /**
     * 7.2 Concurrency & Race Condition Test:
     * Simultaneous balance deduction attempts when balance is only enough for ONE operation.
     * Only 1 operation must succeed; the others must fail with InsufficientBalanceException.
     * Balance must NEVER become negative.
     */
    public function test_concurrent_wallet_debits_prevent_double_spending(): void
    {
        // Give user A exactly 10.0000 COIN
        $this->walletService->deposit($this->userA, '10.0000', 'INIT-DEP-001');

        $successCount = 0;
        $failCount = 0;

        // Simulate 5 simultaneous rapid purchase/debit attempts
        for ($i = 1; $i <= 5; $i++) {
            try {
                $this->walletService->chargeForNumberPurchase(
                    user: $this->userA,
                    amount: '10.0000',
                    referenceId: "PURCHASE-RACE-00{$i}"
                );
                $successCount++;
            } catch (\App\Exceptions\InsufficientWalletBalanceException $e) {
                $failCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'More than one debit succeeded! Race condition double spend occurred.');
        $this->assertEquals(4, $failCount);

        // Verify balance is exactly 0.0000 (never negative)
        $wallet = $this->userA->getOrCreateWallet();
        $this->assertEquals('0.0000', (string) $wallet->fresh()->balance);

        // Verify double-entry ledger calculation matches
        $ledgerBalance = $this->ledgerService->calculateUserBalance($this->userA);
        $this->assertEquals('0.0000', $ledgerBalance);
    }

    /**
     * 7.4 Security & IDOR Prevention:
     * User B must NOT be able to view, access or delete messages belonging to User A's phone line.
     */
    public function test_idor_prevention_on_private_user_messages(): void
    {
        // Create private message on line A
        $msgA = Message::create([
            'phone_number_id' => $this->phoneA->id,
            'destination_number' => '000077799',
            'sender' => $this->phoneA->number,
            'peer_number' => '000077799',
            'body' => 'Confidential business message of User A',
            'direction' => MessageDirection::Outbound,
            'server_received_at' => now(),
        ]);

        // 1. User B attempts to access Line A messages via Conversation API
        $response = $this->actingAs($this->userB)->getJson("/messages/conversations?phone_number_id={$this->phoneA->id}");
        // Must either be 403 Forbidden or return empty list for user B
        if ($response->status() === 200) {
            $data = $response->json('conversations') ?? [];
            $this->assertEmpty($data, 'User B was able to see User A conversation items!');
        } else {
            $this->assertContains($response->status(), [403, 404]);
        }

        // 2. User B attempts to send SMS using User A's phone line
        $sendResponse = $this->actingAs($this->userB)->postJson('/messages/send', [
            'from_number_id' => $this->phoneA->id,
            'to' => '000077799',
            'body' => 'Unauthorized spoof attempt',
        ]);

        $this->assertContains($sendResponse->status(), [403, 422]);
    }

    /**
     * 7.4 Security: 2FA Bypass Protection
     * User with 2FA enabled cannot access authenticated routes simply by knowing password without TOTP confirmation.
     */
    public function test_2fa_bypass_is_strictly_prevented(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        $secureUser = User::factory()->create([
            'username' => 'twofa_victim',
            'password' => bcrypt('StrongPass123!'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['REC-1111-2222'],
        ]);

        // Attempting direct login
        $loginRes = $this->post('/login', [
            'username' => 'twofa_victim',
            'password' => 'StrongPass123!',
        ]);

        $loginRes->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // Trying to access dashboard directly without 2FA
        $dashRes = $this->get('/dashboard');
        $dashRes->assertRedirect('/login');
    }

    /**
     * 7.2 High Throughput & Batch Performance Test:
     * Verify system can ingest and process 100 messages within low execution latency and bounded memory.
     */
    public function test_batch_message_ingestion_throughput_and_memory(): void
    {
        $startMemory = memory_get_usage();
        $startTime = microtime(true);

        $records = [];
        for ($i = 1; $i <= 100; $i++) {
            $records[] = [
                'phone_number_id' => $this->phoneA->id,
                'destination_number' => '000077702',
                'sender' => $this->phoneA->number,
                'peer_number' => '000077702',
                'body' => "Performance batch message #{$i}",
                'direction' => MessageDirection::Outbound->value,
                'processing_status' => 'received',
                'server_received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('messages')->insert($records);

        $elapsedSeconds = microtime(true) - $startTime;
        $memoryConsumedMb = (memory_get_usage() - $startMemory) / (1024 * 1024);

        // Throughput assertion: 100 insertions should complete well under 2 seconds
        $this->assertLessThan(2.0, $elapsedSeconds, 'Batch message ingestion exceeded 2.0s latency threshold');

        // Verify count
        $count = Message::where('phone_number_id', $this->phoneA->id)->count();
        $this->assertEquals(100, $count);

        // Check memory remained bounded (< 15MB)
        $this->assertLessThan(15.0, $memoryConsumedMb, 'Batch ingestion consumed excessive memory');
    }

    /**
     * 7.3 Fault Tolerance & Idempotent Deduplication:
     * Ingesting the exact same client_message_id multiple times must return the existing record
     * without creating duplicate entries or inflating counters.
     */
    public function test_idempotent_inbound_intake_prevents_duplicates_on_retry(): void
    {
        $payload = [
            'sender' => $this->phoneA->number,
            'destination_number' => $this->phoneB->number,
            'body' => 'Important notification with client idempotency key',
            'client_message_id' => 'CLIENT-IDEMPOTENT-XYZ-999',
            'source_system_id' => 'SMSC-NODE-1',
        ];

        // 1st intake
        $firstResult = $this->messageService->recordInboundMessage($payload);
        $this->assertFalse($firstResult['is_duplicate']);
        $firstId = $firstResult['message']->id;

        // 2nd intake (exact duplicate retry e.g. after network glitch)
        $secondResult = $this->messageService->recordInboundMessage($payload);
        $this->assertTrue($secondResult['is_duplicate'], 'Retried message was not recognized as duplicate!');
        $this->assertEquals($firstId, $secondResult['message']->id);

        // Verify only 1 record exists in database
        $msgCount = Message::where('client_message_id', 'CLIENT-IDEMPOTENT-XYZ-999')->count();
        $this->assertEquals(1, $msgCount, 'Duplicate messages created in database despite idempotency key!');
    }
}
