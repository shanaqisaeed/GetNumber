<?php

namespace Tests\Feature\Numbers;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\InvalidNumberFormatException;
use App\Exceptions\NumberAlreadyAllocatedException;
use App\Exceptions\UserQuotaExceededException;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneNumberAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected PhoneNumberAllocationServiceInterface $allocationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocationService = app(PhoneNumberAllocationServiceInterface::class);
    }

    public function test_leading_zeros_are_strictly_preserved_as_string(): void
    {
        $phone = PhoneNumber::create([
            'number' => '000012345',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $this->assertSame('000012345', $phone->fresh()->number);
        $this->assertSame('+85000012345', $phone->fresh()->formatted_number);
    }

    public function test_effective_title_uses_display_title_or_formatted_number(): void
    {
        $phoneWithTitle = PhoneNumber::create([
            'number' => '000111222',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'display_title' => 'پشتیبانی مرکزی',
        ]);

        $phoneWithoutTitle = PhoneNumber::create([
            'number' => '000333444',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'display_title' => null,
        ]);

        $this->assertSame('پشتیبانی مرکزی', $phoneWithTitle->effective_title);
        $this->assertSame('+85000333444', $phoneWithoutTitle->effective_title);
    }

    public function test_atomic_allocation_from_available_pool(): void
    {
        $user = User::factory()->create();

        $poolNumber = PhoneNumber::create([
            'number' => '000999888',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $allocated = $this->allocationService->allocateStandardNumberToUser($user);

        $this->assertSame($poolNumber->id, $allocated->id);
        $this->assertSame($user->id, $allocated->user_id);
        $this->assertSame(PhoneNumberStatus::Allocated, $allocated->status);
        $this->assertNotNull($allocated->allocated_at);

        // Verify audit log
        $this->assertDatabaseHas('phone_number_audits', [
            'phone_number_id' => $allocated->id,
            'action' => 'allocated',
            'to_status' => PhoneNumberStatus::Allocated->value,
        ]);
    }

    public function test_automatic_number_generation_when_pool_is_empty(): void
    {
        $user = User::factory()->create();

        // Ensure pool has no available numbers
        PhoneNumber::query()->delete();

        $allocated = $this->allocationService->allocateStandardNumberToUser($user);

        $this->assertNotNull($allocated);
        $this->assertSame(9, strlen($allocated->number));
        $this->assertSame($user->id, $allocated->user_id);
        $this->assertSame(PhoneNumberStatus::Allocated, $allocated->status);
    }

    public function test_allocation_of_already_allocated_number_throws_exception(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        PhoneNumber::create([
            'number' => '000777888',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user1->id,
            'allocated_at' => now(),
        ]);

        $this->expectException(NumberAlreadyAllocatedException::class);
        $this->allocationService->allocateStandardNumberToUser($user2, '000777888');
    }

    public function test_user_quota_limit_is_enforced(): void
    {
        $user = User::factory()->create();

        // Create 10 active numbers for this user
        for ($i = 0; $i < 10; $i++) {
            PhoneNumber::create([
                'number' => str_pad((string) $i, 9, '0', STR_PAD_LEFT),
                'type' => PhoneNumberType::UserStandard,
                'status' => PhoneNumberStatus::Allocated,
                'user_id' => $user->id,
                'allocated_at' => now(),
            ]);
        }

        $this->expectException(UserQuotaExceededException::class);
        $this->allocationService->allocateStandardNumberToUser($user);
    }

    public function test_number_release_returns_it_to_available_pool(): void
    {
        $user = User::factory()->create();
        $phone = PhoneNumber::create([
            'number' => '000123999',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
            'display_title' => 'عنوان قدیمی',
        ]);

        $released = $this->allocationService->releaseNumber($phone, $user, 'تست آزادسازی');

        $this->assertNull($released->user_id);
        $this->assertNull($released->allocated_at);
        $this->assertNull($released->display_title);
        $this->assertSame(PhoneNumberStatus::Available, $released->status);

        $this->assertDatabaseHas('phone_number_audits', [
            'phone_number_id' => $phone->id,
            'action' => 'released',
            'to_status' => PhoneNumberStatus::Available->value,
        ]);
    }

    public function test_service_number_allocation_validates_length(): void
    {
        $user = User::factory()->create();

        // Valid 4-digit
        $servicePhone = $this->allocationService->allocateServiceNumberToUser(
            $user,
            '1000',
            'سرشماره تست'
        );
        $this->assertSame('1000', $servicePhone->number);
        $this->assertSame(PhoneNumberType::UserService, $servicePhone->type);

        // Invalid 3-digit throws exception
        $this->expectException(InvalidNumberFormatException::class);
        $this->allocationService->allocateServiceNumberToUser($user, '123', 'نامعتبر');
    }

    public function test_batch_generator_creates_unique_numbers_with_leading_zeros(): void
    {
        $created = $this->allocationService->generateBatchAvailableNumbers(5, PhoneNumberType::UserStandard, 9);

        $this->assertSame(5, $created);
        $this->assertDatabaseCount('phone_numbers', 5);

        foreach (PhoneNumber::all() as $phone) {
            $this->assertSame(9, strlen($phone->number));
            $this->assertSame(PhoneNumberStatus::Available, $phone->status);
        }
    }

    public function test_has_available_standard_number_accurately_detects_pool_state(): void
    {
        PhoneNumber::query()->delete();

        // When pool is completely empty
        $this->assertFalse($this->allocationService->hasAvailableStandardNumber());

        // When only a UserService number is available
        PhoneNumber::create([
            'number' => '4000',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Available,
        ]);
        $this->assertFalse($this->allocationService->hasAvailableStandardNumber());

        // When a UserStandard number is created but allocated
        $user = User::factory()->create();
        PhoneNumber::create([
            'number' => '000123456',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
        ]);
        $this->assertFalse($this->allocationService->hasAvailableStandardNumber());

        // When a UserStandard number is available
        PhoneNumber::create([
            'number' => '000987654',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);
        $this->assertTrue($this->allocationService->hasAvailableStandardNumber());
    }

    public function test_standard_allocation_strictly_prioritizes_available_user_standard_pool_and_ignores_other_types(): void
    {
        PhoneNumber::query()->delete();
        $user = User::factory()->create();

        // Create an available service number (should NOT be picked for standard allocation)
        $servicePoolNumber = PhoneNumber::create([
            'number' => '7777',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Available,
        ]);

        // Create two available standard numbers: first and second
        $firstAvailable = PhoneNumber::create([
            'number' => '000111000',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $secondAvailable = PhoneNumber::create([
            'number' => '000222000',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        // Allocating standard number should pick the first available UserStandard number
        $allocated1 = $this->allocationService->allocateStandardNumberToUser($user);
        $this->assertSame($firstAvailable->id, $allocated1->id);
        $this->assertSame('000111000', $allocated1->number);
        $this->assertSame(PhoneNumberStatus::Allocated, $allocated1->status);

        // Service number must remain available and untouched
        $this->assertSame(PhoneNumberStatus::Available, $servicePoolNumber->fresh()->status);
        $this->assertNull($servicePoolNumber->fresh()->user_id);

        // Next allocation should pick the second available UserStandard number
        $allocated2 = $this->allocationService->allocateStandardNumberToUser($user);
        $this->assertSame($secondAvailable->id, $allocated2->id);
        $this->assertSame('000222000', $allocated2->number);

        // Now pool is exhausted for UserStandard; next allocation must auto-generate a new 9-digit number
        $this->assertFalse($this->allocationService->hasAvailableStandardNumber());
        $allocated3 = $this->allocationService->allocateStandardNumberToUser($user);
        $this->assertSame(9, strlen($allocated3->number));
        $this->assertNotSame($firstAvailable->id, $allocated3->id);
        $this->assertNotSame($secondAvailable->id, $allocated3->id);
        $this->assertSame(PhoneNumberType::UserStandard, $allocated3->type);
    }
}

