#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export COMPOSE_PROJECT_NAME=protec-gestion
BACKUP_DIR=${BACKUP_DIR:-/opt/protec-gestion/shared/backups}; RETENTION=${BACKUP_RETENTION_DAYS:-7}; STAMP=${BACKUP_TIMESTAMP:-$(date +%Y%m%d-%H%M%S)}
ENV_FILE=${BACKUP_ENV_FILE:-/opt/protec-gestion/shared/.env}
[[ $STAMP =~ ^[0-9]{8}-[0-9]{6}$ && $RETENTION =~ ^[1-9][0-9]*$ ]] || { printf 'Invalid timestamp or retention\n' >&2; exit 2; }
if [[ -f $ENV_FILE ]]; then
  [[ $(stat -c '%a' "$ENV_FILE") == 600 ]] || { printf 'Backup environment must have mode 0600\n' >&2; exit 2; }
  db_user=$(sed -n 's/^DB_USERNAME=//p' "$ENV_FILE"); db_name=$(sed -n 's/^DB_DATABASE=//p' "$ENV_FILE")
else db_user=${DB_USERNAME:-protec_gestion}; db_name=${DB_DATABASE:-protec_gestion}; fi
[[ $db_user =~ ^[A-Za-z_][A-Za-z0-9_]*$ && $db_name =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { printf 'Invalid database settings\n' >&2; exit 2; }
mkdir -p "$BACKUP_DIR"; final="$BACKUP_DIR/protec-gestion-$STAMP.sql.gz"; partial="$final.partial"; trap 'rm -f "$partial"' EXIT
docker compose exec -T postgres pg_dump --no-owner --no-privileges -U "$db_user" "$db_name" | gzip -c >"$partial"
gzip -t "$partial"; [[ -s $partial && $(gzip -cd "$partial" | wc -c) -gt 0 ]] || { printf 'Backup SQL is empty\n' >&2; exit 1; }
chmod 0600 "$partial"; mv "$partial" "$final"
cutoff=$(date -d "$RETENTION days ago" +%Y%m%d 2>/dev/null || true)
if [[ $cutoff =~ ^[0-9]{8}$ ]]; then
  shopt -s nullglob
  for candidate in "$BACKUP_DIR"/protec-gestion-????????-??????.sql.gz; do
    name=${candidate##*/}
    [[ $name =~ ^protec-gestion-([0-9]{8})-([0-9]{6})\.sql\.gz$ ]] || continue
    [[ ${BASH_REMATCH[1]} < $cutoff ]] && rm -f -- "$candidate"
  done
fi
trap - EXIT; printf '%s\n' "$final"
