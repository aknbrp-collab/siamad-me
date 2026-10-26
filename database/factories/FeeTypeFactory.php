<?php

namespace Database\Factories;

use App\Enums\FeeFrequency;
use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeType>
 */
class FeeTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'SPP',
            'frequency' => FeeFrequency::PerSemester,
            'is_active' => true,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => ['frequency' => FeeFrequency::Monthly]);
    }

    public function once(): static
    {
        return $this->state(fn (array $attributes) => ['frequency' => FeeFrequency::Once]);
    }
}
