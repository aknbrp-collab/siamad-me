<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'WS-'.fake()->unique()->regexify('[A-HJ-NP-Z2-9]{8}'),
            'user_id' => fn (array $attributes) => User::factory()
                ->withRole(Role::Guardian)
                ->create(['username' => $attributes['code'], 'email' => null])
                ->id,
            'relationship' => fake()->randomElement(['Ayah', 'Ibu', 'Wali']),
        ];
    }
}
