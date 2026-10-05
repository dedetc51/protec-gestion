#!/usr/bin/env bash
set -Eeuo pipefail
usage() { printf 'Usage: %s <40-hex-revision> [--restore-database <verified.sql.gz>]\n' "$0" >&2; exit 2; }
[[ ${1:-} =~ ^[0-9a-f]{40}$ ]] || { printf 'revision must be a lowercase 40-hex commit\n' >&2; usage; }
revision=$1; shift; root=${PROTEC_REMOTE_ROOT:-/opt/protec-gestion}
[[ $root =~ ^/opt/[A-Za-z0-9._/-]+$ && $root != *..* ]] || { printf 'Unsafe remote root\n' >&2; exit 2; }
release="$root/releases/$revision"; shared_env="$root/shared/.env"; dump=''; export COMPOSE_PROJECT_NAME=protec-gestion APP_IMAGE_TAG="$revision"
if (($#)); then [[ ${1:-} == --restore-database && -n ${2:-} && $# -eq 2 ]] || usage; dump=$2; [[ $dump =~ ^/opt/[A-Za-z0-9._/-]+/shared/backups/protec-gestion-[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] || { printf 'Refusing unrecognized dump path\n' >&2; exit 1; }; gzip -t "$dump"; fi
[[ -d $release && -f $release/compose.yaml && -f $shared_env ]] || { printf 'Release or environment does not exist\n' >&2; exit 1; }
ln -sfn "$shared_env" "$release/.env"; cd "$release"
if [[ -n $dump ]]; then
  db_user=$(sed -n 's/^DB_USERNAME=//p' .env); db_name=$(sed -n 's/^DB_DATABASE=//p' .env)
  [[ $db_user =~ ^[A-Za-z_][A-Za-z0-9_]*$ && $db_name =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { printf 'Unsafe database identifiers\n' >&2; exit 1; }
  drill="${db_name}_restore_$$"; docker compose stop app worker scheduler; docker compose exec -T postgres createdb -U "$db_user" "$drill"
  if ! gzip -cd "$dump" | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" "$drill"; then docker compose exec -T postgres dropdb -U "$db_user" --if-exists "$drill"; exit 1; fi
  docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db_name' AND pid <> pg_backend_pid();
ALTER DATABASE "$db_name" RENAME TO "${db_name}_failed_$(date +%Y%m%d%H%M%S)";
ALTER DATABASE "$drill" RENAME TO "$db_name";
SQL
fi
docker compose up -d --remove-orphans; bind=$(sed -n 's/^HTTP_BIND_IP=//p' .env); bind=${bind:-127.0.0.1}; port=$(sed -n 's/^HTTP_PORT=//p' .env); port=${port:-8080}
for _ in {1..30}; do curl --fail --silent "http://$bind:$port/up" >/dev/null && ready=1 && break; sleep 2; done
[[ ${ready:-0} == 1 ]] || { printf 'Rollback health check failed; current link unchanged\n' >&2; exit 1; }
ln -sfn "$release" "$root/current.next"; mv -Tf "$root/current.next" "$root/current"; printf 'Rolled back to %s\n' "$revision"
