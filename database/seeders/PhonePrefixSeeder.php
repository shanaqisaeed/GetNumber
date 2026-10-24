<?php

namespace Database\Seeders;

use App\Models\PhonePrefix;
use Illuminate\Database\Seeder;

class PhonePrefixSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Default International / Enterprise Prefix (+85)
        PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'پیش‌شماره سازمانی راوی نو (پیش‌فرض)',
                'country_code' => 'INTL',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        // 2. Alternative National Prefix (+98) for testing / multi-prefix support
        PhonePrefix::firstOrCreate(
            ['prefix' => '+98'],
            [
                'clean_prefix' => '98',
                'name' => 'پیش‌شماره ملی آزمایشی',
                'country_code' => 'IR',
                'is_default' => false,
                'is_active' => true,
            ]
        );
    }
}
