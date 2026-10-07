<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_keep_memberships_and_assignments_in_several_organization_scopes(): void
    {
        $this->freezeTime();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $branches = Branch::factory()->count(2)->for($department)->create();
        $otherBranch = Branch::factory()->for($otherDepartment)->create();
        $user = User::factory()->create(['role' => 'admin']);
        $membership = Membership::factory()->for($user)->for($branches[0])->create();
        Membership::factory()->for($user)->for($branches[1])->create();
        $futureMembership = Membership::factory()->for($user)->for($otherBranch)->create(['starts_at' => now()->addDay()]);
        $departmentRole = Role::factory()->department()->create();
        $branchRole = Role::factory()->branch()->create();
        $departmentAssignment = RoleAssignment::factory()->for($user)->for($departmentRole)->department($department)->create();
        $branchAssignment = RoleAssignment::factory()->for($user)->for($branchRole)->branch($branches[0])->create();

        $this->assertSame($branches->modelKeys(), $department->branches()->orderBy('id')->get()->modelKeys());
        $this->assertTrue($otherBranch->department->is($otherDepartment));
        $this->assertCount(2, $user->memberships()->active()->get());
        $this->assertTrue($membership->user->is($user));
        $this->assertTrue($membership->branch->is($branches[0]));
        $this->assertFalse($futureMembership->isActiveAt(now()));
        $this->assertSame([$departmentAssignment->id, $branchAssignment->id], $user->roleAssignments()->active()->orderBy('id')->pluck('id')->all());
        $this->assertTrue($departmentAssignment->role->is($departmentRole));
        $this->assertTrue($branchAssignment->user->is($user));
        $this->assertTrue($user->isAdmin());
    }

    #[DataProvider('roleScopes')]
    public function test_role_scope_support_is_explicit(array $flags, string $scope, bool $allowed): void
    {
        $role = Role::factory()->make($flags);

        $this->assertSame($allowed, $role->allowsScope($scope));
    }

    public static function roleScopes(): array
    {
        return [
            'global allowed' => [['allows_global' => true], 'global', true],
            'global denied' => [['allows_global' => false], 'global', false],
            'department allowed' => [['allows_department' => true], 'department', true],
            'department denied' => [['allows_department' => false], 'department', false],
            'branch allowed' => [['allows_branch' => true], 'branch', true],
            'branch denied' => [['allows_branch' => false], 'branch', false],
            'unknown scope' => [[], 'organization', false],
        ];
    }

    #[DataProvider('effectiveWindows')]
    public function test_active_queries_and_instances_use_inclusive_start_and_exclusive_end(string $model, ?string $startsAt, ?string $endsAt, bool $active): void
    {
        $at = CarbonImmutable::parse('2026-10-06 12:00:00');
        $this->travelTo($at);
        $user = User::factory()->create();
        $record = $model::factory()->for($user)->create(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $otherRecord = $model::factory()->create();

        $ids = ($model === Membership::class ? $user->memberships() : $user->roleAssignments())->active()->pluck('id')->all();

        $this->assertSame($active ? [$record->id] : [], $ids);
        $this->assertSame($active, $record->isActiveAt($at));
        $this->assertSame($active, $model::query()->whereKey($record)->active($at)->exists());
        $this->assertNotContains($otherRecord->id, $ids);
    }

    public static function effectiveWindows(): array
    {
        $cases = [];
        foreach ([Membership::class, RoleAssignment::class] as $model) {
            foreach ([
                'unbounded' => [null, null, true],
                'future start' => ['2026-10-07 12:00:00', null, false],
                'start boundary' => ['2026-10-06 12:00:00', null, true],
                'past start' => ['2026-10-05 12:00:00', null, true],
                'future end' => [null, '2026-10-07 12:00:00', true],
                'end boundary' => [null, '2026-10-06 12:00:00', false],
                'expired' => [null, '2026-10-05 12:00:00', false],
                'bounded active' => ['2026-10-05 12:00:00', '2026-10-07 12:00:00', true],
            ] as $name => $window) {
                $cases[$model.' '.$name] = [$model, ...$window];
            }
        }

        return $cases;
    }

    public function test_branch_names_are_unique_inside_a_department(): void
    {
        $branch = Branch::factory()->create(['name' => 'Centre']);
        Branch::factory()->create(['name' => 'Centre']);

        $this->expectException(QueryException::class);

        Branch::factory()->for($branch->department)->create(['name' => 'Centre']);
    }

    #[DataProvider('uniqueCatalogAttributes')]
    public function test_catalog_identifiers_are_unique(string $model, string $attribute, string $value): void
    {
        $model::factory()->create([$attribute => $value]);

        $this->expectException(QueryException::class);

        $model::factory()->create([$attribute => $value]);
    }

    public static function uniqueCatalogAttributes(): array
    {
        return [
            'department name' => [Department::class, 'name', 'Loiret'],
            'role slug' => [Role::class, 'slug', 'custom-role'],
            'permission key' => [Permission::class, 'key', 'custom.view'],
        ];
    }

    public function test_ended_membership_history_allows_rejoining_the_same_branch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
        $old = Membership::factory()->create(['starts_at' => '2026-10-04 12:00:00', 'ends_at' => '2026-10-05 12:00:00']);
        $new = Membership::factory()->for($old->user)->for($old->branch)->create(['starts_at' => '2026-10-06 12:00:00']);

        $this->assertCount(2, $old->user->memberships);
        $this->assertModelExists($new);
        $this->expectException(QueryException::class);

        Membership::factory()->for($old->user)->for($old->branch)->create(['starts_at' => '2026-10-06 12:00:00']);
    }

    #[DataProvider('assignmentScopes')]
    public function test_open_assignments_are_unique_but_ended_history_is_kept(string $scope): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
        $role = Role::factory()->create(['allows_global' => true, 'allows_department' => true, 'allows_branch' => true]);
        $scopeId = match ($scope) {
            'department' => Department::factory()->create()->id,
            'branch' => Branch::factory()->create()->id,
            default => null,
        };
        $old = RoleAssignment::factory()->for($role)->create(['scope_type' => $scope, 'scope_id' => $scopeId, 'starts_at' => '2026-10-04 12:00:00', 'ends_at' => '2026-10-05 12:00:00']);
        $new = RoleAssignment::factory()->for($old->user)->for($role)->create(['scope_type' => $scope, 'scope_id' => $scopeId, 'starts_at' => '2026-10-06 12:00:00']);

        $this->assertCount(2, $old->user->roleAssignments);
        $this->assertModelExists($new);
        $this->expectException(QueryException::class);

        RoleAssignment::factory()->for($old->user)->for($role)->create(['scope_type' => $scope, 'scope_id' => $scopeId, 'starts_at' => '2026-10-06 12:00:00']);
    }

    public static function assignmentScopes(): array
    {
        return ['global' => ['global'], 'department' => ['department'], 'branch' => ['branch']];
    }

    #[DataProvider('overlappingWindows')]
    public function test_memberships_and_assignments_reject_overlapping_effective_windows(string $model, array $firstWindow, array $secondWindow): void
    {
        $first = $model::factory()->create($firstWindow);
        $identity = $model === Membership::class
            ? ['user_id' => $first->user_id, 'branch_id' => $first->branch_id]
            : ['user_id' => $first->user_id, 'role_id' => $first->role_id, 'scope_type' => $first->scope_type, 'scope_id' => $first->scope_id];

        $this->expectException(QueryException::class);

        DB::table($first->getTable())->insert([...$identity, ...$secondWindow]);
    }

    public static function overlappingWindows(): array
    {
        $cases = [];
        foreach ([Membership::class, RoleAssignment::class] as $model) {
            $cases[$model.' bounded overlap'] = [$model,
                ['starts_at' => '2026-10-05 12:00:00', 'ends_at' => '2026-10-07 12:00:00'],
                ['starts_at' => '2026-10-06 12:00:00', 'ends_at' => '2026-10-08 12:00:00'],
            ];
            $cases[$model.' bounded and unbounded overlap'] = [$model,
                ['starts_at' => '2026-10-05 12:00:00', 'ends_at' => '2026-10-07 12:00:00'],
                ['starts_at' => '2026-10-06 12:00:00', 'ends_at' => null],
            ];
        }

        return $cases;
    }

    #[DataProvider('effectiveWindowModels')]
    public function test_adjacent_membership_and_assignment_history_is_allowed(string $model): void
    {
        $first = $model::factory()->create(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-02 00:00:00']);
        $secondFactory = $model::factory()->for($first->user);

        if ($model === Membership::class) {
            $secondFactory = $secondFactory->for($first->branch);
        } else {
            $secondFactory = $secondFactory->for($first->role)->state([
                'scope_type' => $first->scope_type,
                'scope_id' => $first->scope_id,
            ]);
        }

        $second = $secondFactory->create(['starts_at' => '2026-10-02 00:00:00', 'ends_at' => '2026-10-03 00:00:00']);

        $this->assertModelExists($second);
    }

    public static function effectiveWindowModels(): array
    {
        return [[Membership::class], [RoleAssignment::class]];
    }

    #[DataProvider('effectiveWindowModels')]
    public function test_database_rejects_updates_that_make_effective_windows_overlap(string $model): void
    {
        $first = $model::factory()->create(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-02 00:00:00']);
        $identity = $model === Membership::class
            ? ['user_id' => $first->user_id, 'branch_id' => $first->branch_id]
            : ['user_id' => $first->user_id, 'role_id' => $first->role_id, 'scope_type' => $first->scope_type, 'scope_id' => $first->scope_id];
        DB::table($first->getTable())->insert([...$identity, 'starts_at' => '2026-10-02 00:00:00', 'ends_at' => '2026-10-03 00:00:00']);

        $this->expectException(QueryException::class);

        DB::table($first->getTable())->where('id', $first->id)->update(['ends_at' => '2026-10-03 00:00:00']);
    }

    #[DataProvider('invalidAssignmentScopes')]
    public function test_assignments_reject_unsupported_or_malformed_scopes(string $scope, ?int $scopeId): void
    {
        $role = Role::factory()->create(['allows_global' => true, 'allows_department' => true, 'allows_branch' => true]);
        $assignment = RoleAssignment::factory()->for($role)->make(['scope_type' => $scope, 'scope_id' => $scopeId]);

        $this->expectException(InvalidArgumentException::class);

        $assignment->save();
    }

    public static function invalidAssignmentScopes(): array
    {
        return [
            'unknown' => ['organization', null],
            'global target' => ['global', 123],
            'missing department' => ['department', null],
            'missing branch' => ['branch', null],
            'unknown department' => ['department', 999999],
            'unknown branch' => ['branch', 999999],
        ];
    }

    public function test_assignments_reject_a_role_that_does_not_allow_the_selected_scope(): void
    {
        $role = Role::factory()->department()->create();
        $branch = Branch::factory()->create();
        $assignment = RoleAssignment::factory()->for($role)->branch($branch)->make();

        $this->expectException(InvalidArgumentException::class);

        $assignment->save();
    }

    public function test_assignment_updates_recheck_role_compatibility_after_the_relation_was_loaded(): void
    {
        $assignment = RoleAssignment::factory()->create();
        $assignment->load('role');
        $branchRole = Role::factory()->branch()->create();

        $this->expectException(InvalidArgumentException::class);

        $assignment->update(['role_id' => $branchRole->id]);
    }

    public function test_database_rejects_unknown_scope_types_even_without_model_events(): void
    {
        $assignment = RoleAssignment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('role_assignments')->where('id', $assignment->id)->update(['scope_type' => 'organization']);
    }

    public function test_database_rejects_role_assignments_that_the_role_does_not_allow(): void
    {
        $role = Role::factory()->department()->create();
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('role_assignments')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);
    }

    #[DataProvider('malformedDatabaseAssignmentTargets')]
    public function test_database_rejects_malformed_or_missing_assignment_targets(string $scope, ?int $scopeId): void
    {
        $role = Role::factory()->create(['allows_global' => true, 'allows_department' => true, 'allows_branch' => true]);
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('role_assignments')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
        ]);
    }

    public static function malformedDatabaseAssignmentTargets(): array
    {
        return [
            'global target' => ['global', 123],
            'missing department' => ['department', null],
            'missing branch' => ['branch', null],
            'unknown department' => ['department', 999999],
            'unknown branch' => ['branch', 999999],
        ];
    }

    public function test_database_rejects_disabling_a_role_scope_with_existing_assignments(): void
    {
        $role = Role::factory()->global()->create();
        RoleAssignment::factory()->for($role)->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $role->id)->update(['allows_global' => false]);
    }

    public function test_memberships_require_an_existing_branch(): void
    {
        $membership = Membership::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('memberships')->where('id', $membership->id)->update(['branch_id' => 999999]);
    }

    public function test_permission_grants_and_department_overrides_have_typed_relationships(): void
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $department = Department::factory()->create();

        $role->permissions()->attach($permission, ['granted' => true]);
        $override = $department->rolePermissions()->create(['role_id' => $role->id, 'permission_id' => $permission->id, 'state' => 'grant']);

        $this->assertTrue($role->permissions->sole()->is($permission));
        $this->assertTrue($permission->roles->sole()->is($role));
        $this->assertSame('grant', $department->rolePermissions->sole()->state);
        $this->assertTrue($override->permission->is($permission));
        $this->assertDatabaseHas('role_permissions', ['role_id' => $role->id, 'permission_id' => $permission->id, 'granted' => true]);
    }

    #[DataProvider('invalidOverrideStates')]
    public function test_department_overrides_reject_states_other_than_grant_and_deny(string $state): void
    {
        $department = Department::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('department_role_permissions')->insert(['department_id' => $department->id, 'role_id' => $role->id, 'permission_id' => $permission->id, 'state' => $state]);
    }

    public static function invalidOverrideStates(): array
    {
        return ['unknown state' => ['maybe'], 'inherit means no row' => ['inherit']];
    }

    public function test_department_overrides_allow_deny_and_are_unique_per_cell(): void
    {
        $department = Department::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $cell = ['department_id' => $department->id, 'role_id' => $role->id, 'permission_id' => $permission->id, 'state' => 'deny'];
        DB::table('department_role_permissions')->insert($cell);

        $this->assertDatabaseHas('department_role_permissions', $cell);
        $this->expectException(QueryException::class);

        DB::table('department_role_permissions')->insert($cell);
    }

    public function test_department_override_mutations_keep_role_and_permission_identity(): void
    {
        $department = Department::factory()->create();
        $firstRole = Role::factory()->create();
        $secondRole = Role::factory()->create();
        $permission = Permission::factory()->create();
        $firstCell = DepartmentRolePermission::query()->create([
            'department_id' => $department->id,
            'role_id' => $firstRole->id,
            'permission_id' => $permission->id,
            'state' => 'grant',
        ]);
        $secondCell = DepartmentRolePermission::query()->create([
            'department_id' => $department->id,
            'role_id' => $secondRole->id,
            'permission_id' => $permission->id,
            'state' => 'grant',
        ]);

        $department->rolePermissions()->where('role_id', $firstRole->id)->where('permission_id', $permission->id)->update(['state' => 'deny']);

        $this->assertSame('deny', $firstCell->fresh()->state);
        $this->assertSame('grant', $secondCell->fresh()->state);

        $department->rolePermissions()->where('role_id', $firstRole->id)->where('permission_id', $permission->id)->delete();

        $this->assertDatabaseMissing('department_role_permissions', ['id' => $firstCell->id]);
        $this->assertDatabaseHas('department_role_permissions', ['id' => $secondCell->id, 'state' => 'grant']);
    }

    public function test_global_permission_grants_are_unique_per_cell(): void
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $role->permissions()->attach($permission);

        $this->expectException(QueryException::class);

        $role->permissions()->attach($permission);
    }
}
