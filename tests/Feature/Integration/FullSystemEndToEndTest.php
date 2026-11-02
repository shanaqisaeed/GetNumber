<?php

namespace Tests\Feature\Integration;

use App\Contracts\LedgerServiceInterface;
use App\Contracts\MessageArchiveServiceInterface;
use App\Contracts\MessageServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ContentSecurityViolationException;
use App\Models\ArchivedMessage;
use App\Models\Conversation;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Resilience\CircuitBreaker;
use App\Services\Security\TotpService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FullSystemEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected TotpService $totpService;
    protected WalletServiceInterface $walletService;
    protected LedgerServiceInterface $ledgerService;
    protected MessageServiceInterface $messageService;
    protected MessageArchiveServiceInterface $archiveService;
    protected CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->totpService = app(TotpService::class);
        $this->walletService = app(WalletServiceInterface::class);
        $this->ledgerService = app(LedgerServiceInterface::class);
        $this->messageService = app(MessageServiceInterface::class);
        $this->archiveService = app(MessageArchiveServiceInterface::class);
        $this->circuitBreaker = app(CircuitBreaker::class);
    }

    /**
     * E2E Flow:
     * 1. Register User & Enable TOTP 2FA
     * 2. Log in with 2FA Challenge Interception
     * 3. Allocate Virtual Internal Phone Numbers
     * 4. Perform Wallet Deposit with Double-Entry Accounting
     * 5. Send Single SMS & Verify Bi-directional Outbound/Inbound Atomicity
     * 6. Bulk Send SMS Dispatch
     * 7. Trigger Anti-Phishing Security Filter & Auto-Incident Logging
     * 8. Cold Storage Archival & Recovery Verification
     * 9. System Health & Monitoring Circuit Breaker Fast-Fail
     */
    public function test_complete_platform_lifecycle_end_to_end(): void
    {
        // ---------------------------------------------------------
        // Step 1: User Registration
        // ---------------------------------------------------------
        $registerResponse = $this->post(route('register'), [
            'name' => 'Sara Alizadeh',
            'username' => 'sara_alizadeh',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
        ]);
        $registerResponse->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('username', 'sara_alizadeh')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserRole::User, $user->role);
        $this->assertEquals(UserStatus::Active, $user->status);

        // ---------------------------------------------------------
        // Step 2: Enable and Confirm TOTP 2FA
        // ---------------------------------------------------------
        $enableResponse = $this->actingAs($user)->postJson(route('two-factor.enable'));
        $enableResponse->assertOk();
        $secret = $enableResponse->json('secret');
        $this->assertNotEmpty($secret);

        $validCode = $this->totpService->calculateCode($secret);

        $confirmResponse = $this->actingAs($user)->postJson(route('two-factor.confirm'), [
            'code' => $validCode,
        ]);
        $confirmResponse->assertOk();
        $confirmResponse->assertJson(['success' => true]);

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertCount(8, $user->two_factor_recovery_codes);

        // Logout and verify 2FA Challenge is required
        $this->post(route('logout'));
        $this->assertGuest();

        $loginResponse = $this->post(route('login'), [
            'username' => 'sara_alizadeh',
            'password' => 'SecurePass123!@#',
        ]);
        $loginResponse->assertRedirect(route('two-factor.challenge'));

        // Complete 2FA Challenge with valid TOTP
        $challengeResponse = $this->post(route('two-factor.challenge'), [
            'code' => $this->totpService->calculateCode($user->two_factor_secret),
        ]);
        $challengeResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // ---------------------------------------------------------
        // Step 3: Phone Number Allocation
        // ---------------------------------------------------------
        $userPhone = PhoneNumber::create([
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $recipientUser = User::factory()->create([
            'username' => 'reza_rezaei',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $recipientPhone = PhoneNumber::create([
            'number' => '000010002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $recipientUser->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        // ---------------------------------------------------------
        // Step 4: Wallet Deposit & Double-Entry Accounting
        // ---------------------------------------------------------
        $depositTx = $this->walletService->deposit(
            user: $user,
            amount: '100.0000',
            referenceId: 'DEP-E2E-001',
            description: 'شارژ کیف پول تست یکپارچگی نهایی'
        );

        $this->assertNotNull($depositTx);
        $this->assertEquals('100.0000', (string) $user->getOrCreateWallet()->balance);

        // Verify Journal Entry exists and is strictly balanced
        $journal = JournalEntry::where('reference_id', 'DEP-E2E-001')->first();
        $this->assertNotNull($journal);
        $this->assertTrue($journal->isBalanced());

        // Verify double-entry ledger calculation matches wallet
        $calculatedLedgerBalance = $this->ledgerService->calculateUserBalance($user);
        $this->assertEquals('100.0000', $calculatedLedgerBalance);

        // ---------------------------------------------------------
        // Step 5: Send Single SMS & Delivery Verification
        // ---------------------------------------------------------
        $sendResult = $this->messageService->sendSms(
            fromNumber: $userPhone,
            destinationNumbers: [$recipientPhone->number],
            body: 'سلام رضا، تست ارسال پیام در فاز هفتم با موفقیت انجام شد.',
            senderUser: $user
        );

        $this->assertEquals(1, $sendResult['sent_count']);
        $this->assertEquals(0, $sendResult['failed_count']);

        // Verify bi-directional records: Outbound for sender line, Inbound for recipient line
        $outboundMsg = Message::where('phone_number_id', $userPhone->id)
            ->where('destination_number', $recipientPhone->number)
            ->where('direction', MessageDirection::Outbound)
            ->first();
        $this->assertNotNull($outboundMsg);

        $inboundMsg = Message::where('phone_number_id', $recipientPhone->id)
            ->where('sender', $userPhone->number)
            ->where('direction', MessageDirection::Inbound)
            ->first();
        $this->assertNotNull($inboundMsg);

        // ---------------------------------------------------------
        // Step 6: Bulk Message Queue Dispatch
        // ---------------------------------------------------------
        $thirdUser = User::factory()->create();
        $thirdPhone = PhoneNumber::create([
            'number' => '000010003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $thirdUser->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        // Promote user to standard tier for bulk dispatch
        $user->trust_tier = 'standard';
        $user->save();

        $batch = $this->messageService->queueBulkSms(
            fromNumber: $userPhone,
            destinationNumbers: [$recipientPhone->number, $thirdPhone->number],
            body: 'اطلاعیه انبوه سامانه',
            senderUser: $user
        );

        $this->assertNotNull($batch);
        $this->assertEquals(2, $batch->total_count);
        $this->assertEquals('pending', $batch->status);

        // ---------------------------------------------------------
        // Step 7: Anti-Phishing Security Filter & Auto Incident Logging
        // ---------------------------------------------------------
        $caughtViolation = false;
        try {
            $this->messageService->sendSms(
                fromNumber: $userPhone,
                destinationNumbers: [$recipientPhone->number],
                body: 'ابلاغیه قضایی سامانه ثنا: جهت مشاهده شکواییه به نشانی http://192.168.1.1 مراجعه نمایید',
                senderUser: $user
            );
        } catch (ContentSecurityViolationException $e) {
            $caughtViolation = true;
            $this->assertStringContainsString('فیشینگ', $e->getMessage());
        }

        $this->assertTrue($caughtViolation, 'ContentSecurityFilter failed to intercept phishing URL & keyword!');

        // Check that a security incident was automatically recorded
        $incident = SecurityIncident::where('user_id', $user->id)
            ->where('type', 'phishing_attempt')
            ->first();
        $this->assertNotNull($incident);
        $this->assertEquals('high', $incident->severity);

        // ---------------------------------------------------------
        // Step 8: Database Cold Storage Archival & Recovery
        // ---------------------------------------------------------
        // Backdate outbound message to 120 days ago
        DB::table('messages')->where('id', $outboundMsg->id)->update([
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $archiveResult = $this->archiveService->archiveOlderThan(days: 90, chunkSize: 50, dryRun: false);
        $this->assertGreaterThanOrEqual(1, $archiveResult['archived_count']);

        // Assert message moved from active to cold storage
        $this->assertDatabaseMissing('messages', ['id' => $outboundMsg->id]);
        $this->assertDatabaseHas('archived_messages', ['id' => $outboundMsg->id]);

        // Restore message from cold storage back to active table
        $archivedModel = ArchivedMessage::find($outboundMsg->id);
        $this->assertNotNull($archivedModel);
        $restored = $this->archiveService->restoreArchivedMessage($outboundMsg->id);
        $this->assertTrue($restored);
        $this->assertDatabaseHas('messages', ['id' => $outboundMsg->id]);
        $this->assertDatabaseMissing('archived_messages', ['id' => $outboundMsg->id]);

        // ---------------------------------------------------------
        // Step 9: System Health & Circuit Breaker Resilience
        // ---------------------------------------------------------
        $healthResponse = $this->getJson(route('health.ready'));
        $healthResponse->assertOk();
        $healthResponse->assertJson([
            'status' => 'READY',
            'checks' => [
                'database' => ['status' => 'UP'],
                'cache' => ['status' => 'UP'],
                'storage' => ['status' => 'UP'],
            ],
        ]);

        // Verify Circuit Breaker Trips and Fast-fails
        $circuitService = 'tron_grid_rpc_cluster';
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute($circuitService, function () {
                    throw new Exception("RPC Connection Timeout");
                }, failureThreshold: 3, cooldownSeconds: 5);
            } catch (Exception) {}
        }

        $this->assertEquals(CircuitBreaker::STATE_OPEN, $this->circuitBreaker->getState($circuitService, 5));

        // Fallback test under open state
        $fallbackResult = $this->circuitBreaker->execute(
            serviceName: $circuitService,
            action: function () { return 'normal_value'; },
            failureThreshold: 3,
            cooldownSeconds: 5,
            fallback: function () { return 'cached_fallback_response'; }
        );
        $this->assertEquals('cached_fallback_response', $fallbackResult);
    }
}
