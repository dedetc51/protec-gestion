#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SCRIPT="$ROOT/ops/provision/create-vm.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_fails() { if "$@" >"$TMP/out" 2>&1; then fail "command unexpectedly succeeded: $*"; fi; }

assert_fails "$SCRIPT"
grep -q 'required' "$TMP/out" || fail "missing required-input diagnostic"
grep -q -- '--preflight-only' "$SCRIPT" || fail "missing read-only preflight mode"
grep -q 'qm status' "$SCRIPT" || fail "missing VMID collision check"
grep -q 'qm destroy' "$SCRIPT" || fail "missing partial-creation cleanup"
grep -q 'sha512sum' "$SCRIPT" || fail "missing checksum validation"
grep -q 'curl --fail' "$SCRIPT" || fail "missing verified image download"
grep -q 'cloud-init status --wait' "$SCRIPT" || fail "missing cloud-init boot validation"
grep -q 'ADMIN_CIDR' "$SCRIPT" || fail "cloud-init network placeholders are not rendered"
grep -q 'docker.io' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml" || fail "Docker package source is missing"
grep -q -- '--net0.*bridge=.*firewall=1' "$SCRIPT" || fail "VM NIC bridge/firewall is missing"
grep -q 'SSH_AUTHORIZED_KEY' "$SCRIPT" || fail "public key is not rendered into cicustom"
grep -q 'exitcode' "$SCRIPT" || fail "guest exec JSON exitcode is not checked"
grep -q 'ip route' "$SCRIPT" || fail "guest route is not verified"
grep -q 'timedatectl' "$SCRIPT" || fail "NTP is not verified"
grep -q 'ssh-keyscan' "$SCRIPT" || fail "SSH fingerprint is not verified"
grep -q 'StrictHostKeyChecking=yes' "$SCRIPT" || fail "SSH access is not reverified after reboot"
grep -q 'pvesh' "$SCRIPT" || fail "node/firewall/backup preflight is incomplete"
grep -q 'ip neigh' "$SCRIPT" || fail "ARP evidence is missing"
! grep -q 'flush ruleset' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml" || fail "cloud-init flushes Docker firewall rules"
printf 'test-create-vm: ok\n'
