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

# Behavioral preflight: an occupied VMID must stop before any creation.
mkdir -p "$TMP/bin"
cat >"$TMP/bin/pvecm" <<'EOF'
#!/bin/sh
printf 'Quorate: Yes\n'
EOF
cat >"$TMP/bin/pvesh" <<'EOF'
#!/bin/sh
printf '{"memory":{"free":8589934592}}\n'
EOF
cat >"$TMP/bin/jq" <<'EOF'
#!/bin/sh
case "$1" in -r) printf '8589934592\n';; *) exit 0;; esac
EOF
cat >"$TMP/bin/qm" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >>"$STUB_QM_LOG"
case "$1" in status) exit 0;; list) exit 0;; create) printf created >>"$STUB_CREATED";; esac
EOF
for command in pvesm ip ping getent sha512sum; do
  cat >"$TMP/bin/$command" <<'EOF'
#!/bin/sh
exit 0
EOF
done
chmod +x "$TMP/bin"/*
printf 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest operator\n' >"$TMP/id.pub"
printf 'x' >"$TMP/image"; printf '%0128d  image\n' 0 >"$TMP/image.sha512"; : >"$TMP/leases"; : >"$TMP/qm.log"
assert_fails env PATH="$TMP/bin:$PATH" STUB_QM_LOG="$TMP/qm.log" STUB_CREATED="$TMP/created" \
  SSH_PUBLIC_KEY="$TMP/id.pub" STATIC_IP_CIDR=192.0.2.10/24 GATEWAY=192.0.2.1 DNS_SERVER=192.0.2.53 \
  ADMIN_CIDR=192.0.2.0/24 LAN_CIDR=192.0.2.0/24 VPN_CIDR=198.51.100.0/24 DHCP_LEASE_FILE="$TMP/leases" \
  DEBIAN_IMAGE="$TMP/image" DEBIAN_CHECKSUM_FILE="$TMP/image.sha512" "$SCRIPT" --preflight-only
grep -q 'occupied' "$TMP/out" || fail "occupied VMID was not reported"
test ! -e "$TMP/created" || fail "qm create ran after collision"
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
! grep -q 'StrictHostKeyChecking=accept-new' "$SCRIPT" || fail "SSH trust-on-first-use is forbidden"
grep -q 'SSH_HOST_FINGERPRINT is required' "$SCRIPT" || fail "explicit SSH fingerprint is not required"
grep -q 'DOCKER-USER' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml" || fail "Docker-published port is not filtered"
grep -q 'DHCP_LEASE_FILE proof is required' "$SCRIPT" || fail "DHCP evidence is optional"
grep -q 'pvesh' "$SCRIPT" || fail "node/firewall/backup preflight is incomplete"
grep -q 'ip neigh' "$SCRIPT" || fail "ARP evidence is missing"
! grep -q 'flush ruleset' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml" || fail "cloud-init flushes Docker firewall rules"
printf 'test-create-vm: ok\n'
