<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
        // Default System Administrator
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'راهبر ارشد سامانه',
                'password' => Hash::make('Admin@123456'),
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ]
        );

        // Default Standard Enterprise User
        User::updateOrCreate(
            ['username' => 'user'],
            [
                'name' => 'کاربر سازمانی نمونه',
                'password' => Hash::make('User@123456'),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
            ]
        );
    }
}
