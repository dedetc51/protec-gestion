<?php

namespace App\Support;

final class PermissionCatalog
{
    private const array GROUPS = [
        'members' => [
            'name' => 'Membres et bénévoles',
            'permissions' => [
                'members.view' => ['name' => 'Consulter les membres', 'delegable' => true],
                'members.create' => ['name' => 'Créer un membre', 'delegable' => true],
                'members.update' => ['name' => 'Modifier un membre', 'delegable' => true],
                'members.delete' => ['name' => 'Supprimer un membre', 'delegable' => true],
                'members.assign_roles' => ['name' => 'Affecter les rôles', 'delegable' => true],
                'members.export' => ['name' => 'Exporter les membres', 'delegable' => true],
            ],
        ],
        'organization' => [
            'name' => 'Départements et antennes',
            'permissions' => [
                'departments.view' => ['name' => 'Consulter les départements', 'delegable' => true],
                'branches.view' => ['name' => 'Consulter les antennes', 'delegable' => true],
                'branches.create' => ['name' => 'Créer une antenne', 'delegable' => true],
                'branches.update' => ['name' => 'Modifier une antenne', 'delegable' => true],
                'branches.delete' => ['name' => 'Supprimer une antenne', 'delegable' => true],
                'branches.manage' => ['name' => 'Gérer les antennes', 'delegable' => true],
            ],
        ],
        'equipment' => [
            'name' => 'Matériel',
            'permissions' => [
                'equipment.view' => ['name' => 'Consulter', 'delegable' => true],
                'equipment.create' => ['name' => 'Créer', 'delegable' => true],
                'equipment.update' => ['name' => 'Modifier', 'delegable' => true],
                'equipment.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'equipment.assign' => ['name' => 'Affecter', 'delegable' => true],
                'equipment.export' => ['name' => 'Exporter', 'delegable' => true],
            ],
        ],
        'vehicles' => [
            'name' => 'Véhicules',
            'permissions' => [
                'vehicles.view' => ['name' => 'Consulter', 'delegable' => true],
                'vehicles.create' => ['name' => 'Créer', 'delegable' => true],
                'vehicles.update' => ['name' => 'Modifier', 'delegable' => true],
                'vehicles.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'vehicles.assign' => ['name' => 'Affecter', 'delegable' => true],
                'vehicles.export' => ['name' => 'Exporter', 'delegable' => true],
            ],
        ],
        'logistics' => [
            'name' => 'Logistique',
            'permissions' => [
                'logistics.view' => ['name' => 'Consulter', 'delegable' => true],
                'logistics.create' => ['name' => 'Créer', 'delegable' => true],
                'logistics.update' => ['name' => 'Modifier', 'delegable' => true],
                'logistics.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'logistics.assign' => ['name' => 'Affecter', 'delegable' => true],
                'logistics.export' => ['name' => 'Exporter', 'delegable' => true],
            ],
        ],
        'operations' => [
            'name' => 'Opérations et dispositifs',
            'permissions' => [
                'operations.view' => ['name' => 'Consulter', 'delegable' => true],
                'operations.create' => ['name' => 'Créer', 'delegable' => true],
                'operations.update' => ['name' => 'Modifier', 'delegable' => true],
                'operations.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'operations.assign' => ['name' => 'Affecter', 'delegable' => true],
                'operations.export' => ['name' => 'Exporter', 'delegable' => true],
                'operations.validate' => ['name' => 'Valider', 'delegable' => true],
            ],
        ],
        'training' => [
            'name' => 'Formations',
            'permissions' => [
                'training.view' => ['name' => 'Consulter', 'delegable' => true],
                'training.create' => ['name' => 'Créer', 'delegable' => true],
                'training.update' => ['name' => 'Modifier', 'delegable' => true],
                'training.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'training.assign' => ['name' => 'Affecter', 'delegable' => true],
                'training.export' => ['name' => 'Exporter', 'delegable' => true],
                'training.validate' => ['name' => 'Valider', 'delegable' => true],
            ],
        ],
        'documents' => [
            'name' => 'Documents',
            'permissions' => [
                'documents.view' => ['name' => 'Consulter', 'delegable' => true],
                'documents.create' => ['name' => 'Créer', 'delegable' => true],
                'documents.update' => ['name' => 'Modifier', 'delegable' => true],
                'documents.delete' => ['name' => 'Supprimer', 'delegable' => true],
                'documents.assign' => ['name' => 'Affecter', 'delegable' => true],
                'documents.export' => ['name' => 'Exporter', 'delegable' => true],
            ],
        ],
        'communications' => [
            'name' => 'Communications et relances',
            'permissions' => [
                'communications.view' => ['name' => 'Consulter les communications', 'delegable' => true],
                'communications.create' => ['name' => 'Créer une communication', 'delegable' => true],
                'communications.update' => ['name' => 'Modifier une communication', 'delegable' => true],
                'communications.delete' => ['name' => 'Supprimer une communication', 'delegable' => true],
                'communications.send' => ['name' => 'Envoyer une communication', 'delegable' => true],
            ],
        ],
        'settings' => [
            'name' => 'Paramètres associatifs',
            'permissions' => [
                'settings.view' => ['name' => 'Consulter les paramètres associatifs', 'delegable' => true],
                'settings.update' => ['name' => 'Modifier les paramètres associatifs', 'delegable' => true],
                'permissions.manage_department' => ['name' => 'Gérer les permissions départementales', 'delegable' => true],
            ],
        ],
        'audit' => [
            'name' => 'Journal d’audit',
            'permissions' => [
                'audit.view' => ['name' => 'Consulter le journal d’audit', 'delegable' => true],
                'audit.export' => ['name' => 'Exporter le journal d’audit', 'delegable' => true],
            ],
        ],
        'technical' => [
            'name' => 'Administration technique',
            'permissions' => [
                'departments.manage' => ['name' => 'Gérer les départements', 'delegable' => false],
                'permissions.manage_global' => ['name' => 'Gérer les permissions générales', 'delegable' => false],
                'technical.manage' => ['name' => 'Administrer la plateforme', 'delegable' => false],
            ],
        ],
    ];

    /** @return array<string, array{name: string, permissions: array<string, array{name: string, delegable: bool}>}> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** @return array<string, array{name: string, delegable: bool}> */
    public static function all(): array
    {
        $permissions = [];
        foreach (self::GROUPS as $group) {
            $permissions += $group['permissions'];
        }

        return $permissions;
    }

    public static function isDelegable(string $key): bool
    {
        return self::all()[$key]['delegable'] ?? false;
    }
}
