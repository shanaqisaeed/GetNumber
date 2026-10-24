<?php

namespace Database\Factories;

use App\Enums\NumberRequestStatus;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NumberRequest>
 */
class NumberRequestFactory extends Factory
{
    protected $model = NumberRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $length = fake()->numberBetween(4, 8);

        return [
            'user_id' => User::factory(),
            'requested_length' => $length,
            'service_title' => 'سامانه ' . fake()->company(),
            'description' => 'توضیحات استفاده سازمانی جهت ارائه خدمات به پرسنل',
            'preferred_number' => null,
            'status' => NumberRequestStatus::Pending,
            'rejection_reason' => null,
            'allocated_number_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * State for approved request.
     */
    public function approved(?User $reviewer = null, ?PhoneNumber $phone = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NumberRequestStatus::Approved,
            'reviewed_by' => $reviewer?->id ?? User::factory()->admin()->create()->id,
            'allocated_number_id' => $phone?->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * State for rejected request.
     */
    public function rejected(?User $reviewer = null, string $reason = 'عدم تطابق با شرایط پذیرش سازمانی'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NumberRequestStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer?->id ?? User::factory()->admin()->create()->id,
            'reviewed_at' => now(),
        ]);
    }
}
