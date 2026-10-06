#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SCRIPT="$ROOT/ops/provision/create-vm.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
assert_fails() { if "$@" >"$TMP/out" 2>&1; then fail "command unexpectedly succeeded: $*"; fi; }
assert_contains() { grep -Fq -- "$1" "$2" || fail "missing '$1' in $2"; }
assert_not_logged() { ! grep -Eq -- "$1" "$TMP/qm.log" || fail "unexpected qm action matching $1"; }

mkdir -p "$TMP/bin" "$TMP/snippets" "$TMP/hostkeys"
cat >"$TMP/bin/pvecm" <<'EOF'
#!/bin/sh
printf 'Quorate: Yes\n'
EOF
cat >"$TMP/bin/pvesh" <<'EOF'
#!/bin/sh
case "$*" in *status*) printf '{"memory":{"free":%s,"available":%s}}\n' "${STUB_FREE_MEM:-8589934592}" "${STUB_AVAILABLE_MEM:-8589934592}";; *firewall*) printf '{}\n';; esac
EOF
cat >"$TMP/bin/jq" <<'EOF'
#!/usr/bin/env bash
[[ ${STUB_JQ_UNAVAILABLE:-0} == 1 ]] && exit 127
[[ ${1:-} == --version ]] && { printf 'jq-1.7\n'; exit 0; }
case " $* " in
  *' -r '*out-data*)
    input=$(cat)
    case "$input" in
      *'status: error'*) printf 'status: error\n';;
      *'status: running'*) printf 'status: running\n';;
    esac
    ;;
  *' -r '*)
    cat >/dev/null
    case "$*" in
      *memory.available*) printf '%s\n' "${STUB_AVAILABLE_MEM:-8589934592}";;
      *) printf '%s\n' "${STUB_FREE_MEM:-8589934592}";;
    esac
    ;;
  *' -n '*)
    status=''; fingerprints=''
    while [ "$#" -gt 0 ]; do
      case "$1" in --arg) case "$2" in status) status=$3;; ssh_fingerprints) fingerprints=$3;; esac; shift 3;; *) shift;; esac
    done
    printf '{"status":"%s","ssh_fingerprints":"%s"}\n' "$status" "$fingerprints";;
  *) input=$(cat); [[ $input == *'"exitcode":1'* ]] && exit 1; exit 0;;
esac
EOF
cat >"$TMP/bin/pvesm" <<'EOF'
#!/bin/sh
case "$*" in
  *--output-format*) printf 'Unknown option: output-format\n' >&2; exit 255;;
  *--storage\ nas-backup*--content\ backup*|*--content\ backup*--storage\ nas-backup*)
    printf 'Name Type Status Total (KiB) Used (KiB) Available (KiB) %%\n'
    [ "${STUB_BACKUP_ACTIVE:-1}" -eq 1 ] && printf 'nas-backup cifs active 104857600 1 104857599 0.00%%\n'
    ;;
  *--storage\ local-lvm*)
    printf 'Name Type Status Total (KiB) Used (KiB) Available (KiB) %%\n'
    printf 'local-lvm lvmthin active 167772160 1 %s 0.00%%\n' "${STUB_STORAGE_AVAIL_KIB:-83886080}"
    ;;
  *) exit 2;;
esac
EOF
cat >"$TMP/bin/ip" <<'EOF'
#!/bin/sh
case "$1 $2" in
  'link show') exit 0;; 'neigh show') exit 0;;
  'route show') printf 'default via %s dev vmbr0\n' "${STUB_LIVE_GATEWAY:-$GATEWAY}";;
  'route get') printf '%s via %s dev vmbr0\n' "$2" "${STUB_LIVE_GATEWAY:-$GATEWAY}";;
esac
EOF
cat >"$TMP/bin/ping" <<'EOF'
#!/usr/bin/env bash
[[ ${STUB_PING_COLLISION:-0} == 1 ]] && exit 0
exit 1
EOF
cat >"$TMP/bin/getent" <<'EOF'
#!/bin/sh
exit 2
EOF
cat >"$TMP/bin/dig" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  *deb.debian.org*) printf '203.0.113.10\n';;
  *protec-gestion.cuperly*) [[ ${STUB_DNS_COLLISION:-0} == 1 ]] && printf '192.0.2.10\n';;
esac
EOF
cat >"$TMP/bin/sha512sum" <<'EOF'
#!/usr/bin/env bash
cat >/dev/null
[[ ${STUB_CHECKSUM_FAIL:-0} == 1 ]] && exit 1
exit 0
EOF
cat >"$TMP/bin/ssh-keyscan" <<'EOF'
#!/bin/sh
printf '192.0.2.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest\n'
printf '192.0.2.10 ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYITest\n'
EOF
cat >"$TMP/bin/ssh-keygen" <<'EOF'
#!/bin/sh
printf '256 SHA256:first-ed25519 protec-gestion (ED25519)\n'
printf '256 SHA256:approved-ecdsa protec-gestion (ECDSA)\n'
EOF
cat >"$TMP/bin/ssh" <<'EOF'
#!/usr/bin/env bash
known_hosts=''
for argument in "$@"; do
  case $argument in UserKnownHostsFile=*) known_hosts=${argument#*=};; esac
done
[[ -n $known_hosts && -s $known_hosts ]] || exit 42
EOF
cat >"$TMP/bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF
cat >"$TMP/bin/qm" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$STUB_QM_LOG"
case "$1" in
  status) [[ ${STUB_VM_EXISTS:-0} == 1 ]] && { printf 'status: stopped\n'; exit 0; }; exit 1;;
  list) printf ' VMID NAME STATUS\n'; [[ ${STUB_NAME_COLLISION:-0} == 1 ]] && printf ' 999 protec-gestion stopped\n';;
  config) printf 'name: protec-gestion\ntags: webapp;debian13;protec-gestion\nnet0: virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,firewall=1\n';;
  guest)
    if [[ $* == *'cloud-init status --wait'* ]]; then
      count=0
      [[ -f $STUB_GUEST_COUNT ]] && count=$(<"$STUB_GUEST_COUNT")
      count=$((count + 1))
      printf '%s\n' "$count" >"$STUB_GUEST_COUNT"
      if ((count < ${STUB_CLOUD_INIT_READY_AFTER:-1})); then
        printf '{"exitcode":1,"exited":true}\n'
        exit 0
      fi
    fi
    if [[ $* == *'cloud-init status --long'* ]]; then
      if [[ ${STUB_CLOUD_INIT_ERROR:-0} == 1 ]]; then
        printf '{"exitcode":0,"exited":true,"out-data":"status: error\\n"}\n'
      else
        printf '{"exitcode":0,"exited":true,"out-data":"status: running\\n"}\n'
      fi
      exit 0
    fi
    if [[ $* == *'systemctl is-active nftables docker'* ]]; then
      required=(
        'systemctl is-active --quiet protec-docker-firewall.service'
        'iptables -S DOCKER-USER 1'
        'iptables -S DOCKER-USER 2'
        'iptables -S DOCKER-USER 3'
        'iptables -S DOCKER-USER 4'
      )
      for check in "${required[@]}"; do
        [[ $* == *"$check"* ]] || { printf '{"exitcode":1,"exited":true}\n'; exit 0; }
      done
      if [[ ${STUB_FIREWALL_FAIL_AFTER_REBOOT:-0} == 1 ]] && grep -q '^reboot 115$' "$STUB_QM_LOG"; then
        printf '{"exitcode":1,"exited":true}\n'; exit 0
      fi
    fi
    printf '{"exitcode":0,"exited":true}\n';;
  create) : >"$STUB_CREATED";;
  importdisk) [[ ${STUB_IMPORT_FAIL:-0} == 1 ]] && exit 33; exit 0;;
esac
EOF
chmod +x "$TMP/bin"/*
printf 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest operator\n' >"$TMP/id.pub"
printf 'x' >"$TMP/image"; printf '%0128d  image\n' 0 >"$TMP/image.sha512"
: >"$TMP/leases"; printf 'reserved 192.0.2.10 protec-gestion\n' >"$TMP/dhcp-reservations"

base_env=(env PATH="$TMP/bin:$PATH" STUB_QM_LOG="$TMP/qm.log" STUB_CREATED="$TMP/created"
  STUB_GUEST_COUNT="$TMP/guest-count" TMPDIR="$TMP/hostkeys"
  SSH_PUBLIC_KEY="$TMP/id.pub" STATIC_IP_CIDR=192.0.2.10/24 GATEWAY=192.0.2.1 DNS_SERVER=192.0.2.53
  ADMIN_CIDR=192.0.2.0/24 LAN_CIDR=192.0.2.0/24 VPN_CIDR=198.51.100.0/24
  DHCP_LEASE_FILE="$TMP/leases" DHCP_RESERVATION_FILE="$TMP/dhcp-reservations"
  DEBIAN_IMAGE="$TMP/image" DEBIAN_CHECKSUM_FILE="$TMP/image.sha512" PROXMOX_SNIPPET_DIR="$TMP/snippets")

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=1 "$SCRIPT" --preflight-only
assert_contains 'occupied' "$TMP/out"
assert_not_logged '^create '

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_LIVE_GATEWAY=192.0.2.254 STUB_VM_EXISTS=0 "$SCRIPT" --preflight-only
assert_contains 'gateway' "$TMP/out"
assert_fails env "${base_env[@]:1}" DHCP_RESERVATION_FILE=/missing STUB_VM_EXISTS=0 "$SCRIPT" --preflight-only
assert_contains 'DHCP' "$TMP/out"

# PVE 9 exposes pvesm status only as a table. Preflight must consume that
# interface and require the named remote backup storage, not any local target.
: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 STUB_BACKUP_ACTIVE=0 "$SCRIPT" --preflight-only
assert_contains 'nas-backup' "$TMP/out"
assert_not_logged '^create '

# jq is a real provisioning prerequisite and must fail with an actionable
# preflight error before any VM mutation when it is unavailable.
: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 STUB_JQ_UNAVAILABLE=1 "$SCRIPT" --preflight-only
assert_contains 'jq' "$TMP/out"
assert_not_logged '^create '

# Linux may report little completely free RAM while reclaimable/cache-backed
# memory remains available. VM capacity must use memory.available first.
: >"$TMP/qm.log"
if ! "${base_env[@]}" STUB_VM_EXISTS=0 STUB_FREE_MEM=1 STUB_AVAILABLE_MEM=8589934592 "$SCRIPT" --preflight-only >"$TMP/out" 2>&1; then
  sed -n '1,160p' "$TMP/out" >&2
  fail 'preflight rejected sufficient available node memory'
fi
assert_contains 'preflight=ok' "$TMP/out"
assert_not_logged '^create '

# Debian package upgrades and Docker installation can legitimately keep
# cloud-init busy beyond five minutes. The default wait must cover 15 minutes.
: >"$TMP/qm.log"; rm -f "$TMP/created" "$TMP/guest-count"
if ! "${base_env[@]}" STUB_VM_EXISTS=0 STUB_CLOUD_INIT_READY_AFTER=180 "$SCRIPT" >"$TMP/out" 2>&1; then
  sed -n '1,160p' "$TMP/out" >&2
  fail 'phase 1 timed out before the 15-minute cloud-init budget'
fi
[[ $(<"$TMP/guest-count") -eq 180 ]] || fail 'cloud-init polling did not honor the 15-minute budget'
assert_contains 'awaiting_fingerprint_approval' "$TMP/out"

# A terminal cloud-init error must abort immediately instead of burning the
# entire timeout budget while a broken guest can never become ready.
: >"$TMP/qm.log"; rm -f "$TMP/created" "$TMP/guest-count"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 STUB_CLOUD_INIT_READY_AFTER=180 STUB_CLOUD_INIT_ERROR=1 "$SCRIPT"
assert_contains 'cloud-init reported error' "$TMP/out"
[[ $(<"$TMP/guest-count") -eq 1 ]] || fail 'terminal cloud-init error did not fail fast'
grep -Eq '^destroy 115 --purge 1$' "$TMP/qm.log" || fail 'failed cloud-init VM was not cleaned up'

# Invalid timeout configuration is rejected before VM creation.
: >"$TMP/qm.log"; rm -f "$TMP/created" "$TMP/guest-count"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 CLOUD_INIT_TIMEOUT_SECONDS=0 "$SCRIPT"
assert_contains 'CLOUD_INIT_TIMEOUT_SECONDS' "$TMP/out"
assert_not_logged '^create '

# Independent name/IP/DNS/resource/checksum gates all run before creation.
for scenario in name ip dns ram storage checksum; do
  : >"$TMP/qm.log"; rm -f "$TMP/created"
  case $scenario in
    name) extra=(STUB_NAME_COLLISION=1); expected='VM name';;
    ip) extra=(STUB_PING_COLLISION=1); expected='answers ping';;
    dns) extra=(STUB_DNS_COLLISION=1); expected='DNS name occupied';;
    ram) extra=(STUB_FREE_MEM=1 STUB_AVAILABLE_MEM=1); expected='insufficient node RAM';;
    storage) extra=(STUB_STORAGE_AVAIL_KIB=1); expected='insufficient storage';;
    checksum) extra=(STUB_CHECKSUM_FAIL=1); expected='checksum';;
  esac
  assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 "${extra[@]}" "$SCRIPT" --preflight-only
  assert_contains "$expected" "$TMP/out"
  assert_not_logged '^create '
done

# Execute the cloud-init Docker firewall helper with an empty chain: all rules
# must be inserted explicitly before Docker's terminal RETURN rule.
awk '
  /path: \/usr\/local\/sbin\/protec-docker-firewall/ {wanted=1; next}
  wanted && /content: \|/ {content=1; next}
  content && /^  - path:/ {exit}
  content {sub(/^      /, ""); print}
' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml" >"$TMP/firewall-helper"
chmod +x "$TMP/firewall-helper"
cat >"$TMP/bin/iptables" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$STUB_IPTABLES_LOG"
[[ $1 == -C ]] && exit 1
exit 0
EOF
chmod +x "$TMP/bin/iptables"
: >"$TMP/iptables.log"
PATH="$TMP/bin:$PATH" STUB_IPTABLES_LOG="$TMP/iptables.log" "$TMP/firewall-helper"
for position in 1 2 3 4; do
  grep -Eq "^-I DOCKER-USER $position " "$TMP/iptables.log" || fail "firewall rule $position is not inserted before RETURN"
done
! grep -Eq '^-A DOCKER-USER ' "$TMP/iptables.log" || fail 'firewall helper appends a rule after Docker RETURN'

# Debian 13 ships Compose v2 through docker-compose; the obsolete package name
# would make cloud-init fail before the guest can be provisioned.
packages=$(awk '
  /^packages:/ {wanted=1; next}
  wanted && /^[^ ]/ {exit}
  wanted && /^  - / {sub(/^  - /, ""); print}
' "$ROOT/ops/provision/protec-gestion-cloud-init.yaml")
grep -Fxq 'docker-compose' <<<"$packages" || fail 'Debian docker-compose package is missing'
! grep -Fxq 'docker-compose-v2' <<<"$packages" || fail 'obsolete docker-compose-v2 package remains configured'

# A failure after qm create cleans up only the VM created by this invocation.
: >"$TMP/qm.log"; rm -f "$TMP/created"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 STUB_IMPORT_FAIL=1 "$SCRIPT"
grep -Eq '^destroy 115 --purge 1$' "$TMP/qm.log" || fail 'partial created VM was not destroyed'

: >"$TMP/qm.log"; rm -f "$TMP/created"
if ! "${base_env[@]}" STUB_VM_EXISTS=0 "$SCRIPT" >"$TMP/out" 2>&1; then
  sed -n '1,160p' "$TMP/out" >&2
  sed -n '1,160p' "$TMP/qm.log" >&2
  fail 'phase 1 provisioning failed'
fi
assert_contains 'awaiting_fingerprint_approval' "$TMP/out"
assert_contains 'SHA256:first-ed25519' "$TMP/out"
assert_contains 'SHA256:approved-ecdsa' "$TMP/out"
test -z "$(find "$TMP/hostkeys" -type f -print -quit)" || fail 'temporary SSH host key file was not cleaned up'
grep -Eq '^start 115$' "$TMP/qm.log" || fail 'VM was not started'
grep -Eq '^shutdown 115 --timeout 60$' "$TMP/qm.log" || fail 'VM was not shut down cleanly for approval'
assert_not_logged '^destroy '

: >"$TMP/qm.log"
if ! "${base_env[@]}" STUB_VM_EXISTS=1 "$SCRIPT" --verify-fingerprint '256 SHA256:approved-ecdsa protec-gestion (ECDSA)' >"$TMP/out" 2>&1; then
  sed -n '1,160p' "$TMP/out" >&2
  sed -n '1,200p' "$TMP/qm.log" >&2
  fail 'phase 2 verification failed'
fi
assert_contains '"status":"verified"' "$TMP/out"
test -z "$(find "$TMP/hostkeys" -type f -print -quit)" || fail 'temporary SSH host key file was not cleaned up after verification'
assert_not_logged '^create '
assert_not_logged '^destroy '

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=1 STUB_FIREWALL_FAIL_AFTER_REBOOT=1 "$SCRIPT" --verify-fingerprint '256 SHA256:approved-ecdsa protec-gestion (ECDSA)'
assert_contains 'post-reboot verification timed out' "$TMP/out"
! grep -Fq '"status":"verified"' "$TMP/out" || fail 'firewall failure was reported as verified'

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=1 "$SCRIPT" --verify-fingerprint '256 SHA256:wrong protec-gestion (ED25519)'
assert_contains 'fingerprint mismatch' "$TMP/out"
assert_not_logged '^destroy '

printf 'test-create-vm: ok\n'
