<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleAssignment>
 */
class RoleAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory()->global(),
            'scope_type' => 'global',
            'scope_id' => null,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function department(Department $department): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => 'department',
            'scope_id' => $department->id,
        ]);
    }

    public function branch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => 'branch',
            'scope_id' => $branch->id,
        ]);
    }
}
