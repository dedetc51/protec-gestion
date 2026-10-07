<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\Department;
use App\Models\User;

class AdministrationNavigation
{
    /** @return list<array{label: string, description: string, url: string, current: bool}> */
    public function links(User $actor): array
    {
        $technical = $actor->canIn('technical.manage', AuthorizationContext::global());
        $organization = $actor->canIn('departments.manage', AuthorizationContext::global());
        $assignments = $technical;
        $permissionDepartments = [];
        foreach (Department::orderBy('name')->orderBy('id')->get() as $department) {
            $context = AuthorizationContext::department($department);
            $organization = $actor->can('manage', $department) || $organization;
            $assignments = $actor->canIn('members.assign_roles', $context) || $assignments;
            if ($department->deactivated_at === null && $actor->canIn('permissions.manage_department', $context)) {
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
