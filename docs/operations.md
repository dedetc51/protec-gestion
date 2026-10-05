# Exploitation de Protec-Gestion

## Déploiement

Depuis un poste autorisé sur le LAN ou le VPN, exporter `PROTEC_SSH_TARGET` et
`PROTEC_GIT_REVISION` (SHA Git complet de 40 caractères), puis exécuter
`ops/deploy/deploy.sh`. La cible doit déjà contenir le fichier secret, mode
`0600`, `/opt/protec-gestion/shared/.env`. Le script conserve chaque release,
effectue une sauvegarde avant migration et ne bascule le lien `current`
qu'après migrations et contrôles de santé.
La production doit définir `HTTP_BIND_IP` sur l'adresse privée de la VM et
`HTTP_PORT=8080`; ce même port est injecté dans le pare-feu invité. Chaque
release exporte `APP_IMAGE_TAG` avec son SHA exact, y compris lors d'un rollback.
Après la création initiale, les trois variables `INITIAL_ADMIN_*` peuvent être
retirées. Si l'une est présente, nom, adresse et mot de passe sont tous requis.

## Retour arrière

Exécuter sur la VM `ops/deploy/rollback.sh <sha>`. Une restauration de base est
volontairement séparée et exige `--restore-database <archive.sql.gz>` ; elle
n'est appropriée que pour une migration incompatible et après validation de
l'archive. Les releases en échec et les dumps ne sont jamais supprimés.

## Sauvegarde et restauration

Installer les unités de `ops/backup/` dans `/etc/systemd/system`, puis activer
`protec-gestion-backup.timer`. Il s'exécute chaque jour à 02:15 Europe/Paris,
valide gzip et conserve sept jours de dumps nommés strictement.

Exercice de restauration : créer une base PostgreSQL jetable, vérifier
`gzip -t`, injecter le dump avec `gzip -cd … | psql -v ON_ERROR_STOP=1`, pointer
temporairement Laravel vers cette base, exécuter `php artisan migrate:status`
et comparer la présence des tables requises (`users`, `sessions`, `jobs`,
`audit_events`). Supprimer uniquement cette base jetable à la fin.
L'exercice reproductible est `ops/backup/restore-drill.sh <dump.sql.gz>`; son
trap supprime uniquement la base `protec_restore_drill`.

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
