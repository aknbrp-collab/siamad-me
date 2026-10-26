<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\TeacherStatus;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nidm' => fake()->unique()->numerify('2026######'),
            'user_id' => fn (array $attributes) => User::factory()
                ->withRole(Role::Teacher)
                ->create(['username' => $attributes['nidm']])
                ->id,
            'title' => fake()->optional()->randomElement(['Lc.', 'M.A.', 'Lc., M.Ag.']),
            'gender' => Gender::Male,
            'status' => TeacherStatus::Active,
        ];
    }
}
