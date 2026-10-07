<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentPermissionsRequest;
use App\Http\Requests\Admin\UpdateGlobalPermissionsRequest;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionMatrixService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PermissionMatrixController extends Controller
{
    public function index(Request $request, PermissionMatrixService $service): View|RedirectResponse
    {
        if (! $service->canManageGlobal($request->user())) {
            $department = $service->departments($request->user())->first();
            abort_if($department === null, 403);

            return to_route('admin.permissions.department', $department);
        }

        return $this->matrix($request, $service, null);
    }

    public function department(Request $request, Department $department, PermissionMatrixService $service): View
    {
        abort_unless($service->canManageDepartment($request->user(), $department), 403);

        return $this->matrix($request, $service, $department);
    }

    public function updateGlobal(UpdateGlobalPermissionsRequest $request, PermissionMatrixService $service): RedirectResponse
    {
        $service->replaceGlobal($service->submissionCells($request->validated(), false), $request->user());

        return to_route('admin.permissions.index')->with('status', 'Permissions générales enregistrées.');
    }

    public function updateDepartment(UpdateDepartmentPermissionsRequest $request, Department $department, PermissionMatrixService $service): RedirectResponse
    {
        $service->replaceDepartment($department, $service->submissionCells($request->validated(), true), $request->user());

        return to_route('admin.permissions.department', $department)->with('status', 'Permissions départementales enregistrées.');
    }

    private function matrix(Request $request, PermissionMatrixService $service, ?Department $department): View
    {
        $technical = $service->canManageGlobal($request->user());
        $departments = $service->departments($request->user());
        $roles = $service->roles()->load('permissions');
        if ($department === null) {
            $roles = $roles->merge(Role::where('slug', 'technical-admin')->with('permissions')->get());
        }
        $permissions = Permission::orderBy('id')->get();
        $editablePermissionIds = $service->permissions()->modelKeys();
        $groups = [];
        foreach (PermissionCatalog::groups() as $key => $group) {
            $rows = $permissions->filter(fn (Permission $permission): bool => array_key_exists($permission->key, $group['permissions']));
            if ($rows->isNotEmpty()) {
                $groups[$key] = ['name' => $group['name'], 'permissions' => $rows];
            }
        }
        $uncategorized = $permissions->reject(fn (Permission $permission): bool => array_key_exists($permission->key, PermissionCatalog::all()));
        if ($uncategorized->isNotEmpty()) {
            $groups['other'] = ['name' => 'Autres permissions', 'permissions' => $uncategorized];
        }
        $overrides = $department === null ? collect() : DepartmentRolePermission::where('department_id', $department->id)->get()->keyBy(fn ($row): string => $row->role_id.':'.$row->permission_id);
        $restoreOldInput = $request->session()->hasOldInput('cells')
            && ($department !== null || $service->canRestoreGlobalInput($request->session()->getOldInput()));

        return view('admin.permissions.index', compact('technical', 'departments', 'department', 'roles', 'groups', 'editablePermissionIds', 'overrides', 'restoreOldInput'));
    }
}
