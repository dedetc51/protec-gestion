#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
DEPLOY="$ROOT/ops/deploy/deploy.sh"
ROLLBACK="$ROOT/ops/deploy/rollback.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_fails() { if "$@" >"$TMP/out" 2>&1; then fail "command unexpectedly succeeded: $*"; fi; }
assert_contains() { grep -Fq "$1" "$2" || fail "missing '$1' in $2"; }

assert_fails env PROTEC_GIT_REVISION=main PROTEC_SSH_TARGET=host "$DEPLOY"
assert_contains "40-hex" "$TMP/out"

mkdir -p "$TMP/bin"
cat >"$TMP/bin/ssh" <<'EOF'
#!/usr/bin/env bash
exit 255
EOF
chmod +x "$TMP/bin/ssh"
assert_fails env PATH="$TMP/bin:$PATH" PROTEC_GIT_REVISION=0123456789abcdef0123456789abcdef01234567 PROTEC_SSH_TARGET=host "$DEPLOY"
assert_contains "SSH host key" "$TMP/out"

assert_fails env PROTEC_GIT_REVISION=0123456789abcdef0123456789abcdef01234567 PROTEC_SSH_TARGET='host;touch /tmp/pwned' "$DEPLOY"
assert_contains "metacharacters" "$TMP/out"
assert_fails env PROTEC_GIT_REVISION=0123456789abcdef0123456789abcdef01234567 PROTEC_SSH_TARGET=host PROTEC_REMOTE_ROOT='/opt/../etc' "$DEPLOY"
assert_contains "safe absolute" "$TMP/out"

assert_fails "$ROLLBACK" not-a-sha
assert_contains "40-hex" "$TMP/out"

grep -q 'mv -T' "$DEPLOY" || fail "deployment must switch current atomically"
grep -q 'backup' "$DEPLOY" || fail "deployment must create a backup"
grep -q 'rollback.sh' "$DEPLOY" || fail "failure output must give rollback invocation"
grep -q -- '--restore-database' "$ROLLBACK" || fail "database restore must be explicit"
grep -q 'protec:ensure-initial-admin' "$DEPLOY" || fail "wrong initial-admin command"
! grep -q 'app:ensure-initial-admin' "$DEPLOY" || fail "obsolete initial-admin command retained"
grep -q 'APP_IMAGE_TAG' "$DEPLOY" || fail "release image tag is not pinned"
grep -q 'APP_IMAGE_TAG' "$ROLLBACK" || fail "rollback image tag is not pinned"
grep -q 'stat.*0600\|stat.*600' "$DEPLOY" || fail "shared env permissions are not checked"
grep -q 'docker compose stop app worker scheduler' "$ROLLBACK" || fail "database producers are not stopped"
grep -q 'ALTER DATABASE' "$ROLLBACK" || fail "database is not replaced atomically"
grep -q 'curl.*up' "$DEPLOY" || fail "post-maintenance HTTP is not verified"
grep -q 'INITIAL_ADMIN_NAME' "$DEPLOY" || fail "initial admin name contract is missing"
grep -q 'docker compose up -d --remove-orphans' "$DEPLOY" || fail "failed deployment cannot restore containers"
grep -q 'restore_old_database' "$ROLLBACK" || fail "failed rollback does not restore the prior database"
grep -q 'realpath -e' "$ROLLBACK" || fail "dump path is not canonicalized"
grep -q 'initial-admin.env' "$DEPLOY" || fail "bootstrap secrets are not separated"
printf 'test-deploy: ok\n'
