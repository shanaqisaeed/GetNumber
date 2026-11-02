<?php

namespace Tests\Feature\Integration;

use App\Contracts\LedgerServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\ProcessOutboundMessageJob;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\SystemErrorLog;
use App\Models\User;
use App\Services\MessageService;
use App\Services\Resilience\CircuitBreaker;
use Exception;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FaultToleranceAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PhoneNumber $phoneNumber;
    protected CircuitBreaker $circuitBreaker;
    protected WalletServiceInterface $walletService;
    protected LedgerServiceInterface $ledgerService;
    protected MessageService $messageService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->circuitBreaker = new CircuitBreaker();
        $this->walletService = app(WalletServiceInterface::class);
        $this->ledgerService = app(LedgerServiceInterface::class);
        $this->messageService = app(MessageService::class);

        $this->user = User::factory()->create([
            'username' => 'chaos_tester',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->phoneNumber = PhoneNumber::create([
            'number' => '000088801',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);
    }

    /**
     * 7.3 Chaos Test:
     * When WebSocket / Reverb server is offline or throws BroadcastException,
     * MessageService MUST NOT crash or fail the user's message.
     * The SMS must be recorded in the database, and the error gracefully handled.
     */
    public function test_message_service_survives_websocket_broadcaster_failure(): void
    {
        // Force broadcast dispatch to throw BroadcastException
        Event::listen(\App\Events\MessageReceived::class, function () {
            throw new BroadcastException("Reverb connection refused at 127.0.0.1:8080");
        });

        $destNumber = PhoneNumber::create([
            'number' => '000088802',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => User::factory()->create()->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        // Sending message should still succeed despite broadcaster failure
        $result = $this->messageService->sendSms(
            fromNumber: $this->phoneNumber,
            destinationNumbers: [$destNumber->number],
            body: 'Message sent during WebSocket server outage',
            senderUser: $this->user
        );

        $this->assertEquals(1, $result['sent_count']);

        // Assert message exists in DB
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->phoneNumber->id,
            'body' => 'Message sent during WebSocket server outage',
        ]);
    }

    /**
     * 7.3 Resilience & Idempotent Job Execution:
     * When a queue job fails halfway or is retried by Supervisor/Queue Worker,
     * executing ProcessMessageBatchJob twice MUST NOT create duplicate records.
     */
    public function test_queue_job_replay_is_strictly_idempotent(): void
    {
        $destNumber = PhoneNumber::create([
            'number' => '000088803',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => User::factory()->create()->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->user->trust_tier = 'standard';
        $this->user->save();

        $batch = $this->messageService->queueBulkSms(
            fromNumber: $this->phoneNumber,
            destinationNumbers: [$destNumber->number],
            body: 'Mission-critical notification job',
            senderUser: $this->user
        );

        $this->assertEquals(1, $batch->total_count);

        // 1. First execution of the batch job
        $job1 = new \App\Jobs\ProcessMessageBatchJob($batch->id);
        $job1->handle();

        $batch->refresh();
        $this->assertEquals(\App\Models\MessageBatch::STATUS_COMPLETED, $batch->status);
        $initialCount = Message::where('phone_number_id', $this->phoneNumber->id)
            ->where('body', 'Mission-critical notification job')
            ->count();
        $this->assertEquals(1, $initialCount);

        // 2. Replay execution (simulating network retry or worker kill)
        $job2 = new \App\Jobs\ProcessMessageBatchJob($batch->id);
        $job2->handle();

        $afterRetryCount = Message::where('phone_number_id', $this->phoneNumber->id)
            ->where('body', 'Mission-critical notification job')
            ->count();
        $this->assertEquals(1, $afterRetryCount, 'Job replay created duplicate message!');
    }

    /**
     * 7.3 Circuit Breaker Full Life-Cycle Test:
     * CLOSED -> 3 Failures -> OPEN (Fast-fail) -> Cooldown -> HALF_OPEN (Trial) -> Success -> CLOSED (Recovered)
     */
    public function test_circuit_breaker_full_recovery_lifecycle(): void
    {
        $service = 'external_crypto_rpc_node';
        $threshold = 3;
        $cooldown = 1; // 1 second

        // Phase A: CLOSED - normal failures
        for ($i = 0; $i < $threshold; $i++) {
            try {
                $this->circuitBreaker->execute($service, function () {
                    throw new Exception("RPC 504 Gateway Timeout");
                }, $threshold, $cooldown);
            } catch (Exception) {}
        }

        // Breaker must now be OPEN
        $this->assertEquals(CircuitBreaker::STATE_OPEN, $this->circuitBreaker->getState($service, $cooldown));

        // In OPEN state, calls fast fail immediately without calling closure
        $executed = false;
        try {
            $this->circuitBreaker->execute($service, function () use (&$executed) {
                $executed = true;
            }, $threshold, $cooldown);
        } catch (\App\Exceptions\CircuitBreakerOpenException) {}

        $this->assertFalse($executed, 'Closure was executed while Circuit Breaker was OPEN!');

        // Phase B: Wait for cooldown to expire
        sleep(2);

        // Breaker should now enter HALF_OPEN
        $this->assertEquals(CircuitBreaker::STATE_HALF_OPEN, $this->circuitBreaker->getState($service, $cooldown));

        // Phase C: A successful trial call recovers the breaker back to CLOSED
        $result = $this->circuitBreaker->execute($service, function () {
            return 'recovered_payload_data';
        }, $threshold, $cooldown);

        $this->assertEquals('recovered_payload_data', $result);
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $this->circuitBreaker->getState($service, $cooldown));
    }

    /**
     * 7.3 Transaction Atomicity:
     * If an unexpected exception happens during a financial operation,
     * the database transaction must roll back cleanly without leaving orphan records.
     */
    public function test_financial_transaction_rolls_back_completely_on_failure(): void
    {
        $this->walletService->deposit($this->user, '50.0000', 'INIT-TX-50');

        $initialWalletBalance = (string) $this->user->getOrCreateWallet()->balance;
        $initialLedgerEntriesCount = DB::table('ledger_entries')->count();
        $initialJournalEntriesCount = DB::table('journal_entries')->count();

        // Attempt a transaction that fails halfway through
        $caught = false;
        try {
            DB::transaction(function () {
                $wallet = $this->user->getOrCreateWallet();
                $wallet->decrement('balance', 20);

                // Simulate unexpected failure before journal entry is committed
                throw new Exception("Simulated disk error during transaction");
            });
        } catch (Exception) {
            $caught = true;
        }

        $this->assertTrue($caught);

        // Assert database state is completely intact
        $this->assertEquals($initialWalletBalance, (string) $this->user->fresh()->getOrCreateWallet()->balance);
        $this->assertEquals($initialLedgerEntriesCount, DB::table('ledger_entries')->count());
        $this->assertEquals($initialJournalEntriesCount, DB::table('journal_entries')->count());
    }
}
