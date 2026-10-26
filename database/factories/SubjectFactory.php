<?php

namespace Database\Factories;

use App\Models\StudyProgram;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_program_id' => StudyProgram::factory(),
            'code' => fake()->unique()->bothify('MK###'),
            'name' => fake()->randomElement(['Fiqh', 'Ushul Fiqh', 'Tafsir', 'Hadits', 'Nahwu', 'Sharaf', 'Balaghah']).' '.fake()->numberBetween(1, 4),
            'kitab' => fake()->optional()->randomElement(['Fathul Qarib', 'Alfiyah Ibnu Malik', 'Jalalain', 'Bulughul Maram']),
            'credits' => fake()->numberBetween(2, 4),
            'is_active' => true,
        ];
    }
}
