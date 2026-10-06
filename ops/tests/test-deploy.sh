#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
DEPLOY="$ROOT/ops/deploy/deploy.sh"
ROLLBACK="$ROOT/ops/deploy/rollback.sh"
TMP=$(mktemp -d)
DEPLOY_ROOT="$TMP/protec-deploy-test"
ROLLBACK_ROOT="$TMP/protec-rollback-test"
trap 'rm -rf "$TMP" "$DEPLOY_ROOT" "$ROLLBACK_ROOT"' EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_fails() { if "$@" >"$TMP/out" 2>&1; then fail "command unexpectedly succeeded: $*"; fi; }
assert_contains() { grep -Fq -- "$1" "$2" || { sed -n '1,160p' "$2" >&2; fail "missing '$1' in $2"; }; }

assert_fails env PROTEC_GIT_REVISION=main PROTEC_SSH_TARGET=host "$DEPLOY"
assert_contains '40-hex' "$TMP/out"
assert_fails "$ROLLBACK" not-a-sha
assert_contains '40-hex' "$TMP/out"

mkdir -p "$TMP/deploy-bin"
cat >"$TMP/deploy-bin/ssh" <<'EOF'
#!/usr/bin/env bash
if [[ ${*: -1} == true ]]; then exit 0; fi
revision=${@: -2:1}; root=${@: -1}
exec bash -s -- "$revision" "$root"
EOF
cat >"$TMP/deploy-bin/git" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *'rev-parse HEAD'*) printf '%s\n' "${STUB_GIT_HEAD:-$PROTEC_GIT_REVISION}";;
  *'status --porcelain'*) [[ ${STUB_GIT_DIRTY:-0} == 1 ]] && printf ' M compose.yaml\n';;
  *) exit 0;;
esac
EOF
cat >"$TMP/deploy-bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$STUB_DOCKER_LOG"
case "$*" in
  *'artisan migrate --force'*) [[ ${STUB_DEPLOY_FAIL:-} == migration ]] && exit 31;;
  *'pg_isready'*) exit 0;;
esac
exit 0
EOF
cat >"$TMP/deploy-bin/curl" <<'EOF'
#!/usr/bin/env bash
[[ ${STUB_DEPLOY_FAIL:-} == health ]] && exit 22
exit 0
EOF
cat >"$TMP/deploy-bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF
cat >"$TMP/deploy-bin/stat" <<'EOF'
#!/bin/sh
printf '600\n'
EOF
chmod +x "$TMP/deploy-bin"/*

revision=0123456789abcdef0123456789abcdef01234567
previous=89abcdef0123456789abcdef0123456789abcdef
setup_deploy_case() {
  rm -rf "$DEPLOY_ROOT"
  mkdir -p "$DEPLOY_ROOT/shared/backups" "$DEPLOY_ROOT/releases/$revision/.git" "$DEPLOY_ROOT/releases/$revision/ops/backup" "$DEPLOY_ROOT/releases/$previous"
  printf 'services: {}\n' >"$DEPLOY_ROOT/releases/$revision/compose.yaml"
  printf 'services: {}\n' >"$DEPLOY_ROOT/releases/$previous/compose.yaml"
  cat >"$DEPLOY_ROOT/shared/.env" <<'EOF'
APP_KEY=base64:test
DB_DATABASE=protec_gestion
DB_USERNAME=protec_gestion
DB_PASSWORD=test-only
HTTP_BIND_IP=127.0.0.1
HTTP_PORT=8080
EOF
  chmod 600 "$DEPLOY_ROOT/shared/.env"
  ln -s "$DEPLOY_ROOT/releases/$previous" "$DEPLOY_ROOT/current"
  cat >"$DEPLOY_ROOT/releases/$revision/ops/backup/postgres-backup.sh" <<'EOF'
#!/usr/bin/env bash
printf 'backup\n' >>"$STUB_STAGE_LOG"
[[ ${STUB_DEPLOY_FAIL:-} == dump ]] && exit 30
dump="$PROTEC_REMOTE_ROOT/shared/backups/protec-gestion-20261006-120000.sql.gz"
printf 'dump\n' | gzip -c >"$dump"
printf '%s\n' "$dump"
EOF
  chmod +x "$DEPLOY_ROOT/releases/$revision/ops/backup/postgres-backup.sh"
  : >"$TMP/docker.log"; : >"$TMP/stage.log"
}

run_failed_deploy() {
  local stage=$1
  assert_fails env PATH="$TMP/deploy-bin:$PATH" PROTEC_TESTING=1 PROTEC_GIT_REVISION="$revision" PROTEC_SSH_TARGET=host PROTEC_REMOTE_ROOT="$DEPLOY_ROOT" STUB_DEPLOY_FAIL="$stage" STUB_DOCKER_LOG="$TMP/docker.log" STUB_STAGE_LOG="$TMP/stage.log" "$DEPLOY"
  [[ $(readlink "$DEPLOY_ROOT/current") == "$DEPLOY_ROOT/releases/$previous" ]] || fail "current switched during $stage failure"
  assert_contains 'Rollback:' "$TMP/out"
}

run_gate_failure() {
  local expected=$1; shift
  : >"$TMP/docker.log"
  assert_fails env PATH="$TMP/deploy-bin:$PATH" PROTEC_TESTING=1 PROTEC_GIT_REVISION="$revision" PROTEC_SSH_TARGET=host PROTEC_REMOTE_ROOT="$DEPLOY_ROOT" STUB_DOCKER_LOG="$TMP/docker.log" STUB_STAGE_LOG="$TMP/stage.log" "$@" "$DEPLOY"
  assert_contains "$expected" "$TMP/out"
  [[ $(readlink "$DEPLOY_ROOT/current") == "$DEPLOY_ROOT/releases/$previous" ]] || fail "current switched after gate failure: $expected"
  ! grep -Fq 'artisan migrate --force' "$TMP/docker.log" || fail "migration ran after gate failure: $expected"
}

setup_deploy_case
rm -f "$DEPLOY_ROOT/shared/.env"
run_gate_failure 'Missing regular shared .env'

setup_deploy_case
sed -i.bak '/^DB_PASSWORD=/d' "$DEPLOY_ROOT/shared/.env"; rm -f "$DEPLOY_ROOT/shared/.env.bak"
run_gate_failure 'Missing required variable DB_PASSWORD'

setup_deploy_case
run_gate_failure 'Dirty or mismatched checkout' STUB_GIT_DIRTY=1

setup_deploy_case
run_gate_failure 'Dirty or mismatched checkout' STUB_GIT_HEAD=ffffffffffffffffffffffffffffffffffffffff

setup_deploy_case
run_failed_deploy dump
assert_contains backup "$TMP/stage.log"
! grep -Fq 'artisan migrate --force' "$TMP/docker.log" || fail 'migration ran after dump failure'

setup_deploy_case
run_failed_deploy migration
test -s "$DEPLOY_ROOT/shared/backups/protec-gestion-20261006-120000.sql.gz" || fail 'pre-migration dump was not retained'
assert_contains 'artisan migrate --force' "$TMP/docker.log"

setup_deploy_case
run_failed_deploy health
assert_contains 'artisan migrate --force' "$TMP/docker.log"
assert_contains 'up -d --remove-orphans' "$TMP/docker.log"

# Exercise database rollback phase recovery with a Docker stub that records SQL.
mkdir -p "$TMP/rollback-bin" "$ROLLBACK_ROOT/shared/backups" "$ROLLBACK_ROOT/releases/$revision" "$ROLLBACK_ROOT/releases/$previous"
printf 'services: {}\n' >"$ROLLBACK_ROOT/releases/$revision/compose.yaml"
printf 'services: {}\n' >"$ROLLBACK_ROOT/releases/$previous/compose.yaml"
printf 'DB_USERNAME=protec_gestion\nDB_DATABASE=protec_gestion\nHTTP_PORT=8080\n' >"$ROLLBACK_ROOT/shared/.env"
chmod 600 "$ROLLBACK_ROOT/shared/.env"
ln -s "$ROLLBACK_ROOT/releases/$previous" "$ROLLBACK_ROOT/current"
printf 'CREATE TABLE users(id bigint);\n' | gzip -c >"$ROLLBACK_ROOT/shared/backups/protec-gestion-20261006-120000.sql.gz"
cat >"$TMP/rollback-bin/docker" <<'EOF'
#!/usr/bin/env bash
printf 'CMD %s\n' "$*" >>"$STUB_ROLLBACK_LOG"
if [[ $* == *' psql '*postgres* ]]; then
  sql=$(cat)
  printf 'SQL %s\n' "$sql" >>"$STUB_ROLLBACK_LOG"
  if [[ ${STUB_ROLLBACK_FAIL:-} == rename && $sql == *'ALTER DATABASE "protec_gestion" RENAME TO "protec_gestion_previous_'* ]]; then exit 40; fi
  if [[ ${STUB_ROLLBACK_FAIL:-} == swap && $sql == *'ALTER DATABASE "protec_gestion_restore_'*' RENAME TO "protec_gestion"'* ]]; then exit 41; fi
fi
exit 0
EOF
cat >"$TMP/rollback-bin/curl" <<'EOF'
#!/usr/bin/env bash
[[ ${STUB_ROLLBACK_FAIL:-} == health ]] && exit 22
exit 0
EOF
cat >"$TMP/rollback-bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF
chmod +x "$TMP/rollback-bin"/*

run_failed_rollback() {
  local stage=$1
  : >"$TMP/rollback.log"
  assert_fails env PATH="$TMP/rollback-bin:$PATH" PROTEC_TESTING=1 PROTEC_REMOTE_ROOT="$ROLLBACK_ROOT" STUB_ROLLBACK_FAIL="$stage" STUB_ROLLBACK_LOG="$TMP/rollback.log" "$ROLLBACK" "$revision" --restore-database "$ROLLBACK_ROOT/shared/backups/protec-gestion-20261006-120000.sql.gz"
  [[ $(readlink "$ROLLBACK_ROOT/current") == "$ROLLBACK_ROOT/releases/$previous" ]] || fail "current switched during rollback $stage failure"
}

run_failed_rollback swap
assert_contains 'ALTER DATABASE "protec_gestion" RENAME TO "protec_gestion_previous_' "$TMP/rollback.log"
assert_contains 'ALTER DATABASE "protec_gestion_previous_' "$TMP/rollback.log"
assert_contains 'RENAME TO "protec_gestion"' "$TMP/rollback.log"

run_failed_rollback rename
assert_contains 'ALTER DATABASE "protec_gestion_previous_' "$TMP/rollback.log"
assert_contains 'RENAME TO "protec_gestion"' "$TMP/rollback.log"

run_failed_rollback health
assert_contains 'ALTER DATABASE "protec_gestion" RENAME TO "protec_gestion_failed_' "$TMP/rollback.log"
assert_contains 'ALTER DATABASE "protec_gestion_previous_' "$TMP/rollback.log"

printf 'test-deploy: ok\n'
