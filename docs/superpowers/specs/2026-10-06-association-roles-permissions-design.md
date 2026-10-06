# Rôles et permissions de l'association

## Objectif

Protec-Gestion doit permettre à un membre d'appartenir à plusieurs antennes, d'y cumuler plusieurs responsabilités et de détenir, en parallèle, une responsabilité départementale. Les autorisations doivent être administrables dans une matrice lisible et rester strictement limitées au périmètre de chaque affectation.

Le système doit distinguer l'administration technique globale des fonctions associatives. Il doit également permettre d'ajouter ultérieurement des rôles et des permissions sans migration structurelle supplémentaire.

## Périmètres organisationnels

Le modèle comprend trois niveaux :

1. **Global** : administration technique de la plateforme.
2. **Département** : gouvernance et responsabilités applicables à toutes les antennes d'un département.
3. **Antenne** : responsabilités locales et accès aux données d'une antenne précise.

Une antenne appartient à un département. Un membre peut appartenir à plusieurs antennes, y compris dans des départements différents si l'organisation l'exige ultérieurement.

## Rôles initiaux

### Rôle technique global

- Administrateur technique

Ce rôle reste hors de la hiérarchie associative. Il gère la plateforme, les règles générales, la sécurité et les paramètres techniques. Aucun responsable associatif ne peut obtenir ces permissions par héritage ou par surcharge départementale.

### Rôles départementaux

- Président départemental
- Responsable opérationnel départemental
- Responsable opérationnel départemental adjoint

### Rôles d'antenne

- Responsable d'antenne
- Responsable d'antenne adjoint
- Responsable opérationnel d'antenne
- Responsable opérationnel d'antenne adjoint
- Responsable véhicules
- Responsable véhicules adjoint
- Responsable matériel
- Responsable matériel adjoint
- Responsable logistique
- Responsable logistique adjoint
- Bénévole

Les rôles Responsable opérationnel et Responsable opérationnel adjoint existent donc aux deux portées, départementale et locale. Les libellés affichés rendent cette portée explicite.

## Modèle de données

Les entités principales sont :

- `departments` : départements gérés par la plateforme ;
- `branches` : antennes rattachées à un département ;
- `roles` : catalogue extensible des rôles, avec les portées autorisées ;
- `permissions` : catalogue extensible des capacités métier ;
- `role_permissions` : règles générales définies par l'administrateur ;
- `department_role_permissions` : surcharge départementale à trois états ;
- `memberships` : appartenance d'un utilisateur à une antenne ;
- `role_assignments` : attribution d'un rôle à un utilisateur dans un département ou une antenne.

Une affectation comporte une date de début facultative et une date de fin facultative. Une affectation terminée est conservée pour l'historique au lieu d'être supprimée. Les contraintes de base interdisent d'attribuer un rôle à une portée non autorisée.

Le champ historique `users.role` reste temporairement compatible pendant la migration. L'administrateur existant est converti en administrateur technique avant que les contrôles ne basculent sur le nouveau modèle.

## Calcul des autorisations

Une permission effective est calculée à partir :

1. de l'utilisateur authentifié ;
2. de ses affectations actives ;
3. du périmètre demandé ;
4. des permissions générales de chaque rôle ;
5. des éventuelles surcharges du département.

Les droits issus de plusieurs rôles se cumulent dans un même périmètre. Une permission acquise dans une antenne ne donne aucun accès à une autre antenne. Une permission départementale couvre les antennes de ce département uniquement.

L'administrateur technique possède les permissions globales. Le Président départemental peut gérer les antennes, membres, affectations et règles associatives de son département, dans la limite des permissions que l'administrateur a déclarées délégables. Les permissions techniques ne sont jamais délégables.

## Héritage départemental

Chaque cellule départementale possède trois états :

- **Héritée** : utilise la règle générale ;
- **Accordée** : active explicitement la permission dans le département ;
- **Refusée** : désactive explicitement la permission dans le département.

Une surcharge ne peut concerner qu'une permission associative délégable. L'administrateur peut modifier les règles générales et les surcharges de tous les départements. Un Président départemental ne peut modifier que les surcharges de son département.

## Catalogue initial de permissions

Les permissions sont regroupées par domaine :

- membres et bénévoles ;
- départements et antennes ;
- matériel ;
- véhicules ;
- logistique ;
- opérations et dispositifs ;
- formations ;
- documents ;
- communications et relances ;
- paramètres associatifs ;
- journal d'audit ;
- administration technique.

Chaque domaine utilise des actions explicites lorsque pertinentes : consulter, créer, modifier, supprimer, affecter, exporter et valider. Les clés techniques sont stables, par exemple `equipment.view`, `equipment.update` ou `members.assign_roles`.

Le rôle Bénévole reçoit des droits de consultation de base. Les responsables reçoivent les permissions de leur domaine. Les adjoints ne reçoivent pas par défaut les opérations sensibles de suppression, de validation définitive ou de gestion des permissions.

## Interfaces d'administration

### Antennes

Cette page permet de créer, modifier, désactiver et consulter les antennes autorisées. Un Président départemental ne voit que son département.

### Affectations

Cette page permet de rechercher un membre, de gérer ses appartenances à plusieurs antennes et de lui attribuer plusieurs rôles. Chaque attribution affiche son périmètre et ses dates d'effet. Les choix proposés sont filtrés selon la portée autorisée du rôle.

### Permissions

La page principale affiche :

- un sélecteur de périmètre général ou départemental ;
- les permissions en lignes, regroupées par domaine ;
- les rôles en colonnes ;
- des cases à cocher pour la matrice générale ;
- des contrôles à trois états pour les surcharges départementales ;
- une légende visible et un bouton « Enregistrer les modifications ».

La première colonne et l'en-tête restent visibles pendant le défilement. La page permet de filtrer par domaine et de rechercher une permission. Sur écran étroit, l'utilisateur sélectionne un rôle puis modifie sa liste de permissions ; la matrice complète n'est pas comprimée.

L'identité visuelle reste cohérente avec Protec-Gestion : interface opérationnelle, lisible, contrastée, inspirée des outils de poste de commandement plutôt que d'un tableau SaaS générique. Les états hérité, accordé et refusé ne reposent jamais sur la couleur seule.

## Contrôles serveur

Toutes les routes métier utilisent des politiques ou des contrôles d'autorisation Laravel. L'interface peut masquer les actions interdites, mais cette présentation ne remplace jamais le contrôle serveur.

Les entrées doivent vérifier :

- l'existence et l'activité de l'utilisateur ;
- l'activité de l'affectation ;
- la compatibilité du rôle avec le périmètre ;
- l'appartenance de l'antenne au département ;
- le droit de l'acteur à administrer ce périmètre ;
- le caractère délégable de la permission modifiée.

Les mises à jour groupées de la matrice sont transactionnelles : aucune cellule n'est enregistrée si une autre cellule de la requête est invalide.

## Audit

Le journal d'audit enregistre au minimum :

- création, modification et désactivation d'un département ou d'une antenne ;
- ajout et fin d'une appartenance ;
- attribution et révocation d'un rôle ;
- modification de la matrice générale ;
- modification d'une surcharge départementale.

Chaque événement contient l'auteur, la cible, le périmètre, l'ancienne valeur et la nouvelle valeur, sans inclure de secret.

## Tests d'acceptation

Les tests doivent démontrer que :

- un membre peut appartenir à plusieurs antennes et cumuler plusieurs rôles ;
- les droits de plusieurs rôles se cumulent dans le même périmètre ;
- un rôle local n'accorde rien dans une autre antenne ;
- un rôle départemental couvre les antennes de son département ;
- un responsable opérationnel peut être affecté au département ou à une antenne ;
- un Président départemental ne peut gérer que son département ;
- une surcharge héritée, accordée ou refusée produit le résultat attendu ;
- une permission technique ne peut pas être déléguée ;
- une affectation expirée ne donne plus de droit ;
- toute modification sensible produit un événement d'audit ;
- les pages et actions interdites renvoient une réponse 403 ;
- la matrice reste utilisable au clavier et sur écran étroit.

## Déploiement et retour arrière

Le déploiement suit une transition compatible : création des nouvelles tables, alimentation du catalogue, conversion de l'administrateur existant, puis activation des nouveaux contrôles. Les migrations ne suppriment pas immédiatement `users.role`.

Avant migration, le processus de déploiement conserve son dump PostgreSQL vérifié. Un retour arrière applicatif reste possible tant que l'ancien champ est conservé. La suppression de ce champ fera l'objet d'une migration séparée après validation en production.

