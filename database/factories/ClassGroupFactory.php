<?php

namespace Database\Factories;

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
        $level = fake()->numberBetween(1, 4);

        return [
            'study_program_id' => StudyProgram::factory(),
            'name' => "Marhalah {$level} ".fake()->unique()->randomLetter(),
            'level' => $level,
        ];
    }
}
