<?php

namespace App\Support;

final class AssociationRoleCatalog
{
    private const array ROLES = [
        'technical-admin' => ['name' => 'Administrateur technique', 'scopes' => ['global'], 'domains' => ['*']],
        'department-president' => ['name' => 'Président départemental', 'scopes' => ['department'], 'domains' => ['association']],
        'operations-manager' => ['name' => 'Responsable opérationnel', 'scopes' => ['department', 'branch'], 'domains' => ['operations']],
        'operations-deputy' => ['name' => 'Responsable opérationnel adjoint', 'scopes' => ['department', 'branch'], 'domains' => ['operations']],
        'branch-manager' => ['name' => 'Responsable d’antenne', 'scopes' => ['branch'], 'domains' => ['members', 'organization', 'equipment', 'vehicles', 'logistics', 'operations', 'training', 'documents', 'communications', 'audit']],
        'branch-deputy' => ['name' => 'Responsable d’antenne adjoint', 'scopes' => ['branch'], 'domains' => ['members', 'organization', 'equipment', 'vehicles', 'logistics', 'operations', 'training', 'documents', 'communications', 'audit']],
        'vehicle-manager' => ['name' => 'Responsable véhicules', 'scopes' => ['branch'], 'domains' => ['vehicles']],
        'vehicle-deputy' => ['name' => 'Responsable véhicules adjoint', 'scopes' => ['branch'], 'domains' => ['vehicles']],
        'equipment-manager' => ['name' => 'Responsable matériel', 'scopes' => ['branch'], 'domains' => ['equipment']],
        'equipment-deputy' => ['name' => 'Responsable matériel adjoint', 'scopes' => ['branch'], 'domains' => ['equipment']],
        'logistics-manager' => ['name' => 'Responsable logistique', 'scopes' => ['branch'], 'domains' => ['logistics']],
        'logistics-deputy' => ['name' => 'Responsable logistique adjoint', 'scopes' => ['branch'], 'domains' => ['logistics']],
        'volunteer' => ['name' => 'Bénévole', 'scopes' => ['branch'], 'domains' => []],
    ];

    private const array BASE_PERMISSIONS = [
        'members.view', 'branches.view', 'equipment.view', 'vehicles.view',
        'logistics.view', 'operations.view', 'training.view', 'documents.view', 'communications.view',
    ];

    /** @return array<string, array{name: string, allows_global: bool, allows_department: bool, allows_branch: bool, permissions: list<string>}> */
    public static function all(): array
    {
        $roles = [];
        foreach (self::ROLES as $slug => $definition) {
            $roles[$slug] = [
                'name' => $definition['name'],
                'allows_global' => in_array('global', $definition['scopes'], true),
                'allows_department' => in_array('department', $definition['scopes'], true),
                'allows_branch' => in_array('branch', $definition['scopes'], true),
                'permissions' => self::defaultPermissions($slug, $definition['domains']),
            ];
        }

        return $roles;
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    private static function defaultPermissions(string $slug, array $domains): array
    {
        if ($domains === ['*']) {
            return array_keys(PermissionCatalog::all());
        }

        $permissions = self::BASE_PERMISSIONS;
        foreach (PermissionCatalog::groups() as $domain => $group) {
            if ($domains !== ['association'] && ! in_array($domain, $domains, true)) {
                continue;
            }

            foreach ($group['permissions'] as $key => $permission) {
                if (! $permission['delegable']) {
                    continue;
                }
                if (str_ends_with($slug, '-deputy') && (str_ends_with($key, '.delete') || str_ends_with($key, '.validate') || str_starts_with($key, 'permissions.'))) {
                    continue;
                }

                $permissions[] = $key;
            }
        }

        return array_values(array_unique($permissions));
    }
}
