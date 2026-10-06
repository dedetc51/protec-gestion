<?php

namespace App\Policies;

use App\Authorization\AuthorizationContext;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        return $user->canIn('branches.view', AuthorizationContext::branch($branch));
    }

    public function create(User $user, Department $department): bool
    {
        $context = AuthorizationContext::department($department);

        return $user->canIn('branches.create', $context)
            || $user->canIn('branches.manage', $context);
    }

    public function update(User $user, Branch $branch): bool
    {
        $context = AuthorizationContext::branch($branch);

        return $user->canIn('branches.update', $context)
            || $user->canIn('branches.manage', $context);
    }

    public function delete(User $user, Branch $branch): bool
    {
        $context = AuthorizationContext::branch($branch);

        return $user->canIn('branches.delete', $context)
            || $user->canIn('branches.manage', $context);
    }
}
