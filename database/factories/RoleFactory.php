<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'allows_global' => false,
            'allows_department' => false,
            'allows_branch' => false,
        ];
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes): array => ['allows_global' => true]);
    }

    public function department(): static
    {
        return $this->state(fn (array $attributes): array => ['allows_department' => true]);
    }

    public function branch(): static
    {
        return $this->state(fn (array $attributes): array => ['allows_branch' => true]);
    }
}
