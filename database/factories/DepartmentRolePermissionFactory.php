<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentRolePermission>
 */
class DepartmentRolePermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'role_id' => Role::factory(),
            'permission_id' => Permission::factory(),
            'state' => 'grant',
        ];
    }
}
