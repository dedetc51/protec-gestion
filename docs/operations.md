# Exploitation de Protec-Gestion

## Déploiement

Depuis un poste autorisé sur le LAN ou le VPN, exporter `PROTEC_SSH_TARGET` et
`PROTEC_GIT_REVISION` (SHA Git complet de 40 caractères), puis exécuter
`ops/deploy/deploy.sh`. La cible doit déjà contenir le fichier secret, mode
`0600`, `/opt/protec-gestion/shared/.env`. Le script conserve chaque release,
effectue une sauvegarde avant migration et ne bascule le lien `current`
qu'après migrations et contrôles de santé.
Le déploiement refuse toute mutation si ce fichier ne définit pas exactement
une occurrence de `APP_ENV=production` et une occurrence de `APP_DEBUG=false` ;
les clés absentes ou dupliquées sont refusées.
La production doit définir `HTTP_BIND_IP` sur l'adresse privée de la VM et
`HTTP_PORT=8080`; ce même port est injecté dans le pare-feu invité. Chaque
release exporte `APP_IMAGE_TAG` avec son SHA exact, y compris lors d'un rollback.
Les secrets du premier administrateur ne vont jamais dans `.env`. Les placer
ensemble dans `/opt/protec-gestion/shared/initial-admin.env`, mode `0600` ; le
déploiement les injecte uniquement dans la commande bootstrap puis supprime ce
fichier après réussite. Ce fichier est obligatoire au premier déploiement. Aux
déploiements suivants, il peut être absent uniquement si une lecture directe de
la base confirme qu'un administrateur actif existe ; ce contrôle ne charge ni
n'affiche aucun secret de bootstrap.

## Autorisations associatives

Avant la première migration associative, définir `DEFAULT_DEPARTMENT_NAME` et
`DEFAULT_BRANCH_NAME` dans l'environnement partagé. Les valeurs de repli sont
`Département initial` et `Antenne initiale`. La migration crée cette organisation
une fois ; modifier ensuite ces variables ne renomme pas les enregistrements.
Les départements et antennes se gèrent depuis
**Administration → Départements et antennes**.
La désactivation conserve les données et interdit les nouvelles affectations.

Depuis **Administration → Affectations des membres**, sélectionner un membre, ses
appartenances aux antennes, puis ses rôles et dates d'effet. Une appartenance et
un rôle sont distincts : un rôle d'antenne doit avoir une appartenance dans cette
antenne. Plusieurs antennes et rôles peuvent être cumulés. Les responsabilités
opérationnelles et leurs adjoints acceptent une portée départementale ou locale ;
le président est départemental et l'administrateur technique est global.
Les autres rôles sont locaux. Les affectations futures ou terminées et les
comptes désactivés ne donnent aucun droit. Les retraits terminent les périodes
et préservent l'historique. Le serveur vérifie chaque périmètre soumis et protège
la continuité d'au moins un administrateur technique actif.

Les liens **Administration → Règles générales** et **Permissions : département**
donnent accès aux deux niveaux de la matrice :

- **Générale** : l'administrateur technique coche les permissions accordées à
  chaque rôle ; ces règles sont les valeurs héritées dans les départements.
- **Départementale** : le président autorisé ne gère que son département ;
  l'administrateur technique peut gérer tous les départements. Chaque cellule
  est **Héritée**, **Accordée** ou **Refusée**. Héritée supprime la surcharge et
  utilise la règle générale ; les deux autres états la remplacent pour ce rôle.

Seules les permissions délégables peuvent être surchargées. Les permissions
techniques, dont `technical.manage` et `permissions.manage_global`, restent
réservées au rôle global `technical-admin`. Les droits départementaux couvrent
uniquement les antennes de leur département ; un droit local ne couvre aucune
autre antenne. Les rôles se cumulent : un refus sur un rôle n'annule pas un
accord apporté par un autre rôle du même périmètre. Les mises à jour groupées
sont transactionnelles et les changements d'organisation, d'affectations et de
matrice produisent des événements d'audit sans secret.

Le catalogue initial contient 13 rôles et 63 permissions dans 12 groupes. Les
bénévoles reçoivent des consultations de base et les adjoints n'ont pas, par
défaut, de suppression, validation définitive ni gestion de matrice. Le seeder
`php artisan db:seed --class=AssociationAuthorizationSeeder --force --no-interaction`
peut être répété : il conserve les identifiants, refus existants et droits
personnalisés, tout en ajoutant les entrées manquantes. Il ne crée aucun compte
de démonstration. Éviter `migrate:fresh` et `db:wipe` sur une base opérationnelle.

## Migration associative et compatibilité

Conserver et valider le dump PostgreSQL **avant** toute migration. Le script de
déploiement utilise `pg_dump` puis `gzip -t` avant `php artisan migrate --force` ;
un échec de sauvegarde arrête la release. Conserver également le SHA complet de
la release précédente et la preuve d'un exercice de restauration sur une base
jetable. Répéter d'abord l'évolution depuis le schéma précédent dans un projet
Compose au nom unique, avec son volume dédié et aucune connexion à la base
opérationnelle. Après migration, exécuter le seeder deux fois et comparer les
comptages de rôles, permissions, groupes, droits et affectations.

La migration conserve `users.role` sans le modifier et convertit les comptes
historiques `role=admin` en affectations globales `technical-admin`. Le bootstrap
`protec:ensure-initial-admin` maintient aussi cette affectation. Vérifier après
migration qu'au moins un compte non désactivé possède cette affectation active
et que le nombre d'administrateurs correspond au nombre attendu (un dans
l'installation actuelle). Tester l'accès de cet administrateur à la matrice et
le refus 403 d'un bénévole. Ne pas supprimer `users.role` dans cette release.

Les nouvelles affectations ne réécrivent pas `users.role`. Un retour à l'ancien
code utilise donc les anciens droits de ce champ : vérifier explicitement les
comptes administrateurs historiques et ne pas supposer qu'une promotion ou
révocation associative est répercutée dans l'ancienne application.

## Retour arrière

Exécuter sur la VM `ops/deploy/rollback.sh <sha>`. Une restauration de base est
volontairement séparée et exige `--restore-database <archive.sql.gz>` ; elle
n'est appropriée que pour une migration incompatible et après validation de
l'archive. Avant toute intervention, le script mémorise la release désignée par
`current` et son tag d'image. Si la création ou le chargement de la base échoue,
il restaure la base si nécessaire puis relance précisément cette release active,
sans modifier `current`. Les releases en échec et les dumps ne sont jamais
supprimés.

Pour cette évolution additive, privilégier le retour à la release applicative
précédente avec son SHA complet en conservant les nouvelles tables et données.
Ne pas exécuter `php artisan migrate:rollback` comme retour arrière applicatif :
la migration de catalogue conserve ses données, mais les migrations de schéma
peuvent supprimer les tables et leur historique. La restauration du dump
prémigration est une opération distincte ; elle perd les écritures effectuées
depuis le dump et requiert une validation explicite. Après retour arrière,
contrôler `/up`, la connexion de l'administrateur historique, les conteneurs,
les tâches et le timer de sauvegarde, puis conserver les preuves et archives.

## Sauvegarde et restauration

Installer les unités de `ops/backup/` dans `/etc/systemd/system`, puis activer
`protec-gestion-backup.timer`. Il s'exécute chaque jour à 02:15 Europe/Paris,
valide gzip et conserve sept jours de dumps nommés strictement.

Exercice de restauration : créer une base PostgreSQL jetable, vérifier
`gzip -t`, injecter le dump avec `gzip -cd … | psql -v ON_ERROR_STOP=1`, pointer
temporairement Laravel vers cette base, exécuter `php artisan migrate:status`
et comparer la présence des tables requises (`users`, `sessions`, `jobs`,
`audit_events`). Supprimer uniquement cette base jetable à la fin.
L'exercice reproductible est
`BACKUP_DIR=/opt/protec-gestion/shared/backups ops/backup/restore-drill.sh /opt/protec-gestion/shared/backups/<dump.sql.gz>`.
Le script canonise les deux chemins et refuse toute archive hors de
`BACKUP_DIR`, y compris via un lien symbolique. Son trap supprime uniquement la
base jetable au nom unique
`protec_restore_drill_<YYYYMMDDHHMMSS>_<pid>` créée par cette exécution.

## Provisionnement VM

Exécuter d'abord `ops/provision/create-vm.sh --preflight-only` sur `pve1` et
conserver le rapport. Fournir explicitement IP/CIDR, passerelle, DNS, réseaux
LAN/administration/VPN et clé publique ; aucune plage n'est devinée. La DNS
interne `protec-gestion.cuperly` ne doit être créée qu'après démarrage, reboot
et contrôles complets de la VM. Paramètres attendus : VMID 115, 2 vCPU, 4096
Mio, disque 40 Gio sur `local-lvm`, Debian 13 et aucune exposition publique.
L'image generic-cloud doit être téléchargée depuis Debian avec son fichier
SHA512 publié, puis vérifiée avant l'exécution ; le provisionneur refuse un
cache dont le contrôle échoue. Le paquet Docker provient des dépôts Debian 13.
Les deux URL doivent rester sous `https://cloud.debian.org/images/cloud/trixie/`
et le fichier SHA512 doit contenir exactement une entrée pour le nom de l'image.
Le rapport de préflight doit inclure quorum/nœud, RAM, espace stockage, bridge,
VMID/nom, DHCP/ARP/ICMP/DNS, VPN, pare-feu et stockage de sauvegarde.
`DHCP_LEASE_FILE` doit pointer vers une preuve de baux lisible dans laquelle
l'adresse est absente. `DHCP_RESERVATION_FILE` doit pointer vers une seconde
preuve lisible montrant cette adresse réservée ou exclue de la plage dynamique.
Le préflight compare la passerelle par défaut réellement active à `GATEWAY`,
vérifie la route vers `DNS_SERVER`, puis effectue une requête A directement
auprès de ce résolveur avec `dig @DNS_SERVER`; une simple résolution via le
résolveur local ne suffit pas.

Le provisionnement se fait obligatoirement en deux commandes. La première,
`ops/provision/create-vm.sh`, crée et démarre la VM, attend cloud-init, capture
et trie les empreintes avec `ssh-keyscan`/`ssh-keygen`, arrête proprement la VM, imprime un
résumé JSON avec l'état `awaiting_fingerprint_approval` et conserve la VM. Après
vérification humaine de l'empreinte par un canal distinct, reprendre exactement
la VM existante avec
`ops/provision/create-vm.sh --verify-fingerprint '<ligne ssh-keygen -lf exacte>'`.
La reprise vérifie d'abord VMID, nom, tag de propriété et bridge, confirme que
l'empreinte approuvée figure dans l'ensemble observé avant toute connexion SSH,
puis exécute les validations invité et
le reboot. Après ce reboot, le statut actif de
`protec-docker-firewall.service` et les quatre règles effectives de
`DOCKER-USER` sont revérifiés : connexions établies, accès au port conteneur 80
depuis le LAN, accès depuis le VPN, puis refus de toute autre source. Une
validation par position garantit que ces règles précèdent le `RETURN` terminal
installé par Docker. Une empreinte différente ou une règle absente arrête la
reprise sans supprimer la VM.
`StrictHostKeyChecking=accept-new` n'est jamais utilisé.
