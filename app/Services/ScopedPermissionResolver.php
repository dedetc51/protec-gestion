<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ScopedPermissionResolver
{
    public function allows(User $user, string $permissionKey, AuthorizationContext $context): bool
    {
        if (! $this->hasPersistedScope($context) || $user->deactivated_at !== null) {
            return false;
        }

        $at = now();
        $permission = Permission::query()->where('key', $permissionKey)->first();

        if ($permission === null) {
            return false;
        }

        $technicalAdmin = $this->hasTechnicalRole($user, $at);

        if ($technicalAdmin) {
            return true;
        }

        if (! PermissionCatalog::isDelegable($permissionKey)) {
            return false;
        }

        $assignments = $user->roleAssignments()
            ->active($at)
            ->where(function (Builder $query) use ($context): void {
                if ($context->scopeType === 'global') {
                    $query->where('scope_type', 'global');

                    return;
                }

                if ($context->departmentId !== null) {
                    $query->where(function (Builder $query) use ($context): void {
                        $query->where(function (Builder $query) use ($context): void {
                            $query->where('scope_type', 'department')
                                ->where('scope_id', $context->departmentId);
                        });

                        if ($context->scopeType === 'branch') {
                            $query->orWhere(function (Builder $query) use ($context): void {
                                $query->where('scope_type', 'branch')
                                    ->where('scope_id', $context->scopeId);
                            });
                        }
                    });
                }
            })
            ->with(['role.permissions' => fn (BelongsToMany $query): BelongsToMany => $query->where('permissions.id', $permission->id)])
            ->get();

        if ($assignments->isEmpty()) {
            return false;
        }

        $branchAssignmentIds = $assignments
            ->where('scope_type', 'branch')
            ->pluck('scope_id')
            ->unique();
        $activeMembershipBranchIds = $branchAssignmentIds->isEmpty()
            ? collect()
            : Membership::query()
                ->whereBelongsTo($user)
                ->whereIn('branch_id', $branchAssignmentIds)
                ->active($at)
                ->pluck('branch_id');

        $overrides = $context->departmentId === null
            ? collect()
            : DepartmentRolePermission::query()
                ->where('department_id', $context->departmentId)
                ->whereIn('role_id', $assignments->pluck('role_id'))
                ->where('permission_id', $permission->id)
                ->pluck('state', 'role_id');

        foreach ($assignments as $assignment) {
            if ($assignment->scope_type === 'branch' && ! $activeMembershipBranchIds->contains($assignment->scope_id)) {
                continue;
            }

            $departmentOverride = $overrides->get($assignment->role_id);

            if ($this->roleAllows($assignment, $permission, $departmentOverride)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare a fresh batch of department decisions without caching across mutations.
     *
     * @param  list<string>  $permissionKeys
     * @param  Collection<int, Department>  $departments
     * @return array<int, list<string>>
     */
    public function grantedDepartmentPermissions(User $user, array $permissionKeys, Collection $departments): array
    {
        $departments = $departments->filter(fn (Department $department): bool => $department->exists);
        if ($user->deactivated_at !== null || $departments->isEmpty() || $permissionKeys === []) {
            return [];
        }

        $at = now();
        $permissionKeys = array_values(array_unique($permissionKeys));
        $permissions = Permission::whereIn('key', $permissionKeys)->get()->keyBy('key');
        $technical = $this->hasTechnicalRole($user, $at);
        $assignments = $technical ? collect() : $user->roleAssignments()->active($at)
            ->where('scope_type', 'department')->whereIn('scope_id', $departments->modelKeys())
            ->with(['role.permissions' => fn (BelongsToMany $query): BelongsToMany => $query->whereIn('permissions.id', $permissions->modelKeys())])->get();
        $assignmentsByDepartment = $assignments->groupBy('scope_id');
        $overrides = $assignments->isEmpty() ? collect() : DepartmentRolePermission::whereIn('department_id', $departments->modelKeys())
            ->whereIn('role_id', $assignments->pluck('role_id'))->whereIn('permission_id', $permissions->modelKeys())
            ->get()->keyBy(fn (DepartmentRolePermission $override): string => $override->department_id.':'.$override->role_id.':'.$override->permission_id);

        $grants = [];
        foreach ($departments as $department) {
            foreach ($permissionKeys as $key) {
                $permission = $permissions->get($key);
                if ($permission === null || (! $technical && ! PermissionCatalog::isDelegable($key))) {
                    continue;
                }
                $allowed = $technical || $assignmentsByDepartment->get($department->id, collect())->contains(
                    fn (RoleAssignment $assignment): bool => $this->roleAllows($assignment, $permission, $overrides->get($department->id.':'.$assignment->role_id.':'.$permission->id)?->state),
                );
                if ($allowed) {
                    $grants[$department->id][] = $key;
                }
            }
        }

        return $grants;
    }

    private function hasTechnicalRole(User $user, CarbonInterface $at): bool
    {
        return $user->roleAssignments()->active($at)->where('scope_type', 'global')->whereNull('scope_id')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'technical-admin'))->exists();
    }

    private function roleAllows(RoleAssignment $assignment, Permission $permission, ?string $override): bool
    {
        return $override !== null
            ? $override === 'grant'
            : (bool) $assignment->role->permissions->firstWhere('id', $permission->id)?->pivot->granted;
    }

    private function hasPersistedScope(AuthorizationContext $context): bool
    {
        if (! $context->isPersisted) {
            return false;
        }

        if ($context->scopeType === 'global') {
            return $context->scopeId === null && $context->departmentId === null;
        }

        if ($context->scopeType === 'department') {
            return $context->scopeId !== null && $context->departmentId === $context->scopeId;
        }

        return $context->scopeType === 'branch'
            && $context->scopeId !== null
            && $context->departmentId !== null;
    }
}
