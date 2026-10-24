<?php

namespace Tests\Feature\Numbers;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicPrefixAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected PhoneNumberAllocationServiceInterface $allocationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocationService = app(PhoneNumberAllocationServiceInterface::class);
    }

    public function test_dynamic_prefix_resolution_and_custom_prefix_allocation(): void
    {
        $user = User::factory()->create();

        // 1. Create a custom active prefix (+98)
        $prefix98 = PhonePrefix::create([
            'prefix' => '+98',
            'clean_prefix' => '98',
            'name' => 'پیش‌شماره ملی ایران',
            'country_code' => 'IR',
            'is_default' => false,
            'is_active' => true,
        ]);

        // 2. Allocate standard number using +98 prefix
        $phone = $this->allocationService->allocateStandardNumberToUser(
            user: $user,
            preferredNumber: '000777888',
            displayTitle: 'خط ملی سازمان',
            prefix: $prefix98
        );

        $this->assertSame($prefix98->id, $phone->phone_prefix_id);
        $this->assertSame('+98000777888', $phone->formatted_number);
        $this->assertSame('خط ملی سازمان', $phone->effective_title);

        // 3. Verify PhoneNumberNormalizer dynamically resolves input with +98 or 98
        $this->assertSame('000777888', PhoneNumberNormalizer::resolveToStoredNumber('+98000777888'));
        $this->assertSame('000777888', PhoneNumberNormalizer::resolveToStoredNumber('98000777888'));
        $this->assertSame('000777888', PhoneNumberNormalizer::resolveToStoredNumber('000777888'));
    }

    public function test_privacy_leak_prevention_on_line_release(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 1. Allocate number to User 1
        $phone = $this->allocationService->allocateStandardNumberToUser($user1, '000111222');

        // 2. User 1 receives private inbound messages
        Message::create([
            'phone_number_id' => $phone->id,
            'destination_number' => $phone->number,
            'sender' => 'BANK_OTP',
            'body' => 'کد محرمانه حساب بانکی: ۹۸۷۶۵۴',
            'server_received_at' => now(),
            'source_system_id' => 'SYS-BANK',
            'client_message_id' => 'SEC-001',
        ]);

        $this->assertSame(1, Message::forUser($user1)->count());
        $this->assertSame(1, $phone->messages()->count());

        // 3. User 1 releases the line
        $this->allocationService->releaseNumber($phone, $user1, 'خاتمه اشتراک کاربر اول');

        // 4. CRITICAL PRIVACY VERIFICATION:
        // Prior messages belonging to this line must be purged
        $this->assertSame(0, $phone->fresh()->messages()->count());
        $this->assertDatabaseMissing('messages', [
            'client_message_id' => 'SEC-001',
        ]);

        // 5. Line is now allocated to User 2
        $reallocated = $this->allocationService->allocateStandardNumberToUser($user2, '000111222');
        $this->assertSame($phone->id, $reallocated->id);
        $this->assertSame($user2->id, $reallocated->user_id);

        // 6. User 2 must NOT see any messages from User 1!
        $this->assertSame(0, Message::forUser($user2)->count());
        $this->assertSame(0, $reallocated->messages()->count());
    }

    public function test_number_reservation_and_auto_expiration(): void
    {
        $user = User::factory()->create();

        $phone = PhoneNumber::create([
            'number' => '000555666',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        // 1. Reserve number for user for 3 days
        $reservedUntil = now()->addDays(3);
        $reserved = $this->allocationService->reserveNumber($phone, $user, $reservedUntil);

        $this->assertSame(PhoneNumberStatus::Reserved, $reserved->status);
        $this->assertTrue($reserved->isReserved());
        $this->assertFalse($reserved->isExpired());
        $this->assertSame($user->id, $reserved->user_id);

        // 2. Fast forward time past reservation expiration
        $reserved->update([
            'reserved_until' => now()->subMinute(),
        ]);

        // Process expired reservations
        $processedCount = $this->allocationService->processExpiredNumbers();
        $this->assertSame(1, $processedCount);

        // Line should return to available pool
        $fresh = $phone->fresh();
        $this->assertSame(PhoneNumberStatus::Available, $fresh->status);
        $this->assertNull($fresh->user_id);
    }

    public function test_allocated_line_expiration_lifecycle(): void
    {
        $user = User::factory()->create();

        $phone = $this->allocationService->allocateStandardNumberToUser($user, '000444555');

        // Set line expiration date
        $phone->update([
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($phone->isExpired());

        // Fast forward past expires_at
        $phone->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($phone->fresh()->isExpired());

        // Process expiration sweep
        $count = $this->allocationService->processExpiredNumbers();
        $this->assertSame(1, $count);

        $this->assertSame(PhoneNumberStatus::Expired, $phone->fresh()->status);
    }
}
