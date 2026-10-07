<?php

use App\Support\AssociationRoleCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $timestamp = now();
            $permissionIds = [];
            foreach (PermissionCatalog::all() as $key => $definition) {
                DB::table('permissions')->insertOrIgnore(['key' => $key, 'name' => $definition['name'], 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                $permissionIds[$key] = DB::table('permissions')->where('key', $key)->value('id');
            }

            foreach (AssociationRoleCatalog::all() as $slug => $definition) {
                $keys = $definition['permissions'];
                unset($definition['permissions']);
                DB::table('roles')->insertOrIgnore(['slug' => $slug, ...$definition, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                $roleId = DB::table('roles')->where('slug', $slug)->value('id');
                foreach ($keys as $key) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds[$key], 'granted' => true]);
                }
            }

            $departmentName = config('association.default_department_name') ?: 'Département initial';
            $branchName = config('association.default_branch_name') ?: 'Antenne initiale';
            DB::table('departments')->insertOrIgnore(['name' => $departmentName, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            $departmentId = DB::table('departments')->where('name', $departmentName)->value('id');
            DB::table('branches')->insertOrIgnore(['department_id' => $departmentId, 'name' => $branchName, 'created_at' => $timestamp, 'updated_at' => $timestamp]);

            $technicalRoleId = DB::table('roles')->where('slug', 'technical-admin')->value('id');
            DB::table('users')->where('role', 'admin')->orderBy('id')->chunkById(100, function (Collection $users) use ($technicalRoleId, $timestamp): void {
                foreach ($users as $user) {
                    $assignment = ['user_id' => $user->id, 'role_id' => $technicalRoleId, 'scope_type' => 'global', 'scope_id' => null];
                    if (! DB::table('role_assignments')->where($assignment)->exists()) {
                        DB::table('role_assignments')->insert([...$assignment, 'starts_at' => null, 'ends_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                    }
                }
            });
        });
    }

    /** Seeded rows may own operational records; application rollback retains them and users.role. */
    public function down(): void {}
};
