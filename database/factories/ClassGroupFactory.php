<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\ClassGroup;
use App\Models\StudyProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroup>
 */
class ClassGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $level = fake()->numberBetween(1, 8);

        return [
            'study_program_id' => StudyProgram::factory(),
            'name' => "Semester {$level} Putra ".fake()->unique()->randomLetter(),
            'level' => $level,
            'gender' => Gender::Male,
        ];
    }

    /**
     * A class for female mahasantri.
     */
    public function putri(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => Gender::Female,
            'name' => str_replace('Putra', 'Putri', $attributes['name']),
        ]);
    }
}
