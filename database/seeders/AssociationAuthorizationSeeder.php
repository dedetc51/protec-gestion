<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\AssociationRoleCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssociationAuthorizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];
            foreach (PermissionCatalog::all() as $key => $definition) {
                $permissionIds[$key] = Permission::query()->updateOrCreate(['key' => $key], ['name' => $definition['name']])->id;
            }

            foreach (AssociationRoleCatalog::all() as $slug => $definition) {
                $keys = $definition['permissions'];
                unset($definition['permissions']);
                $role = Role::query()->updateOrCreate(['slug' => $slug], $definition);
                $role->permissions()->syncWithoutDetaching(array_map(fn (string $key): int => $permissionIds[$key], $keys));
            }
        });
    }
}
