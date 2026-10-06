<?php

namespace App\Providers;

use App\Authorization\AuthorizationContext;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\DepartmentPolicy;
use App\Services\ScopedPermissionResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('scoped-permission', function (User $user, string $permission, AuthorizationContext $context): bool {
            return app(ScopedPermissionResolver::class)->allows($user, $permission, $context);
        });

        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
    }
}
