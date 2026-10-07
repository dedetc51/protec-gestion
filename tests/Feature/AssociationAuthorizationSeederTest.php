<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\AssociationAuthorizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssociationAuthorizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_every_role_with_its_supported_scopes(): void
    {
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        $this->seed(AssociationAuthorizationSeeder::class);

        $expected = [
            'technical-admin' => [true, false, false],
            'department-president' => [false, true, false],
            'operations-manager' => [false, true, true],
            'operations-deputy' => [false, true, true],
            'branch-manager' => [false, false, true],
            'branch-deputy' => [false, false, true],
            'vehicle-manager' => [false, false, true],
            'vehicle-deputy' => [false, false, true],
            'equipment-manager' => [false, false, true],
            'equipment-deputy' => [false, false, true],
            'logistics-manager' => [false, false, true],
            'logistics-deputy' => [false, false, true],
            'volunteer' => [false, false, true],
        ];
        $this->assertDatabaseCount('roles', 13);
        foreach ($expected as $slug => $scopes) {
            $role = Role::query()->where('slug', $slug)->sole();
            $this->assertSame($scopes, [$role->allows_global, $role->allows_department, $role->allows_branch]);
        }
        foreach (['members.view', 'branches.manage', 'equipment.update', 'vehicles.assign', 'logistics.export', 'operations.validate', 'training.validate', 'documents.create', 'communications.send', 'settings.update', 'audit.view', 'permissions.manage_global', 'technical.manage'] as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
    }

    public function test_groups_cover_all_domains_and_technical_permissions_cannot_be_delegated(): void
    {
        $groups = PermissionCatalog::groups();

        $this->assertSame(['members', 'organization', 'equipment', 'vehicles', 'logistics', 'operations', 'training', 'documents', 'communications', 'settings', 'audit', 'technical'], array_keys($groups));
        foreach ($groups['technical']['permissions'] as $key => $permission) {
            $this->assertFalse(PermissionCatalog::isDelegable($key));
        }
        $this->assertFalse(PermissionCatalog::isDelegable('missing.permission'));
        foreach (['members.view', 'members.assign_roles', 'branches.manage', 'equipment.update', 'operations.validate', 'permissions.manage_department'] as $key) {
            $this->assertTrue(PermissionCatalog::isDelegable($key));
        }
    }

    public function test_defaults_limit_volunteers_and_deputies_and_grant_managers_their_domain(): void
    {
        $this->seed(AssociationAuthorizationSeeder::class);

        $volunteerKeys = Role::query()->where('slug', 'volunteer')->sole()->permissions()->pluck('key')->all();
        $this->assertContains('documents.view', $volunteerKeys);
        foreach ($volunteerKeys as $key) {
            $this->assertStringEndsWith('.view', $key);
        }
        foreach (['operations-deputy' => 'operations.update', 'branch-deputy' => 'members.assign_roles', 'vehicle-deputy' => 'vehicles.update', 'equipment-deputy' => 'equipment.update', 'logistics-deputy' => 'logistics.update'] as $slug => $requiredKey) {
            $keys = Role::query()->where('slug', $slug)->sole()->permissions()->pluck('key')->all();
            $this->assertContains($requiredKey, $keys);
            foreach ($keys as $key) {
                $this->assertFalse(str_ends_with($key, '.delete') || str_ends_with($key, '.validate') || str_starts_with($key, 'permissions.'));
            }
        }
        foreach (['operations-manager' => 'operations.validate', 'branch-manager' => 'branches.manage', 'vehicle-manager' => 'vehicles.delete', 'equipment-manager' => 'equipment.delete', 'logistics-manager' => 'logistics.delete'] as $slug => $requiredKey) {
            $this->assertTrue(Role::query()->where('slug', $slug)->sole()->permissions()->where('key', $requiredKey)->exists());
        }
    }

    public function test_president_receives_only_delegable_management_and_technical_admin_receives_all_permissions(): void
    {
        $this->seed(AssociationAuthorizationSeeder::class);

        $keys = Role::query()->where('slug', 'department-president')->sole()->permissions()->pluck('key')->all();
        foreach (['members.assign_roles', 'branches.manage', 'permissions.manage_department', 'audit.view'] as $key) {
            $this->assertContains($key, $keys);
        }
        foreach ($keys as $key) {
            $this->assertTrue(PermissionCatalog::isDelegable($key));
        }
        $technicalKeys = Role::query()->where('slug', 'technical-admin')->sole()->permissions()->pluck('key')->all();
        $this->assertContains('permissions.manage_global', $technicalKeys);
        $this->assertContains('technical.manage', $technicalKeys);
        $this->assertSame(Permission::query()->count(), count($technicalKeys));
    }

    public function test_reseeding_preserves_existing_denials_custom_grants_and_catalog_ids(): void
    {
        $this->freezeTime();
        $this->seed(AssociationAuthorizationSeeder::class);
        $role = Role::query()->where('slug', 'volunteer')->sole();
        $base = Permission::query()->where('key', 'documents.view')->sole();
        $custom = Permission::factory()->create(['key' => 'custom.view']);
        $role->permissions()->updateExistingPivot($base->id, ['granted' => false]);
        $role->permissions()->attach($custom->id);
        $before = $this->authorizationSnapshot();

        $this->seed(AssociationAuthorizationSeeder::class);
        $this->seed(AssociationAuthorizationSeeder::class);

        $this->assertSame($before, $this->authorizationSnapshot());
    }

    public function test_migration_converts_existing_admins_once_and_preserves_legacy_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'member']);
        $migration = require database_path('migrations/2026_10_06_000002_seed_association_authorization.php');

        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('role_assignments', ['user_id' => $admin->id, 'role_id' => Role::query()->where('slug', 'technical-admin')->sole()->id, 'scope_type' => 'global', 'scope_id' => null, 'starts_at' => null, 'ends_at' => null]);
        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_migration_preserves_ended_technical_assignments(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $role = Role::query()->where('slug', 'technical-admin')->sole();
        $assignment = RoleAssignment::factory()->for($admin)->for($role)->create(['ends_at' => now()->subDay()]);
        $migration = require database_path('migrations/2026_10_06_000002_seed_association_authorization.php');

        $migration->up();

        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertFalse($assignment->fresh()->isActiveAt());
    }

    public function test_migration_creates_configured_organization_once_and_retains_data_on_rollback(): void
    {
        $this->assertDatabaseHas('departments', ['name' => 'Département initial']);
        $this->assertDatabaseHas('branches', ['name' => 'Antenne initiale']);
        config(['association.default_department_name' => 'Protection Civile 75', 'association.default_branch_name' => 'Paris']);
        $migration = require database_path('migrations/2026_10_06_000002_seed_association_authorization.php');

        $migration->up();
        $migration->up();
        $before = $this->authorizationSnapshot();
        $migration->down();

        $departmentId = DB::table('departments')->where('name', 'Protection Civile 75')->sole()->id;
        $this->assertDatabaseHas('branches', ['department_id' => $departmentId, 'name' => 'Paris']);
        $this->assertDatabaseCount('departments', 2);
        $this->assertDatabaseCount('branches', 2);
        $this->assertSame($before, $this->authorizationSnapshot());
    }

    public function test_database_seeder_creates_authorization_without_a_demo_user(): void
    {
        DB::table('roles')->delete();
        DB::table('permissions')->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('roles', 13);
        $this->assertDatabaseHas('permissions', ['key' => 'technical.manage']);
        $this->assertDatabaseCount('users', 0);
    }

    private function authorizationSnapshot(): array
    {
        $snapshot = [];
        foreach (['roles', 'permissions', 'role_permissions', 'departments', 'branches', 'role_assignments'] as $table) {
            $query = DB::table($table)->orderBy($table === 'role_permissions' ? 'role_id' : 'id');
            if ($table === 'role_permissions') {
                $query->orderBy('permission_id');
            }
            $snapshot[$table] = $query->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
