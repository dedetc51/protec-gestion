#!/usr/bin/env bash
set -Eeuo pipefail
revision=${PROTEC_GIT_REVISION:-}; target=${PROTEC_SSH_TARGET:-}; remote_root=${PROTEC_REMOTE_ROOT:-/opt/protec-gestion}
die() { printf 'deploy: %s\n' "$*" >&2; exit 1; }
[[ $revision =~ ^[0-9a-f]{40}$ ]] || die 'PROTEC_GIT_REVISION must be a lowercase 40-hex commit'
[[ $target =~ ^[A-Za-z0-9_.@:-]+$ ]] || die 'PROTEC_SSH_TARGET is required and must not contain shell metacharacters'
[[ $remote_root =~ ^/opt/[A-Za-z0-9._/-]+$ && $remote_root != *..* ]] || die 'PROTEC_REMOTE_ROOT must be a safe absolute /opt path'
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -- "$target" true 2>/dev/null || die 'SSH host key is unknown or the target is unavailable'
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -- "$target" bash -s -- "$revision" "$remote_root" <<'REMOTE'
set -Eeuo pipefail
revision=$1; root=$2
[[ $revision =~ ^[0-9a-f]{40}$ && $root =~ ^/opt/[A-Za-z0-9._/-]+$ && $root != *..* ]] || exit 2
export COMPOSE_PROJECT_NAME=protec-gestion APP_IMAGE_TAG="$revision"
repo=${PROTEC_GIT_URL:-https://github.com/dedetc51/protec-gestion.git}; release="$root/releases/$revision"; shared_env="$root/shared/.env"
previous=''; dump='none'; maintenance=0
health() { local bind port; bind=$(sed -n 's/^HTTP_BIND_IP=//p' "$1"); bind=${bind:-127.0.0.1}; port=$(sed -n 's/^HTTP_PORT=//p' "$1"); port=${port:-8080}; curl --fail --silent --show-error "http://$bind:$port/up" >/dev/null; }
recover() {
  rc=$?; trap - ERR
  printf 'Deployment failed; retained release: %s; retained dump: %s\n' "$release" "$dump" >&2
  if [[ -n $previous && -d $previous ]]; then
    export APP_IMAGE_TAG=${previous##*/}; cd "$previous"; docker compose up -d --remove-orphans || true
    ((maintenance)) && docker compose exec -T app php artisan up || true
    health "$shared_env" || printf 'WARNING: previous release HTTP verification failed\n' >&2
    printf 'Rollback: %s/ops/deploy/rollback.sh %s\n' "$previous" "${previous##*/}" >&2
  fi
  exit "$rc"
}
trap recover ERR
[[ -f $shared_env && ! -L $shared_env ]] || { printf 'Missing regular shared .env\n' >&2; false; }
mode=$(stat -c '%a' "$shared_env"); [[ $mode == 600 ]] || { printf 'shared .env must have mode 0600\n' >&2; false; }
for variable in APP_KEY DB_DATABASE DB_USERNAME DB_PASSWORD; do grep -Eq "^${variable}=.+" "$shared_env" || { printf 'Missing required variable %s\n' "$variable" >&2; false; }; done
admin_email=$(sed -n 's/^INITIAL_ADMIN_EMAIL=//p' "$shared_env"); admin_password=$(sed -n 's/^INITIAL_ADMIN_PASSWORD=//p' "$shared_env"); admin_name=$(sed -n 's/^INITIAL_ADMIN_NAME=//p' "$shared_env")
if [[ -n $admin_email || -n $admin_password || -n $admin_name ]]; then [[ -n $admin_email && -n $admin_password && -n $admin_name ]] || { printf 'Initial admin name, email and password are all required when creating it\n' >&2; false; }; fi
[[ ! -e $root/current || -L $root/current ]] || { printf 'current must be a symlink\n' >&2; false; }
[[ -L $root/current ]] && previous=$(readlink -f "$root/current")
mkdir -p "$root/releases" "$root/shared/backups"
if [[ ! -d $release/.git ]]; then git init -q "$release"; git -C "$release" remote add origin "$repo"; git -C "$release" fetch -q --depth=1 origin "$revision"; git -C "$release" checkout -q --detach FETCH_HEAD; fi
[[ $(git -C "$release" rev-parse HEAD) == "$revision" && -z $(git -C "$release" status --porcelain) ]] || { printf 'Dirty or mismatched checkout\n' >&2; false; }
ln -sfn "$shared_env" "$release/.env"; cd "$release"; docker compose config --quiet; docker compose build
if [[ -n $previous ]]; then dump=$(BACKUP_ENV_FILE="$shared_env" "$release/ops/backup/postgres-backup.sh"); docker compose exec -T app php artisan down; maintenance=1; fi
docker compose run --rm app php artisan migrate --force; docker compose up -d --remove-orphans
for _ in {1..30}; do db_user=$(sed -n 's/^DB_USERNAME=//p' .env); docker compose exec -T postgres pg_isready -U "$db_user" >/dev/null 2>&1 && health .env && ready=1 && break; sleep 2; done
[[ ${ready:-0} == 1 ]] || { printf 'Health check failed\n' >&2; false; }
if [[ -n $admin_email ]]; then set +x; docker compose exec -T app php artisan protec:ensure-initial-admin; fi
docker compose exec -T app php artisan up; maintenance=0; health .env
ln -sfn "$release" "$root/current.next"; mv -Tf "$root/current.next" "$root/current"; printf '%s %s\n' "$revision" "$(date --iso-8601=seconds)" >"$root/DEPLOYED"
trap - ERR; printf 'Deployed %s\n' "$revision"
REMOTE
