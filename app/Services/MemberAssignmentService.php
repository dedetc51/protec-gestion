<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\MemberAssignmentForm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberAssignmentService
{
    /** @return Collection<int, Department> */
    public function departments(User $actor): Collection
    {
        return Department::with('branches')->orderBy('name')->get()
            ->filter(fn (Department $department): bool => $actor->canIn('members.assign_roles', AuthorizationContext::department($department)));
    }

    public function canManage(User $subject, User $actor): bool
    {
        if ($actor->canIn('technical.manage', AuthorizationContext::global())) {
            return true;
        }
        $departmentIds = $this->departments($actor)->modelKeys();
        if ($departmentIds === []) {
            return false;
        }

        return $subject->memberships()->whereHas('branch', fn (Builder $query): Builder => $query->whereIn('department_id', $departmentIds))->exists()
            || $subject->roleAssignments()->where(function (Builder $query) use ($departmentIds): void {
                $query->where('scope_type', 'department')->whereIn('scope_id', $departmentIds);
            })->exists()
            || $subject->roleAssignments()->where('scope_type', 'branch')->whereIn('scope_id', Branch::whereIn('department_id', $departmentIds)->select('id'))->exists()
            || (! $subject->memberships()->exists() && ! $subject->roleAssignments()->exists());
    }

    public static function rules(): array
    {
        return [
            'memberships' => ['present', 'array', 'max:200'],
            'memberships.*' => ['array:branch_id,starts_at,ends_at'],
            'memberships.*.branch_id' => ['required', 'integer', 'exists:branches,id', 'distinct'],
            'memberships.*.starts_at' => ['nullable', 'date'],
            'memberships.*.ends_at' => ['nullable', 'date', 'after:memberships.*.starts_at'],
            'assignments' => ['present', 'array', 'max:500'],
            'assignments.*' => ['array:role_id,scope_type,scope_id,starts_at,ends_at'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:roles,id'],
            'assignments.*.scope_type' => ['required', Rule::in(['global', 'department', 'branch'])],
            'assignments.*.scope_id' => ['nullable', 'integer'],
            'assignments.*.starts_at' => ['nullable', 'date'],
            'assignments.*.ends_at' => ['nullable', 'date', 'after:assignments.*.starts_at'],
            'represented' => ['sometimes', 'array:membership_ids,assignment_ids'],
            'represented.membership_ids' => ['sometimes', 'array', 'max:200'],
            'represented.membership_ids.*' => ['integer', 'distinct', 'exists:memberships,id'],
            'represented.assignment_ids' => ['sometimes', 'array', 'max:500'],
            'represented.assignment_ids.*' => ['integer', 'distinct', 'exists:role_assignments,id'],
        ];
    }

    /** @param array<int, array<string, mixed>> $memberships
     * @param  array<int, array<string, mixed>>  $assignments
     * @param  array{membership_ids?: list<int>, assignment_ids?: list<int>}  $represented
     */
    public function replace(User $subject, array $memberships, array $assignments, User $actor, array $represented = [], ?string $editorToken = null): void
    {
        Validator::make(compact('memberships', 'assignments', 'represented'), self::rules(), [
            'after' => 'La date de fin doit être postérieure à la date de début.',
            'distinct' => 'Cette antenne est déjà sélectionnée.',
        ])->validate();

        DB::transaction(function () use ($subject, $memberships, $assignments, $actor, $represented, $editorToken): void {
            $technicalRole = Role::where('slug', 'technical-admin')->lockForUpdate()->firstOrFail();
            $hadTechnicalAdmin = RoleAssignment::active()->where('role_id', $technicalRole->id)->where('scope_type', 'global')->whereNull('scope_id')->whereHas('user', fn (Builder $query): Builder => $query->whereNull('deactivated_at'))->exists();
            $subject = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($this->canManage($subject, $actor), 403);
            if ($subject->deactivated_at !== null) {
                throw ValidationException::withMessages(['memberships' => 'Ce membre est désactivé.']);
            }
            $technical = $actor->canIn('technical.manage', AuthorizationContext::global());
            $departments = $this->departments($actor);
            abort_if($departments->isEmpty() && ! $technical, 403);
            $branches = Branch::with('department')->get()->keyBy('id');
            $roles = Role::all()->keyBy('id');
            $departmentIds = $departments->modelKeys();
            $branchIds = $branches->whereIn('department_id', $departmentIds)->modelKeys();

            foreach ($memberships as $index => $row) {
                $branch = $branches->get($row['branch_id']);
                abort_unless($technical || in_array($branch->department_id, $departmentIds, true), 403);
                if ($branch->deactivated_at !== null || $branch->department->deactivated_at !== null) {
                    throw ValidationException::withMessages(["memberships.$index.branch_id" => 'Choisissez une antenne active.']);
                }
            }
            $seen = [];
            foreach ($assignments as $index => $row) {
                $scope = $row['scope_type'];
                $scopeId = $row['scope_id'] ?? null;
                $role = $roles->get($row['role_id']);
                $allowed = match ($scope) {
                    'global' => $technical,
                    'department' => $technical || in_array((int) $scopeId, $departmentIds, true),
                    'branch' => $technical || in_array((int) $scopeId, $branchIds, true),
                };
                abort_unless($allowed, 403);
                $target = match ($scope) {
                    'global' => null,
                    'department' => Department::find($scopeId),
                    'branch' => $branches->get($scopeId),
                };
                if (! $role->allowsScope($scope)) {
                    throw ValidationException::withMessages(["assignments.$index.role_id" => 'Ce rôle ne peut pas être affecté à ce périmètre.']);
                }
                if (($scope === 'global' && $scopeId !== null) || ($scope !== 'global' && ($target === null || $target->deactivated_at !== null))
                    || ($scope === 'branch' && $target->department->deactivated_at !== null)) {
                    throw ValidationException::withMessages(["assignments.$index.scope_id" => 'Choisissez un périmètre actif et compatible.']);
                }
                $key = $row['role_id'].':'.$scope.':'.$scopeId;
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(["assignments.$index.role_id" => 'Ce rôle est déjà sélectionné dans ce périmètre.']);
                }
                $seen[$key] = true;
                if ($scope === 'branch' && ! in_array((int) $scopeId, array_map('intval', array_column($memberships, 'branch_id')), true)) {
                    throw ValidationException::withMessages(["assignments.$index.scope_id" => 'Une responsabilité d’antenne nécessite une appartenance à cette antenne.']);
                }
            }

            $currentMemberships = $subject->memberships()->whereIn('branch_id', $branchIds)->lockForUpdate()->get();
            $currentAssignments = $subject->roleAssignments()->where(function (Builder $query) use ($technical, $departmentIds, $branchIds): void {
                if ($technical) {
                    return;
                }
                $query->where(fn (Builder $query): Builder => $query->where('scope_type', 'department')->whereIn('scope_id', $departmentIds))
                    ->orWhere(fn (Builder $query): Builder => $query->where('scope_type', 'branch')->whereIn('scope_id', $branchIds));
            })->lockForUpdate()->get();
            if ($editorToken !== null) {
                $form = app(MemberAssignmentForm::class);
                $expected = $form->decode($editorToken);
                if ($expected === null || ($expected['actor_id'] ?? null) !== $actor->id || ($expected['subject_id'] ?? null) !== $subject->id
                    || $form->editor($subject, $actor, $expected['scope'], $expected['page'])['manifest'] !== $expected) {
                    throw ValidationException::withMessages(['editor_token' => 'Le formulaire est incomplet ou a changé. Rechargez la page avant de réessayer.']);
                }
            }
            $representedMembershipIds = array_map('intval', $represented['membership_ids'] ?? []);
            $representedAssignmentIds = array_map('intval', $represented['assignment_ids'] ?? []);
            abort_if(array_diff($representedMembershipIds, $currentMemberships->modelKeys()) !== []
                || array_diff($representedAssignmentIds, $currentAssignments->modelKeys()) !== [], 403);
            $before = ['membership_ids' => $currentMemberships->modelKeys(), 'assignment_ids' => $currentAssignments->modelKeys()];
            $at = now()->startOfSecond();
            $membershipIds = $this->replaceRows($subject, $currentMemberships, $memberships, ['branch_id'], 'memberships', $actor, $at, $representedMembershipIds);
            $assignmentIds = $this->replaceRows($subject, $currentAssignments, $assignments, ['role_id', 'scope_type', 'scope_id'], 'assignments', $actor, $at, $representedAssignmentIds);

            if ($hadTechnicalAdmin && ! $this->hasContinuousTechnicalCoverage($technicalRole, $at)) {
                throw ValidationException::withMessages(['assignments' => 'Un administrateur technique doit rester disponible sans interruption, avec une période finale sans date de fin.']);
            }

            AuditEvent::create(['actor_id' => $actor->id, 'event' => 'members.assignments.replaced', 'outcome' => 'success', 'metadata' => [
                'target_id' => $subject->id, 'department_ids' => $departmentIds, 'before' => $before,
                'after' => ['membership_ids' => $membershipIds, 'assignment_ids' => $assignmentIds],
            ]]);
        }, 3);
    }

    /** @param Collection<int, Membership|RoleAssignment> $current
     * @param  array<int, array<string, mixed>>  $desired
     * @param  list<string>  $keys
     * @param  list<int>  $representedIds
     * @return list<int>
     */
    private function replaceRows(User $subject, Collection $current, array $desired, array $keys, string $kind, User $actor, Carbon $at, array $representedIds): array
    {
        $desired = array_map(function (array $row) use ($keys): array {
            $normalized = [];
            foreach ($keys as $key) {
                $normalized[$key] = $key === 'scope_type' ? $row[$key] : (isset($row[$key]) ? (int) $row[$key] : null);
            }
            foreach (['starts_at', 'ends_at'] as $key) {
                $normalized[$key] = empty($row[$key]) ? null : Carbon::parse($row[$key])->toDateTimeString();
            }

            return $normalized;
        }, $desired);
        $matches = [];
        foreach ($desired as $index => $row) {
            $match = $current->first(fn (Model $record): bool => collect($row)->every(fn ($value, $key): bool => in_array($key, ['starts_at', 'ends_at'], true)
                ? $record->{$key}?->toDateTimeString() === $value
                : $record->{$key} === $value));
            if ($match !== null) {
                $matches[$index] = $match->id;
            }
        }
        foreach ($current as $record) {
            if (! in_array($record->id, $representedIds, true) || in_array($record->id, $matches, true) || ($record->ends_at !== null && $record->ends_at->lte($at))) {
                continue;
            }
            $before = $record->only([...$keys, 'starts_at', 'ends_at']);
            $record->ends_at = $at;
            $record->save();
            $this->auditRow($subject, $record, $kind === 'memberships' ? 'members.membership.ended' : 'members.role.revoked', $actor, $before);
        }
        foreach ($desired as $index => $row) {
            if (isset($matches[$index])) {
                continue;
            }
            $previous = $current->filter(fn (Model $record): bool => collect($keys)->every(fn (string $key): bool => $record->{$key} === $row[$key]));
            if ($previous->isNotEmpty() && ($row['starts_at'] === null || Carbon::parse($row['starts_at'])->lt($at))) {
                $row['starts_at'] = $at->toDateTimeString();
            }
            if ($row['ends_at'] !== null && $row['starts_at'] !== null && Carbon::parse($row['ends_at'])->lte(Carbon::parse($row['starts_at']))) {
                throw ValidationException::withMessages(["$kind.$index.ends_at" => 'La nouvelle période doit se terminer après sa date de début.']);
            }
            $relation = $kind === 'memberships' ? $subject->memberships() : $subject->roleAssignments();
            $overlap = $relation->getQuery()->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhereNull('ends_at')->orWhereColumn('ends_at', '>', 'starts_at'));
            foreach ($keys as $key) {
                $overlap->where($key, $row[$key]);
            }
            if ($row['ends_at'] !== null) {
                $overlap->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<', $row['ends_at']));
            }
            if ($row['starts_at'] !== null) {
                $overlap->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $row['starts_at']));
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(["$kind.$index.starts_at" => 'Cette période chevauche une période déjà enregistrée.']);
            }
            $record = $kind === 'memberships' ? $subject->memberships()->create($row) : $subject->roleAssignments()->create($row);
            $matches[$index] = $record->id;
            $this->auditRow($subject, $record, $kind === 'memberships' ? 'members.membership.added' : 'members.role.assigned', $actor, []);
        }
        ksort($matches);

        return array_values(array_unique([...$matches, ...$current->filter(fn (Model $record): bool => $record->ends_at === null || $record->ends_at->gt($at))->modelKeys()]));
    }

    private function hasContinuousTechnicalCoverage(Role $technicalRole, Carbon $at): bool
    {
        $intervals = RoleAssignment::where('role_id', $technicalRole->id)->where('scope_type', 'global')->whereNull('scope_id')
            ->whereHas('user', fn (Builder $query): Builder => $query->whereNull('deactivated_at'))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhereNull('ends_at')->orWhereColumn('ends_at', '>', 'starts_at'))
            ->get()->sortBy(fn (RoleAssignment $assignment): int => $assignment->starts_at?->getTimestamp() ?? PHP_INT_MIN);
        $coveredUntil = $at->copy();
        foreach ($intervals as $interval) {
            if ($interval->starts_at !== null && $interval->starts_at->gt($coveredUntil)) {
                return false;
            }
            if ($interval->ends_at === null) {
                return true;
            }
            if ($interval->ends_at->gt($coveredUntil)) {
                $coveredUntil = $interval->ends_at;
            }
        }

        return false;
    }

    private function auditRow(User $subject, Model $record, string $event, User $actor, array $before): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'event' => $event, 'outcome' => 'success', 'metadata' => [
            'target_id' => $subject->id, 'record_id' => $record->id,
            'scope_type' => $record instanceof Membership ? 'branch' : $record->scope_type,
            'scope_id' => $record instanceof Membership ? $record->branch_id : $record->scope_id,
            'before' => $before, 'after' => $record->only(['branch_id', 'role_id', 'scope_type', 'scope_id', 'starts_at', 'ends_at']),
        ]]);
    }
}
