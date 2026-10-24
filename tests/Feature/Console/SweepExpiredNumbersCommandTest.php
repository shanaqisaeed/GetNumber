<?php

namespace Tests\Feature\Console;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SweepExpiredNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sweep_command_processes_expired_reservations_and_allocations(): void
    {
        $user = User::factory()->create();
        $allocationService = app(PhoneNumberAllocationServiceInterface::class);

        // 1. Create a reserved number whose reservation has expired
        $reservedPhone = PhoneNumber::create([
            'number' => '000999111',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);
        $allocationService->reserveNumber($reservedPhone, $user, now()->subHour());

        // 2. Create an allocated number whose active validity has expired
        $allocatedPhone = $allocationService->allocateStandardNumberToUser($user, '000999222');
        $allocatedPhone->update([
            'expires_at' => now()->subDay(),
        ]);

        // 3. Execute artisan command
        $this->artisan('numbers:sweep-expired')
            ->expectsOutputToContain('در حال بررسی و پردازش خطوط منقضی‌شده سامانه...')
            ->expectsOutputToContain('تعداد 2 شماره منقضی‌شده با موفقیت پردازش و وضعیت آن‌ها به‌روزرسانی گردید.')
            ->assertExitCode(0);

        // 4. Verify database state
        $this->assertSame(PhoneNumberStatus::Available, $reservedPhone->fresh()->status);
        $this->assertNull($reservedPhone->fresh()->user_id);

        $this->assertSame(PhoneNumberStatus::Expired, $allocatedPhone->fresh()->status);
    }
}
