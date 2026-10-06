<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\AuthorizationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMemberAssignmentsRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberAssignmentController extends Controller
{
    public function index(Request $request, MemberAssignmentService $service): View
    {
        $departments = $service->departments($request->user());
        $technical = $request->user()->canIn('technical.manage', AuthorizationContext::global());
        abort_if($departments->isEmpty() && ! $technical, 403);
        $departmentIds = $departments->modelKeys();
        $branchIds = Branch::whereIn('department_id', $departmentIds)->pluck('id')->all();
        $search = $request->validate(['q' => ['nullable', 'string', 'max:255']])['q'] ?? '';
        $users = User::query()
            ->when(! $technical, function (Builder $query) use ($departmentIds, $branchIds): void {
                $query->where(function (Builder $query) use ($departmentIds, $branchIds): void {
                    $query->whereHas('memberships', fn (Builder $query): Builder => $query->whereIn('branch_id', $branchIds))
                        ->orWhereHas('roleAssignments', function (Builder $query) use ($departmentIds, $branchIds): void {
                            $query->where(fn (Builder $query): Builder => $query->where('scope_type', 'department')->whereIn('scope_id', $departmentIds))
                                ->orWhere(fn (Builder $query): Builder => $query->where('scope_type', 'branch')->whereIn('scope_id', $branchIds));
                        })
                        ->orWhere(fn (Builder $query): Builder => $query->doesntHave('memberships')->doesntHave('roleAssignments'));
                });
            })
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->with([
                'memberships' => fn ($query) => $query->active()->whereIn('branch_id', $branchIds)->with('branch'),
                'roleAssignments' => function ($query) use ($technical, $departmentIds, $branchIds): void {
                    $query->active()->with('role')->where(function (Builder $query) use ($technical, $departmentIds, $branchIds): void {
                        if ($technical) {
                            return;
                        }
                        $query->where(fn (Builder $query): Builder => $query->where('scope_type', 'department')->whereIn('scope_id', $departmentIds))
                            ->orWhere(fn (Builder $query): Builder => $query->where('scope_type', 'branch')->whereIn('scope_id', $branchIds));
                    });
                },
            ])->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
        $scopeNames = ['global' => [0 => 'Global'], 'department' => $departments->pluck('name', 'id')->all(), 'branch' => Branch::whereIn('id', $branchIds)->pluck('name', 'id')->all()];

        return view('admin.assignments.index', compact('users', 'search', 'scopeNames'));
    }

    public function edit(Request $request, User $user, MemberAssignmentService $service): View
    {
        abort_unless($service->canManage($user, $request->user()), 403);
        $technical = $request->user()->canIn('technical.manage', AuthorizationContext::global());
        $departments = $service->departments($request->user());
        $branchIds = $departments->flatMap(fn ($department) => $department->branches->modelKeys())->all();
        $roles = Role::orderBy('name')->get();
        $memberships = $user->memberships()->whereIn('branch_id', $branchIds)->orderBy('id')->get();
        $assignments = $user->roleAssignments()->where(function (Builder $query) use ($technical, $departments, $branchIds): void {
            if ($technical) {
                return;
            }
            $query->where(fn (Builder $query): Builder => $query->where('scope_type', 'department')->whereIn('scope_id', $departments->modelKeys()))
                ->orWhere(fn (Builder $query): Builder => $query->where('scope_type', 'branch')->whereIn('scope_id', $branchIds));
        })->with('role')->orderBy('id')->get();
        $membershipRows = [];
        $assignmentRows = [];
        foreach ($departments as $department) {
            if ($department->deactivated_at !== null) {
                continue;
            }
            foreach ($roles->where('allows_department', true) as $role) {
                $assignmentRows[] = $this->assignmentRow($assignments, $role->id, 'department', $department->id, $role->name.' — Département : '.$department->name);
            }
            foreach ($department->branches->whereNull('deactivated_at') as $branch) {
                $record = $memberships->first(fn ($record): bool => $record->branch_id === $branch->id && $this->hasRemainingWindow($record));
                $membershipRows[] = ['record_id' => $record?->id, 'branch_id' => $branch->id, 'label' => $branch->name.' — '.$department->name, 'enabled' => $record !== null, 'starts_at' => $record?->starts_at?->format('Y-m-d\TH:i:s'), 'ends_at' => $record?->ends_at?->format('Y-m-d\TH:i:s')];
                foreach ($roles->where('allows_branch', true) as $role) {
                    $assignmentRows[] = $this->assignmentRow($assignments, $role->id, 'branch', $branch->id, $role->name.' — Antenne : '.$branch->name);
                }
            }
        }
        if ($technical) {
            foreach ($roles->where('allows_global', true) as $role) {
                $assignmentRows[] = $this->assignmentRow($assignments, $role->id, 'global', null, $role->name.' — Global');
            }
        }

        return view('admin.assignments.edit', compact('user', 'membershipRows', 'assignmentRows', 'memberships', 'assignments'));
    }

    public function update(UpdateMemberAssignmentsRequest $request, User $user, MemberAssignmentService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->replace($user, $data['memberships'], $data['assignments'], $request->user(), $data['represented'] ?? []);

        return to_route('admin.assignments.edit', $user)->with('status', 'Affectations enregistrées. L’historique est conservé.');
    }

    private function assignmentRow(Collection $assignments, int $roleId, string $scope, ?int $scopeId, string $label): array
    {
        $record = $assignments->first(fn ($record): bool => $record->role_id === $roleId && $record->scope_type === $scope && $record->scope_id === $scopeId && $this->hasRemainingWindow($record));

        return ['record_id' => $record?->id, 'role_id' => $roleId, 'scope_type' => $scope, 'scope_id' => $scopeId, 'label' => $label, 'enabled' => $record !== null, 'starts_at' => $record?->starts_at?->format('Y-m-d\TH:i:s'), 'ends_at' => $record?->ends_at?->format('Y-m-d\TH:i:s')];
    }

    private function hasRemainingWindow(Model $record): bool
    {
        return ($record->ends_at === null || $record->ends_at->isFuture())
            && ($record->starts_at === null || $record->ends_at === null || $record->ends_at->gt($record->starts_at));
    }
}
