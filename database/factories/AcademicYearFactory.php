<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->unique()->numberBetween(2000, 2099);

        return [
            'name' => $start.'/'.($start + 1),
            'starts_on' => "{$start}-07-01",
            'ends_on' => ($start + 1).'-06-30',
        ];
    }
}
