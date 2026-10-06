<?php

namespace App\Policies;

use App\Authorization\AuthorizationContext;
use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function view(User $user, Department $department): bool
    {
        return $user->canIn('departments.view', AuthorizationContext::department($department));
    }

    public function manage(User $user, Department $department): bool
    {
        return $user->canIn('branches.manage', AuthorizationContext::department($department));
    }

    public function manageMembers(User $user, Department $department): bool
    {
        return $user->canIn('members.assign_roles', AuthorizationContext::department($department));
    }
}
