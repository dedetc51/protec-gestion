#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SCRIPT="$ROOT/ops/backup/postgres-backup.sh"
DRILL="$ROOT/ops/backup/restore-drill.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/backups"

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

cat >"$TMP/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' 'CREATE TABLE users (id bigint);'
EOF
chmod +x "$TMP/bin/docker"
PATH="$TMP/bin:$PATH" BACKUP_DIR="$TMP/backups" BACKUP_TIMESTAMP=20261005-021500 "$SCRIPT"
ARCHIVE="$TMP/backups/protec-gestion-20261005-021500.sql.gz"
test -s "$ARCHIVE" || fail "archive missing or empty"
gzip -t "$ARCHIVE" || fail "invalid gzip archive"
if mode=$(stat -c '%a' "$ARCHIVE" 2>/dev/null); then
  :
else
  mode=$(stat -f '%Lp' "$ARCHIVE")
fi
test "$mode" = 600 || fail "archive mode is not 0600"
printf keep >"$TMP/backups/unrelated.txt"
touch -t 202609010000 "$TMP/backups/unrelated.txt"
PATH="$TMP/bin:$PATH" BACKUP_DIR="$TMP/backups" BACKUP_TIMESTAMP=20261006-021500 BACKUP_RETENTION_DAYS=7 "$SCRIPT"
test -f "$TMP/backups/unrelated.txt" || fail "unrelated file was pruned"

cat >"$TMP/bin/docker" <<'EOF'
#!/usr/bin/env bash
exit 42
EOF
if PATH="$TMP/bin:$PATH" BACKUP_DIR="$TMP/backups" BACKUP_TIMESTAMP=20261007-021500 "$SCRIPT" >/dev/null 2>&1; then
  fail "pg_dump failure was ignored"
fi
test ! -e "$TMP/backups/protec-gestion-20261007-021500.sql.gz" || fail "failed archive was published"
grep -q 'COMPOSE_PROJECT_NAME=protec-gestion' "$SCRIPT" || fail "backup compose project is not fixed"
grep -q 'DB_USERNAME' "$SCRIPT" || fail "backup does not read database user"
test -x "$DRILL" || fail "restore drill is not executable"

# Restore drills accept only a canonical regular dump below BACKUP_DIR.
printf 'outside\n' | gzip -c >"$TMP/protec-gestion-20261006-021500.sql.gz"
printf 'DB_USERNAME=protec_gestion\nDB_DATABASE=protec_gestion\n' >"$TMP/env"
chmod 600 "$TMP/env"
: >"$TMP/docker.log"
cat >"$TMP/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$STUB_DOCKER_LOG"
case "$*" in
  *' psql '*'SELECT to_regclass'*) printf 't\n';;
esac
EOF
chmod +x "$TMP/bin/docker"
if PATH="$TMP/bin:$PATH" STUB_DOCKER_LOG="$TMP/docker.log" BACKUP_DIR="$TMP/backups" BACKUP_ENV_FILE="$TMP/env" "$DRILL" "$TMP/protec-gestion-20261006-021500.sql.gz" >"$TMP/drill.out" 2>&1; then
  fail "restore drill accepted a dump outside BACKUP_DIR"
fi
test ! -s "$TMP/docker.log" || fail "restore drill touched PostgreSQL for an out-of-directory dump"

printf 'inside\n' | gzip -c >"$TMP/backups/protec-gestion-20261006-021500.sql.gz"
PATH="$TMP/bin:$PATH" STUB_DOCKER_LOG="$TMP/docker.log" BACKUP_DIR="$TMP/backups" BACKUP_ENV_FILE="$TMP/env" "$DRILL" "$TMP/backups/protec-gestion-20261006-021500.sql.gz" >"$TMP/drill.out"
grep -Eq 'createdb .*protec_restore_drill_[0-9]{14}_[0-9]+' "$TMP/docker.log" || fail "restore drill database name is not unique"
grep -Eq 'dropdb .*protec_restore_drill_[0-9]{14}_[0-9]+' "$TMP/docker.log" || fail "restore drill did not remove its own database"
printf 'test-backup: ok\n'
