<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\AuthorizationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Models\AuditEvent;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $technical = $request->user()->canIn('departments.manage', AuthorizationContext::global());
        $departments = Department::with('branches')->orderBy('name')->get()
            ->filter(fn (Department $department): bool => $technical || $request->user()->can('manage', $department));
        abort_if($departments->isEmpty() && ! $technical, 403);

        return view('admin.organization.index', compact('departments', 'technical'));
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $department = Department::create($request->validated());
            $this->audit($request, $department, 'created', [], ['name' => $department->name]);
        });

        return to_route('admin.organization.index')->with('status', 'Département créé.');
    }

    public function update(StoreDepartmentRequest $request, Department $department): RedirectResponse
    {
        DB::transaction(function () use ($request, $department): void {
            $department = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $department->name];
            $department->update($request->validated());
            $this->audit($request, $department, 'updated', $before, ['name' => $department->name]);
        });

        return to_route('admin.organization.index')->with('status', 'Département modifié.');
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        abort_unless($request->user()->canIn('departments.manage', AuthorizationContext::global()), 403);
        DB::transaction(function () use ($request, $department): void {
            $department = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();
            if ($department->deactivated_at === null) {
                $department->forceFill(['deactivated_at' => now()])->save();
                $this->audit($request, $department, 'deactivated', ['active' => true], ['active' => false]);
            }
        });

        return to_route('admin.organization.index')->with('status', 'Département désactivé. Son historique est conservé.');
    }

    private function audit(Request $request, Department $department, string $action, array $before, array $after): void
    {
        AuditEvent::create(['actor_id' => $request->user()->id, 'event' => 'organization.department.'.$action, 'outcome' => 'success', 'metadata' => ['target_id' => $department->id, 'scope_type' => 'department', 'scope_id' => $department->id, 'before' => $before, 'after' => $after]]);
    }
}
