<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Models\AuditEvent;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function store(StoreBranchRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $branch = Branch::create($request->validated());
            $this->audit($request, $branch, 'created', [], ['name' => $branch->name, 'department_id' => $branch->department_id]);
        });

        return to_route('admin.organization.index')->with('status', 'Antenne créée.');
    }

    public function update(StoreBranchRequest $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $branch);
            $before = ['name' => $branch->name, 'department_id' => $branch->department_id];
            $branch->update($request->validated());
            $this->audit($request, $branch, 'updated', $before, ['name' => $branch->name, 'department_id' => $branch->department_id]);
        });

        return to_route('admin.organization.index')->with('status', 'Antenne modifiée.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('delete', $branch);
        DB::transaction(function () use ($request, $branch): void {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('delete', $branch);
            if ($branch->deactivated_at === null) {
                $branch->forceFill(['deactivated_at' => now()])->save();
                $this->audit($request, $branch, 'deactivated', ['active' => true], ['active' => false]);
            }
        });

        return to_route('admin.organization.index')->with('status', 'Antenne désactivée. Son historique est conservé.');
    }

    private function audit(Request $request, Branch $branch, string $action, array $before, array $after): void
    {
        AuditEvent::create(['actor_id' => $request->user()->id, 'event' => 'organization.branch.'.$action, 'outcome' => 'success', 'metadata' => ['target_id' => $branch->id, 'scope_type' => 'branch', 'scope_id' => $branch->id, 'before' => $before, 'after' => $after]]);
    }
}
