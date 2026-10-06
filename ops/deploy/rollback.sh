#!/usr/bin/env bash
set -Eeuo pipefail
usage() { printf 'Usage: %s <40-hex-revision> [--restore-database <verified.sql.gz>]\n' "$0" >&2; exit 2; }
[[ ${1:-} =~ ^[0-9a-f]{40}$ ]] || { printf 'revision must be a lowercase 40-hex commit\n' >&2; usage; }
revision=$1; shift; root=${PROTEC_REMOTE_ROOT:-/opt/protec-gestion}
if [[ $root =~ ^/opt/[A-Za-z0-9._/-]+$ && $root != *..* ]]; then :
elif [[ ${PROTEC_TESTING:-0} == 1 && $root =~ ^/(private/)?(tmp|var/folders)/[A-Za-z0-9._/-]+$ && $root != *..* ]]; then :
else printf 'Unsafe remote root\n' >&2; exit 2
fi
release="$root/releases/$revision"; shared_env="$root/shared/.env"; dump=''; export COMPOSE_PROJECT_NAME=protec-gestion APP_IMAGE_TAG="$revision"
if (($#)); then
  [[ ${1:-} == --restore-database && -n ${2:-} && $# -eq 2 ]] || usage
  backup_dir=$(realpath "$root/shared/backups"); dump=$(realpath "$2")
  [[ $dump == "$backup_dir"/protec-gestion-????????-??????.sql.gz && ${dump##*/} =~ ^protec-gestion-[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] || { printf 'Refusing dump outside canonical backup directory\n' >&2; exit 1; }
  gzip -t "$dump"
fi
[[ -d $release && -f $release/compose.yaml && -f $shared_env ]] || { printf 'Release or environment does not exist\n' >&2; exit 1; }
ln -sfn "$shared_env" "$release/.env"; cd "$release"
if [[ -n $dump ]]; then
  db_user=$(sed -n 's/^DB_USERNAME=//p' .env); db_name=$(sed -n 's/^DB_DATABASE=//p' .env)
  [[ $db_user =~ ^[A-Za-z_][A-Za-z0-9_]*$ && $db_name =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { printf 'Unsafe database identifiers\n' >&2; exit 1; }
  drill="${db_name}_restore_$$"; old="${db_name}_previous_$(date +%Y%m%d%H%M%S)"; failed="${db_name}_failed_$(date +%Y%m%d%H%M%S)"; restore_phase=preparing
  restore_old_database() {
    rc=$?; trap - ERR
    docker compose stop app worker scheduler >/dev/null 2>&1 || true
    case $restore_phase in
      production_rename_started|production_renamed)
        docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL || true
ALTER DATABASE "$old" RENAME TO "$db_name";
SQL
        ;;
      replacement_rename_started|replacement_active)
        docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL || true
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db_name' AND pid <> pg_backend_pid();
ALTER DATABASE "$db_name" RENAME TO "$failed";
SQL
        docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL || true
ALTER DATABASE "$old" RENAME TO "$db_name";
SQL
        ;;
      preparing|drill_created|drill_loaded)
        docker compose exec -T postgres dropdb -U "$db_user" --if-exists "$drill" >/dev/null 2>&1 || true
        ;;
    esac
    docker compose up -d --remove-orphans >/dev/null 2>&1 || true
    exit "$rc"
  }
  trap restore_old_database ERR
  docker compose stop app worker scheduler
  docker compose exec -T postgres createdb -U "$db_user" "$drill"
  restore_phase=drill_created
  gzip -cd "$dump" | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" "$drill"
  restore_phase=drill_loaded
  docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db_name' AND pid <> pg_backend_pid();
SQL
  restore_phase=production_rename_started
  docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL
ALTER DATABASE "$db_name" RENAME TO "$old";
SQL
  restore_phase=production_renamed
  restore_phase=replacement_rename_started
  docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$db_user" postgres <<SQL
ALTER DATABASE "$drill" RENAME TO "$db_name";
SQL
  restore_phase=replacement_active
fi
docker compose up -d --remove-orphans; bind=$(sed -n 's/^HTTP_BIND_IP=//p' .env); bind=${bind:-127.0.0.1}; port=$(sed -n 's/^HTTP_PORT=//p' .env); port=${port:-8080}
for _ in {1..30}; do curl --fail --silent "http://$bind:$port/up" >/dev/null && ready=1 && break; sleep 2; done
if [[ ${ready:-0} != 1 ]]; then
  printf 'Rollback health check failed; current link unchanged\n' >&2
  if [[ -n $dump ]]; then false; else exit 1; fi
fi
trap - ERR
ln -sfn "$release" "$root/current.next"; mv -Tf "$root/current.next" "$root/current"; printf 'Rolled back to %s\n' "$revision"
