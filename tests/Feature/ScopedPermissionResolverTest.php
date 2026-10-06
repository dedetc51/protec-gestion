<?php

namespace Tests\Feature;

use App\Authorization\AuthorizationContext;
use App\Models\Branch;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ScopedPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ScopedPermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_administrator_receives_global_technical_permissions_in_every_context(): void
    {
        $department = Department::factory()->create();
        $branch = Branch::factory()->for($department)->create();
        $technicalAdmin = Role::query()->where('slug', 'technical-admin')->sole();
        $technicalPermission = $this->permission('technical.manage');
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($technicalAdmin)->create();

        $resolver = app(ScopedPermissionResolver::class);

        $this->assertTrue($resolver->allows($user, 'technical.manage', AuthorizationContext::global()));
        $this->assertTrue($resolver->allows($user, 'technical.manage', AuthorizationContext::department($department)));
        $this->assertTrue($resolver->allows($user, 'technical.manage', AuthorizationContext::branch($branch)));
    }

    public function test_department_roles_cover_only_their_department_and_branch_roles_stay_local(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $branch = Branch::factory()->for($department)->create();
        $otherBranch = Branch::factory()->for($otherDepartment)->create();
        $departmentRole = Role::factory()->department()->create();
        $branchRole = Role::factory()->branch()->create();
        $permission = $this->permission('members.view');
        $departmentRole->permissions()->attach($permission);
        $branchRole->permissions()->attach($permission);
        $departmentMember = User::factory()->create();
        $branchMember = User::factory()->create();
        RoleAssignment::factory()->for($departmentMember)->for($departmentRole)->department($department)->create();
        RoleAssignment::factory()->for($branchMember)->for($branchRole)->branch($branch)->create();
        $this->membership($branchMember, $branch);
        $resolver = app(ScopedPermissionResolver::class);

        $this->assertTrue($resolver->allows($departmentMember, 'members.view', AuthorizationContext::department($department)));
        $this->assertTrue($resolver->allows($departmentMember, 'members.view', AuthorizationContext::branch($branch)));
        $this->assertFalse($resolver->allows($departmentMember, 'members.view', AuthorizationContext::department($otherDepartment)));
        $this->assertFalse($resolver->allows($branchMember, 'members.view', AuthorizationContext::branch($otherBranch)));
        $this->assertFalse($resolver->allows($branchMember, 'members.view', AuthorizationContext::department($department)));
    }

    public function test_permissions_from_multiple_roles_accumulate_within_the_same_scope(): void
    {
        $branch = Branch::factory()->create();
        $viewRole = Role::factory()->branch()->create();
        $updateRole = Role::factory()->branch()->create();
        $view = $this->permission('equipment.view');
        $update = $this->permission('equipment.update');
        $viewRole->permissions()->attach($view);
        $updateRole->permissions()->attach($update);
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($viewRole)->branch($branch)->create();
        RoleAssignment::factory()->for($user)->for($updateRole)->branch($branch)->create();
        $this->membership($user, $branch);
        $context = AuthorizationContext::branch($branch);
        $resolver = app(ScopedPermissionResolver::class);

        $this->assertTrue($resolver->allows($user, 'equipment.view', $context));
        $this->assertTrue($resolver->allows($user, 'equipment.update', $context));
    }

    public function test_department_override_inherits_grants_or_denies_the_role_permission(): void
    {
        $department = Department::factory()->create();
        $branch = Branch::factory()->for($department)->create();
        $role = Role::factory()->department()->create();
        $globallyGranted = $this->permission('members.view');
        $globallyDenied = $this->permission('members.export');
        $role->permissions()->attach($globallyGranted, ['granted' => true]);
        $role->permissions()->attach($globallyDenied, ['granted' => false]);
        DepartmentRolePermission::query()->create([
            'department_id' => $department->id,
            'role_id' => $role->id,
            'permission_id' => $globallyDenied->id,
            'state' => 'grant',
        ]);
        $role->permissions()->attach($this->permission('members.assign_roles'), ['granted' => true]);
        DepartmentRolePermission::query()->create([
            'department_id' => $department->id,
            'role_id' => $role->id,
            'permission_id' => $this->permission('members.assign_roles')->id,
            'state' => 'deny',
        ]);
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($role)->department($department)->create();
        $context = AuthorizationContext::branch($branch);
        $resolver = app(ScopedPermissionResolver::class);

        $this->assertTrue($resolver->allows($user, 'members.view', $context));
        $this->assertTrue($resolver->allows($user, 'members.export', $context));
        $this->assertFalse($resolver->allows($user, 'members.assign_roles', $context));
    }

    public function test_branch_assignments_require_membership_active_at_the_resolution_time(): void
    {
        $this->freezeTime();
        $branch = Branch::factory()->create();
        $role = Role::factory()->branch()->create();
        $role->permissions()->attach($this->permission('members.view'));
        $activeUser = User::factory()->create();
        $futureUser = User::factory()->create();
        $expiredUser = User::factory()->create();
        foreach ([$activeUser, $futureUser, $expiredUser] as $user) {
            RoleAssignment::factory()->for($user)->for($role)->branch($branch)->create();
        }
        $this->membership($activeUser, $branch);
        $this->membership($futureUser, $branch, ['starts_at' => now()->addDay()]);
        $this->membership($expiredUser, $branch, ['ends_at' => now()]);
        $resolver = app(ScopedPermissionResolver::class);
        $context = AuthorizationContext::branch($branch);

        $this->assertTrue($resolver->allows($activeUser, 'members.view', $context));
        $this->assertFalse($resolver->allows($futureUser, 'members.view', $context));
        $this->assertFalse($resolver->allows($expiredUser, 'members.view', $context));
    }

    public function test_unsaved_department_and_branch_contexts_cannot_reuse_an_existing_scope_grant(): void
    {
        $department = Department::factory()->create();
        $branch = Branch::factory()->for($department)->create();
        $departmentRole = Role::factory()->department()->create();
        $branchRole = Role::factory()->branch()->create();
        $departmentRole->permissions()->attach($this->permission('members.view'));
        $branchRole->permissions()->attach($this->permission('members.update'));
        $departmentUser = User::factory()->create();
        $branchUser = User::factory()->create();
        RoleAssignment::factory()->for($departmentUser)->for($departmentRole)->department($department)->create();
        RoleAssignment::factory()->for($branchUser)->for($branchRole)->branch($branch)->create();
        $this->membership($branchUser, $branch);
        $unsavedDepartment = Department::factory()->make();
        $unsavedBranch = Branch::factory()->for($department)->make();
        $resolver = app(ScopedPermissionResolver::class);

        $this->assertFalse($resolver->allows($branchUser, 'members.update', AuthorizationContext::department($unsavedDepartment)));
        $this->assertFalse($resolver->allows($branchUser, 'members.update', AuthorizationContext::branch($unsavedBranch)));
        $this->assertFalse($resolver->allows($departmentUser, 'members.view', AuthorizationContext::branch($unsavedBranch)));
    }

    public function test_denial_for_one_role_does_not_cancel_another_roles_grant(): void
    {
        $department = Department::factory()->create();
        $roleWithDenial = Role::factory()->department()->create();
        $roleWithGrant = Role::factory()->department()->create();
        $permission = $this->permission('members.view');
        $roleWithDenial->permissions()->attach($permission, ['granted' => true]);
        $roleWithGrant->permissions()->attach($permission, ['granted' => true]);
        DepartmentRolePermission::query()->create([
            'department_id' => $department->id,
            'role_id' => $roleWithDenial->id,
            'permission_id' => $permission->id,
            'state' => 'deny',
        ]);
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($roleWithDenial)->department($department)->create();
        RoleAssignment::factory()->for($user)->for($roleWithGrant)->department($department)->create();

        $this->assertTrue(app(ScopedPermissionResolver::class)->allows(
            $user,
            'members.view',
            AuthorizationContext::department($department),
        ));
    }

    public function test_technical_permission_cannot_be_granted_to_an_association_role(): void
    {
        $branch = Branch::factory()->create();
        $role = Role::factory()->branch()->create();
        $permission = $this->permission('technical.manage');
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($role)->branch($branch)->create();
        $this->membership($user, $branch);

        $this->assertFalse(app(ScopedPermissionResolver::class)->allows(
            $user,
            'technical.manage',
            AuthorizationContext::branch($branch),
        ));
    }

    public function test_future_and_expired_assignments_and_deactivated_users_do_not_authorize(): void
    {
        $this->freezeTime();
        $branch = Branch::factory()->create();
        $role = Role::factory()->branch()->create();
        $permission = $this->permission('members.view');
        $role->permissions()->attach($permission);
        $futureUser = User::factory()->create();
        $expiredUser = User::factory()->create();
        $deactivatedUser = User::factory()->create(['deactivated_at' => now()]);
        RoleAssignment::factory()->for($futureUser)->for($role)->branch($branch)->create(['starts_at' => now()->addDay()]);
        RoleAssignment::factory()->for($expiredUser)->for($role)->branch($branch)->create(['ends_at' => now()]);
        RoleAssignment::factory()->for($deactivatedUser)->for($role)->branch($branch)->create();
        $this->membership($futureUser, $branch);
        $this->membership($expiredUser, $branch);
        $this->membership($deactivatedUser, $branch);
        $resolver = app(ScopedPermissionResolver::class);
        $context = AuthorizationContext::branch($branch);

        $this->assertFalse($resolver->allows($futureUser, 'members.view', $context));
        $this->assertFalse($resolver->allows($expiredUser, 'members.view', $context));
        $this->assertFalse($resolver->allows($deactivatedUser, 'members.view', $context));
    }

    public function test_scoped_permission_gate_uses_the_shared_resolver(): void
    {
        $branch = Branch::factory()->create();
        $role = Role::factory()->branch()->create();
        $permission = $this->permission('members.view');
        $role->permissions()->attach($permission);
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($role)->branch($branch)->create();
        $this->membership($user, $branch);

        $this->assertTrue(Gate::forUser($user)->check('scoped-permission', ['members.view', AuthorizationContext::branch($branch)]));
        $this->assertTrue($user->canIn('members.view', AuthorizationContext::branch($branch)));
    }

    public function test_department_policy_limits_association_management_to_the_assigned_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $role = Role::factory()->department()->create();
        $role->permissions()->attach($this->permission('branches.manage'));
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($role)->department($department)->create();

        $this->assertTrue(Gate::forUser($user)->allows('manage', $department));
        $this->assertFalse(Gate::forUser($user)->allows('manage', $otherDepartment));
    }

    public function test_branch_policy_limits_branch_management_to_the_assigned_branch(): void
    {
        $department = Department::factory()->create();
        $branch = Branch::factory()->for($department)->create();
        $otherBranch = Branch::factory()->for($department)->create();
        $role = Role::factory()->branch()->create();
        $role->permissions()->attach($this->permission('branches.manage'));
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->for($role)->branch($branch)->create();
        $this->membership($user, $branch);

        $this->assertTrue(Gate::forUser($user)->allows('update', $branch));
        $this->assertFalse(Gate::forUser($user)->allows('update', $otherBranch));
    }

    private function permission(string $key): Permission
    {
        return Permission::query()->firstOrCreate(['key' => $key], ['name' => $key]);
    }

    /** @param array<string, mixed> $attributes */
    private function membership(User $user, Branch $branch, array $attributes = []): Membership
    {
        return Membership::factory()->for($user)->for($branch)->create($attributes);
    }
}
