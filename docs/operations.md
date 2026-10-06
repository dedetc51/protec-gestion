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
Les secrets du premier administrateur ne vont jamais dans `.env`. Les placer
ensemble dans `/opt/protec-gestion/shared/initial-admin.env`, mode `0600` ; le
déploiement les injecte uniquement dans la commande bootstrap puis supprime ce
fichier après réussite.

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
l'empreinte avec `ssh-keyscan`/`ssh-keygen`, arrête proprement la VM, imprime un
résumé JSON avec l'état `awaiting_fingerprint_approval` et conserve la VM. Après
vérification humaine de l'empreinte par un canal distinct, reprendre exactement
la VM existante avec
`ops/provision/create-vm.sh --verify-fingerprint '<ligne ssh-keygen -lf exacte>'`.
La reprise vérifie d'abord VMID, nom, tag de propriété et bridge, compare
l'empreinte avant toute connexion SSH, puis exécute les validations invité et
le reboot. Après ce reboot, le statut actif de
`protec-docker-firewall.service` et les quatre règles effectives de
`DOCKER-USER` sont revérifiés : connexions établies, accès au port conteneur 80
depuis le LAN, accès depuis le VPN, puis refus de toute autre source. Une
empreinte différente ou une règle absente arrête la reprise sans supprimer la VM.
`StrictHostKeyChecking=accept-new` n'est jamais utilisé.
