<?php

namespace Database\Factories;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhoneNumber>
 */
class PhoneNumberFactory extends Factory
{
    protected $model = PhoneNumber::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => str_pad((string) fake()->unique()->numberBetween(0, 999999999), 9, '0', STR_PAD_LEFT),
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
            'display_title' => null,
            'user_id' => null,
            'allocated_at' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PhoneNumberStatus::Available,
            'user_id' => null,
            'allocated_at' => null,
        ]);
    }

    public function allocated(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user?->id ?? User::factory(),
            'allocated_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PhoneNumberStatus::Suspended,
        ]);
    }

    public function service(int $digits = 6): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => (string) fake()->unique()->numberBetween(1000, 999999),
            'type' => PhoneNumberType::UserService,
            'display_title' => 'سرشماره سازمانی اختصاصی',
        ]);
    }

    public function platform(): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => '1000',
            'type' => PhoneNumberType::PlatformService,
            'display_title' => 'سرشماره پلتفرم',
        ]);
    }
}
