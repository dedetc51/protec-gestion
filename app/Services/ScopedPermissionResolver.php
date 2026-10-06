<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\DepartmentRolePermission;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
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

        $technicalAdmin = $user->roleAssignments()
            ->active($at)
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'technical-admin'))
            ->exists();

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

            if ($departmentOverride !== null) {
                if ($departmentOverride === 'grant') {
                    return true;
                }

                continue;
            }

            $grant = $assignment->role->permissions->first()?->pivot->granted;

            if ((bool) $grant) {
                return true;
            }
        }

        return false;
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
