<?php

namespace Database\Seeders;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PhonePrefixSeeder::class);

        // Default System Administrator
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'راهبر ارشد سامانه',
                'password' => Hash::make('Admin@123456'),
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ]
        );

        // Default Standard Enterprise User
        $user = User::updateOrCreate(
            ['username' => 'user'],
            [
                'name' => 'کاربر سازمانی نمونه',
                'password' => Hash::make('User@123456'),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]
        );

        // Seed an allocated standard number with leading zeros to user
        PhoneNumber::updateOrCreate(
            ['number' => '000100200'],
            [
                'type' => PhoneNumberType::UserStandard,
                'status' => PhoneNumberStatus::Allocated,
                'display_title' => 'واحد پشتیبانی و خدمات مشترکین',
                'user_id' => $user->id,
                'allocated_at' => now()->subDays(2),
            ]
        );

        // Seed available numbers in the pool with leading zeros
        $availableNumbers = [
            '000000001',
            '000555777',
            '123456789',
            '987654321',
            '000999888',
        ];

        foreach ($availableNumbers as $num) {
            PhoneNumber::updateOrCreate(
                ['number' => $num],
                [
                    'type' => PhoneNumberType::UserStandard,
                    'status' => PhoneNumberStatus::Available,
                    'user_id' => null,
                    'allocated_at' => null,
                ]
            );
        }

        // Seed sample platform and user service numbers
        PhoneNumber::updateOrCreate(
            ['number' => '1000'],
            [
                'type' => PhoneNumberType::PlatformService,
                'status' => PhoneNumberStatus::Allocated,
                'display_title' => 'سرشماره مرکزی پلتفرم',
                'user_id' => $admin->id,
                'allocated_at' => now()->subDays(10),
            ]
        );

        $allocatedServicePhone = PhoneNumber::updateOrCreate(
            ['number' => '50000'],
            [
                'type' => PhoneNumberType::UserService,
                'status' => PhoneNumberStatus::Allocated,
                'display_title' => 'درگاه خدمات مالی',
                'user_id' => $user->id,
                'allocated_at' => now()->subDay(),
            ]
        );

        // Seed available service numbers in the pool for Phase 4 selection
        $availableServicePool = ['4000', '7000', '60000', '800000'];
        foreach ($availableServicePool as $svcNum) {
            PhoneNumber::updateOrCreate(
                ['number' => $svcNum],
                [
                    'type' => PhoneNumberType::UserService,
                    'status' => PhoneNumberStatus::Available,
                    'user_id' => null,
                    'allocated_at' => null,
                ]
            );
        }

        // Seed sample Number Requests for testing Phase 4 flows
        \App\Models\NumberRequest::updateOrCreate(
            ['service_title' => 'درگاه ارسال کد تایید یکبارمصرف (OTP)'],
            [
                'user_id' => $user->id,
                'requested_length' => 4,
                'description' => 'ارسال پیام‌های ورود دو مرحله‌ای و رمز یکبارمصرف به کارکنان شرکت',
                'preferred_number' => '2000',
                'status' => \App\Enums\NumberRequestStatus::Pending,
            ]
        );

        \App\Models\NumberRequest::updateOrCreate(
            ['service_title' => 'درگاه خدمات مالی و صورتحساب'],
            [
                'user_id' => $user->id,
                'requested_length' => 5,
                'description' => 'ارسال خودکار وضعیت تراکنش‌ها و هشدارهای سررسید مالی',
                'preferred_number' => '50000',
                'status' => \App\Enums\NumberRequestStatus::Approved,
                'allocated_number_id' => $allocatedServicePhone->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->subHours(12),
            ]
        );

        \App\Models\NumberRequest::updateOrCreate(
            ['service_title' => 'کانال اطلاع‌رسانی پیامکی کمپین‌ها'],
            [
                'user_id' => $user->id,
                'requested_length' => 6,
                'description' => 'ارسال پیام‌های تبلیغاتی انبوه به مخاطبین آزاد',
                'preferred_number' => null,
                'status' => \App\Enums\NumberRequestStatus::Rejected,
                'rejection_reason' => 'ارسال پیام‌های تبلیغاتی در این فاز مجاز نمی‌باشد و صرفاً پیام‌های داخلی پشتیبانی می‌شوند.',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->subDay(),
            ]
        );
    }
}
