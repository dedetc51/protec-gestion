<?php

namespace App\Support;

use App\Authorization\AuthorizationContext;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberAssignmentService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class MemberAssignmentForm
{
    public function __construct(private MemberAssignmentService $service) {}

    public function editor(User $subject, User $actor, ?string $scope = null, int $page = 1): array
    {
        $technical = $actor->canIn('technical.manage', AuthorizationContext::global());
        $departments = $this->service->departments($actor)->whereNull('deactivated_at');
        $scopes = [];
        foreach ($departments as $department) {
            $scopes['department:'.$department->id] = 'Département : '.$department->name;
            foreach ($department->branches->whereNull('deactivated_at') as $branch) {
                $scopes['branch:'.$branch->id] = 'Antenne : '.$branch->name.' — '.$department->name;
            }
        }
        if ($technical) {
            $scopes['global'] = 'Global — Administration technique';
        }
        if ($scope === null) {
            $branchIds = $subject->memberships()->active()->orderBy('id')->pluck('branch_id');
            $scope = $branchIds->map(fn (int $id): string => 'branch:'.$id)->first(fn (string $key): bool => isset($scopes[$key]));
            if ($scope === null && $technical && $subject->roleAssignments()->where('scope_type', 'global')->exists()) {
                $scope = 'global';
            }
            $scope ??= array_key_first($scopes);
        }
        abort_unless($scope !== null && isset($scopes[$scope]), 403);
        [$scopeType, $scopeId] = array_pad(explode(':', $scope, 2), 2, null);
        $scopeId = $scopeId === null ? null : (int) $scopeId;
        $roles = Role::where('allows_'.$scopeType, true)->orderBy('name')->orderBy('id')->paginate(100, ['*'], 'page', $page);
        if ($page > $roles->lastPage()) {
            return $this->editor($subject, $actor, $scope, $roles->lastPage());
        }
        $memberships = $subject->memberships()->where('branch_id', $scopeType === 'branch' ? $scopeId : 0)->orderBy('id')->get();
        $assignments = $subject->roleAssignments()->where('scope_type', $scopeType)->where('scope_id', $scopeId)->with('role')->orderBy('id')->get();
        $membershipRows = [];
        if ($scopeType === 'branch') {
            $record = $memberships->first(fn (Model $record): bool => $this->hasRemainingWindow($record));
            $membershipRows[] = ['record_id' => $record?->id, 'branch_id' => $scopeId, 'label' => $scopes[$scope]] + $this->period($record);
        }
        $assignmentRows = [];
        foreach ($roles as $role) {
            $record = $assignments->first(fn (Model $record): bool => $record->role_id === $role->id && $this->hasRemainingWindow($record));
            $assignmentRows[] = ['record_id' => $record?->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'label' => $role->name.' — '.$scopes[$scope]] + $this->period($record);
        }
        $manifest = ['actor_id' => $actor->id, 'subject_id' => $subject->id, 'scope' => $scope, 'page' => $page, 'role_count' => $roles->total()];
        foreach (['memberships' => $membershipRows, 'assignments' => $assignmentRows] as $key => $rows) {
            $manifest[$key] = array_map(fn (array $row): array => array_diff_key($row, ['label' => true]), $rows);
        }
        $editorToken = Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR));

        return compact('scopes', 'scope', 'roles', 'membershipRows', 'assignmentRows', 'memberships', 'assignments', 'manifest', 'editorToken');
    }

    public function decode(mixed $token): ?array
    {
        if (! is_string($token)) {
            return null;
        }
        try {
            $manifest = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        if (! is_array($manifest) || ! is_string($manifest['scope'] ?? null)
            || ! preg_match('/^(global|(?:department|branch):[1-9][0-9]*)$/', $manifest['scope'])
            || ! is_int($manifest['page'] ?? null) || $manifest['page'] < 1) {
            return null;
        }

        return $manifest;
    }

    public function matches(array $input, array $manifest): bool
    {
        if (($input['selection_mode'] ?? null) !== '1' || ($input['submission_complete'] ?? null) !== '1'
            || $this->decode($input['editor_token'] ?? null) !== $manifest) {
            return false;
        }
        foreach (['memberships' => ['branch_id'], 'assignments' => ['role_id', 'scope_type', 'scope_id']] as $key => $identities) {
            $rows = $input[$key] ?? [];
            if (! is_array($rows) || array_keys($rows) !== array_keys($manifest[$key])) {
                return false;
            }
            foreach ($rows as $index => $row) {
                if (! is_array($row) || array_diff(array_keys($row), [...$identities, 'starts_at', 'ends_at', 'enabled']) !== []
                    || (array_key_exists('enabled', $row) && $row['enabled'] !== '1')) {
                    return false;
                }
                foreach ($identities as $identity) {
                    $value = $row[$identity] ?? null;
                    if (! array_key_exists($identity, $row) || (! is_string($value) && ! is_int($value) && $value !== null)
                        || (string) $value !== (string) $manifest[$key][$index][$identity]) {
                        return false;
                    }
                }
                foreach (['starts_at', 'ends_at'] as $date) {
                    if (! array_key_exists($date, $row) || (! is_string($row[$date]) && $row[$date] !== null)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function represented(array $manifest): array
    {
        return [
            'membership_ids' => array_values(array_filter(array_column($manifest['memberships'], 'record_id'))),
            'assignment_ids' => array_values(array_filter(array_column($manifest['assignments'], 'record_id'))),
        ];
    }

    private function period(?Model $record): array
    {
        return ['enabled' => $record !== null, 'starts_at' => $record?->starts_at?->format('Y-m-d\TH:i:s'), 'ends_at' => $record?->ends_at?->format('Y-m-d\TH:i:s')];
    }

    private function hasRemainingWindow(Model $record): bool
    {
        return ($record->ends_at === null || $record->ends_at->isFuture())
            && ($record->starts_at === null || $record->ends_at === null || $record->ends_at->gt($record->starts_at));
    }
}
