<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\AuthorizationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMemberAssignmentsRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\MemberAssignmentService;
use App\Support\MemberAssignmentForm;
use Illuminate\Database\Eloquent\Builder;
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

    public function edit(Request $request, User $user, MemberAssignmentService $service, MemberAssignmentForm $form): View
    {
        abort_unless($service->canManage($user, $request->user()), 403);
        $context = $request->validate(['scope' => ['nullable', 'string', 'regex:/^(global|(?:department|branch):[1-9][0-9]*)$/'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $editor = $form->editor($user, $request->user(), $context['scope'] ?? null, (int) ($context['page'] ?? 1));
        $old = $request->session()->getOldInput();
        $oldRows = ['memberships' => $old['memberships'] ?? [], 'assignments' => $old['assignments'] ?? []];
        $restoreOldInput = $form->matches($old, $editor['manifest']);

        return view('admin.assignments.edit', $editor + compact('user', 'oldRows', 'restoreOldInput'));
    }

    public function update(UpdateMemberAssignmentsRequest $request, User $user, MemberAssignmentService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->replace($user, $data['memberships'], $data['assignments'], $request->user(), $data['represented'] ?? []);

        return to_route('admin.assignments.edit', ['user' => $user] + $request->editorContext())->with('status', 'Affectations enregistrées. L’historique est conservé.');
    }
}
