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
        PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'پیش‌شماره بین‌المللی سازمانی (پیش‌فرض)',
                'country_name' => 'بین‌الملل',
                'country_code' => 'INTL',
                'flag' => '🌐',
                'sub_prefixes' => ['850', '851', '855', '858'],
                'price_regular' => 1.0000,
                'price_semi_rond' => 5.0000,
                'price_golden_rond' => 20.0000,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        // 2. Alternative National Prefix (+98) for Iran
        PhonePrefix::updateOrCreate(
            ['prefix' => '+98'],
            [
                'clean_prefix' => '98',
                'name' => 'ایران (همراه اول / ایرانسل)',
                'country_name' => 'ایران',
                'country_code' => 'IR',
                'flag' => '🇮🇷',
                'sub_prefixes' => ['912', '910', '918', '919', '935', '936', '937', '938', '939', '990'],
                'price_regular' => 1.0000,
                'price_semi_rond' => 5.0000,
                'price_golden_rond' => 20.0000,
                'is_default' => false,
                'is_active' => true,
            ]
        );
    }
}
