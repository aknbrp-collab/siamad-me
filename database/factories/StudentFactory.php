<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nim' => fake()->unique()->numerify('2026######'),
            'user_id' => fn (array $attributes) => User::factory()
                ->withRole(Role::Student)
                ->create(['username' => $attributes['nim'], 'email' => null])
                ->id,
            'study_program_id' => StudyProgram::factory(),
            'entry_year' => 2026,
            // Putra by default, so students fit the default (putra) factory class; use female() for putri.
            'gender' => Gender::Male,
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-25 years', '-17 years')->format('Y-m-d'),
            'status' => StudentStatus::Active,
        ];
    }

    public function female(): static
    {
        return $this->state(fn (array $attributes) => ['gender' => Gender::Female]);
    }

    /**
     * Create the missing-document rows, as a real enrolment does.
     */
    public function withDocuments(): static
    {
        return $this->afterCreating(function (Student $student) {
            foreach (DocumentType::cases() as $type) {
                $student->documents()->create(['type' => $type]);
            }
        });
    }
}
