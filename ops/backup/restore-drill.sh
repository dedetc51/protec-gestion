#!/usr/bin/env bash
set -Eeuo pipefail
export COMPOSE_PROJECT_NAME=protec-gestion
dump=${1:-}; env_file=${BACKUP_ENV_FILE:-/opt/protec-gestion/shared/.env}
[[ $dump =~ /protec-gestion-[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] || { printf 'Usage: %s <verified dump>\n' "$0" >&2; exit 2; }
gzip -t "$dump"; db_user=$(sed -n 's/^DB_USERNAME=//p' "$env_file"); db_name="protec_restore_drill_$(date +%Y%m%d%H%M%S)_$$"
[[ $db_user =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || exit 2
owned=0
cleanup() { ((owned)) && docker compose exec -T postgres dropdb -U "$db_user" "$db_name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker compose exec -T postgres createdb -U "$db_user" "$db_name"; owned=1
gzip -cd "$dump" | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" "$db_name"
docker compose run --rm -e DB_DATABASE="$db_name" app php artisan migrate:status --no-interaction >/dev/null
for table in users sessions jobs audit_events; do docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name" -tAc "SELECT to_regclass('public.$table') IS NOT NULL" | grep -qx t; done
printf 'Restore drill passed for %s\n' "$dump"
