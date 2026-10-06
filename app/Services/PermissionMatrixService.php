<?php

namespace App\Services;

use App\Authorization\AuthorizationContext;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PermissionMatrixService
{
    public function canManageGlobal(User $actor): bool
    {
        return $actor->canIn('permissions.manage_global', AuthorizationContext::global());
    }

    public function canManageDepartment(User $actor, Department $department): bool
    {
        return $department->deactivated_at === null
            && $actor->canIn('permissions.manage_department', AuthorizationContext::department($department));
    }

    /** @return Collection<int, Department> */
    public function departments(User $actor): Collection
    {
        return Department::whereNull('deactivated_at')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (Department $department): bool => $this->canManageDepartment($actor, $department));
    }

    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        return Role::where('slug', '!=', 'technical-admin')
            ->where(fn (Builder $query): Builder => $query->where('allows_department', true)->orWhere('allows_branch', true))
            ->orderBy('id')->get();
    }

    /** @return Collection<int, Permission> */
    public function permissions(): Collection
    {
        return Permission::orderBy('id')->get()->filter(fn (Permission $permission): bool => PermissionCatalog::isDelegable($permission->key));
    }

    public static function rules(bool $departmental): array
    {
        return [
            'cells' => ['present', 'array', 'max:200'],
            'cells.*' => ['array', 'max:500'],
            'cells.*.*' => [$departmental ? Rule::in(['inherit', 'grant', 'deny']) : 'boolean'],
            'represented' => ['required', 'array', 'max:200'],
            'represented.*' => ['required', 'string', 'max:5000'],
            'submission_complete' => ['required', 'in:1'],
        ];
    }

    public static function messages(): array
    {
        return [
            'cells.present' => 'La matrice doit être fournie.',
            'cells.array' => 'La matrice est invalide.',
            'cells.*.array' => 'Les permissions de ce rôle sont invalides.',
            'cells.*.*.boolean' => 'Choisissez une permission accordée ou refusée.',
            'cells.*.*.in' => 'Choisissez Héritée, Accordée ou Refusée.',
            'represented.required' => 'Le formulaire est incomplet. Rechargez la page avant de réessayer.',
            'submission_complete.required' => 'Le formulaire est incomplet. Rechargez la page avant de réessayer.',
            'array' => 'La matrice est invalide.',
            'string' => 'La déclaration des permissions est invalide.',
            'required' => 'Ce champ est obligatoire.',
            'in' => 'La sélection est invalide.',
            'max' => 'La matrice dépasse la taille autorisée.',
        ];
    }

    /**
     * A complete manifest and final sentinel distinguish unchecked boxes from a truncated form.
     *
     * @param  array{cells: array, represented: array, submission_complete: string}  $data
     * @return array<int, array<int, bool|string>>
     */
    public function submissionCells(array $data, bool $departmental): array
    {
        if (! $this->hasCompleteManifest($data)) {
            throw ValidationException::withMessages(['represented' => 'Le formulaire est incomplet. Rechargez la page avant de réessayer.']);
        }
        $cells = $data['cells'];
        if (! $departmental) {
            foreach ($data['represented'] as $roleId => $manifest) {
                foreach (explode(',', $manifest) as $permissionId) {
                    $cells[$roleId][$permissionId] ??= false;
                }
            }
        }

        return $cells;
    }

    public function canRestoreGlobalInput(array $data): bool
    {
        if (! $this->hasCompleteManifest($data) || ! is_array($data['cells'] ?? null)) {
            return false;
        }
        foreach ($data['cells'] as $roleId => $states) {
            if (! array_key_exists($roleId, $data['represented']) || ! is_array($states)) {
                return false;
            }
            $permissionIds = explode(',', $data['represented'][$roleId]);
            foreach ($states as $permissionId => $state) {
                if (! in_array((string) $permissionId, $permissionIds, true) || is_array($state)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasCompleteManifest(array $data): bool
    {
        if (! in_array($data['submission_complete'] ?? null, ['1', 1], true) || ! is_array($data['represented'] ?? null)) {
            return false;
        }
        if (! $this->sameIds(array_keys($data['represented']), $this->roles()->modelKeys())) {
            return false;
        }
        $permissionIds = $this->permissions()->modelKeys();
        foreach ($data['represented'] as $manifest) {
            if (! is_string($manifest) || ! $this->sameIds(explode(',', $manifest), $permissionIds)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<int, bool|int|string>> $cells */
    public function replaceGlobal(array $cells, User $actor): void
    {
        $this->replace($cells, $actor, null);
    }

    /** @param array<int, array<int, string>> $cells */
    public function replaceDepartment(Department $department, array $cells, User $actor): void
    {
        $this->replace($cells, $actor, $department);
    }

    private function replace(array $cells, User $actor, ?Department $department): void
    {
        DB::transaction(function () use ($cells, $actor, $department): void {
            // Parent locks serialize edits even for cells whose pivot row does not yet exist.
            Role::orderBy('id')->lockForUpdate()->get();
            Permission::orderBy('id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            if ($department !== null) {
                $department = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();
            }
            abort_unless($department === null ? $this->canManageGlobal($actor) : $this->canManageDepartment($actor, $department), 403);
            $this->validateCells($cells, $department !== null);
            $table = $department === null ? 'role_permissions' : 'department_role_permissions';
            $query = DB::table($table)->whereIn('role_id', array_keys($cells));
            if ($department !== null) {
                $query->where('department_id', $department->id);
            }
            $current = $query->lockForUpdate()->get()->keyBy(fn (object $row): string => $row->role_id.':'.$row->permission_id);
            $changes = [];
            foreach ($cells as $roleId => $permissions) {
                foreach ($permissions as $permissionId => $state) {
                    $row = $current->get($roleId.':'.$permissionId);
                    $before = $department === null ? (bool) ($row?->granted ?? false) : ($row?->state ?? 'inherit');
                    $after = $department === null ? (bool) $state : $state;
                    if ($before === $after) {
                        continue;
                    }
                    $key = ['role_id' => (int) $roleId, 'permission_id' => (int) $permissionId];
                    if ($department !== null) {
                        $key['department_id'] = $department->id;
                    }
                    if ($after === 'inherit') {
                        DB::table($table)->where($key)->delete();
                    } else {
                        DB::table($table)->updateOrInsert($key, [$department === null ? 'granted' : 'state' => $after]);
                    }
                    $changes[] = ['role_id' => (int) $roleId, 'permission_id' => (int) $permissionId, 'before' => $before, 'after' => $after];
                }
            }
            AuditEvent::create([
                'actor_id' => $actor->id,
                'event' => $department === null ? 'permissions.global.updated' : 'permissions.department.updated',
                'outcome' => 'success',
                'metadata' => ['target_id' => $department?->id, 'scope_type' => $department === null ? 'global' : 'department', 'scope_id' => $department?->id, 'changes' => $changes],
            ]);
        });
    }

    private function validateCells(array $cells, bool $departmental): void
    {
        Validator::make(['cells' => $cells], array_intersect_key(self::rules($departmental), array_flip(['cells', 'cells.*', 'cells.*.*'])), self::messages())->validate();
        $roles = $this->roles()->keyBy('id');
        $permissions = $this->permissions()->keyBy('id');
        foreach ($cells as $roleId => $states) {
            foreach ($states as $permissionId => $state) {
                if (! $roles->has($roleId) || ! $permissions->has($permissionId)) {
                    throw ValidationException::withMessages(["cells.$roleId.$permissionId" => 'Ce rôle ou cette permission ne peut pas être modifié dans cette matrice.']);
                }
            }
        }
        if (! $this->sameIds(array_keys($cells), $roles->modelKeys())) {
            throw ValidationException::withMessages(['cells' => 'La matrice est incomplète. Rechargez la page avant de réessayer.']);
        }
        foreach ($cells as $states) {
            if (! $this->sameIds(array_keys($states), $permissions->modelKeys())) {
                throw ValidationException::withMessages(['cells' => 'La matrice est incomplète. Rechargez la page avant de réessayer.']);
            }
        }
    }

    private function sameIds(array $submitted, array $expected): bool
    {
        foreach ($submitted as $id) {
            if (! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                return false;
            }
        }
        $submitted = array_map('intval', $submitted);
        sort($submitted);
        sort($expected);

        return $submitted === $expected;
    }
}
