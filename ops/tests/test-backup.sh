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
test "$(stat -f '%Lp' "$ARCHIVE" 2>/dev/null || stat -c '%a' "$ARCHIVE")" = 600 || fail "archive mode is not 0600"
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
grep -q 'protec_restore_drill_' "$DRILL" || fail "restore drill does not use a unique disposable database"
! grep -q 'dropdb.*--if-exists' "$DRILL" || fail "restore drill must refuse collisions, not pre-drop"
printf 'test-backup: ok\n'
