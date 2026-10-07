<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdministrationNavigation
{
    public function __construct(private ScopedPermissionResolver $resolver) {}

    /** @return list<array{label: string, description: string, url: string, current: bool}> */
    public function links(User $actor): array
    {
        $technical = $actor->canIn('technical.manage', AuthorizationContext::global());
        $organization = $actor->canIn('departments.manage', AuthorizationContext::global());
        $assignments = $technical;
        $permissionDepartments = [];
        $departments = Department::orderBy('name')->orderBy('id')
            ->when(! $technical, fn (Builder $query): Builder => $query->whereIn('id', $actor->roleAssignments()->active()->where('scope_type', 'department')->select('scope_id')))
            ->get();
        $grants = $this->resolver->grantedDepartmentPermissions($actor, ['branches.manage', 'members.assign_roles', 'permissions.manage_department'], $departments);
        foreach ($departments as $department) {
            $departmentGrants = $grants[$department->id] ?? [];
            $organization = $organization || in_array('branches.manage', $departmentGrants, true);
            $assignments = $assignments || in_array('members.assign_roles', $departmentGrants, true);
            if ($department->deactivated_at === null && in_array('permissions.manage_department', $departmentGrants, true)) {
                $permissionDepartments[] = $department;
            }
        }

        $links = [];
        if ($organization) {
            $links[] = ['label' => 'Départements et antennes', 'description' => 'Gérer les antennes et leur activité dans votre périmètre.', 'url' => route('admin.organization.index'), 'current' => request()->routeIs('admin.organization.*')];
        }
        if ($assignments) {
            $links[] = ['label' => 'Affectations des membres', 'description' => 'Attribuer les appartenances, les responsabilités et leurs dates d’effet.', 'url' => route('admin.assignments.index'), 'current' => request()->routeIs('admin.assignments.*')];
        }
        if ($actor->canIn('permissions.manage_global', AuthorizationContext::global())) {
            $links[] = ['label' => 'Règles générales', 'description' => 'Définir les permissions associatives héritées par les départements.', 'url' => route('admin.permissions.index'), 'current' => request()->routeIs('admin.permissions.index')];
        }
        foreach ($permissionDepartments as $department) {
            $links[] = ['label' => 'Permissions : '.$department->name, 'description' => 'Adapter les droits hérités dans ce département.', 'url' => route('admin.permissions.department', $department), 'current' => request()->routeIs('admin.permissions.department*') && request()->route('department')?->id === $department->id];
        }

        return $links;
    }
}
