<?php

namespace Database\Factories;

use App\Enums\SemesterStatus;
use App\Enums\SemesterTerm;
use App\Models\AcademicYear;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semester>
 */
class SemesterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'term' => SemesterTerm::Odd,
            'status' => SemesterStatus::Planned,
        ];
    }

    public function even(): static
    {
        return $this->state(fn (array $attributes) => ['term' => SemesterTerm::Even]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SemesterStatus::Active]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SemesterStatus::Closed]);
    }
}
