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

mkdir -p "$TMP/bin" "$TMP/snippets"
cat >"$TMP/bin/pvecm" <<'EOF'
#!/bin/sh
printf 'Quorate: Yes\n'
EOF
cat >"$TMP/bin/pvesh" <<'EOF'
#!/bin/sh
case "$*" in *status*) printf '{"memory":{"free":%s}}\n' "${STUB_FREE_MEM:-8589934592}";; *firewall*) printf '{}\n';; esac
EOF
cat >"$TMP/bin/jq" <<'EOF'
#!/bin/sh
case " $* " in
  *' -r '*) cat >/dev/null; printf '%s\n' "${STUB_FREE_MEM:-8589934592}";;
  *' -n '*)
    status=''; fingerprint=''
    while [ "$#" -gt 0 ]; do
      case "$1" in --arg) case "$2" in status) status=$3;; ssh_fingerprint) fingerprint=$3;; esac; shift 3;; *) shift;; esac
    done
    printf '{"status":"%s","ssh_fingerprint":"%s"}\n' "$status" "$fingerprint";;
  *) input=$(cat); [[ $input == *'"exitcode":1'* ]] && exit 1; exit 0;;
esac
EOF
cat >"$TMP/bin/pvesm" <<'EOF'
#!/bin/sh
case "$*" in *--content*backup*) printf '[{"active":1}]\n';; *) printf '[{"active":1,"avail":%s}]\n' "${STUB_STORAGE_AVAIL:-85899345920}";; esac
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
EOF
cat >"$TMP/bin/ssh-keygen" <<'EOF'
#!/bin/sh
printf '256 SHA256:approved protec-gestion (ED25519)\n'
EOF
cat >"$TMP/bin/ssh" <<'EOF'
#!/bin/sh
exit 0
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
    if [[ $* == *'systemctl is-active nftables docker'* ]]; then
      required=(
        'systemctl is-active --quiet protec-docker-firewall.service'
        'iptables -C DOCKER-USER -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT'
        "iptables -C DOCKER-USER -p tcp --dport 80 -s '192.0.2.0/24' -j ACCEPT"
        "iptables -C DOCKER-USER -p tcp --dport 80 -s '198.51.100.0/24' -j ACCEPT"
        'iptables -C DOCKER-USER -p tcp --dport 80 -j DROP'
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

# Independent name/IP/DNS/resource/checksum gates all run before creation.
for scenario in name ip dns ram storage checksum; do
  : >"$TMP/qm.log"; rm -f "$TMP/created"
  case $scenario in
    name) extra=(STUB_NAME_COLLISION=1); expected='VM name';;
    ip) extra=(STUB_PING_COLLISION=1); expected='answers ping';;
    dns) extra=(STUB_DNS_COLLISION=1); expected='DNS name occupied';;
    ram) extra=(STUB_FREE_MEM=1); expected='insufficient node RAM';;
    storage) extra=(STUB_STORAGE_AVAIL=1); expected='insufficient storage';;
    checksum) extra=(STUB_CHECKSUM_FAIL=1); expected='checksum';;
  esac
  assert_fails "${base_env[@]}" STUB_VM_EXISTS=0 "${extra[@]}" "$SCRIPT" --preflight-only
  assert_contains "$expected" "$TMP/out"
  assert_not_logged '^create '
done

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
assert_contains 'SHA256:approved' "$TMP/out"
grep -Eq '^start 115$' "$TMP/qm.log" || fail 'VM was not started'
grep -Eq '^shutdown 115 --timeout 60$' "$TMP/qm.log" || fail 'VM was not shut down cleanly for approval'
assert_not_logged '^destroy '

: >"$TMP/qm.log"
if ! "${base_env[@]}" STUB_VM_EXISTS=1 "$SCRIPT" --verify-fingerprint '256 SHA256:approved protec-gestion (ED25519)' >"$TMP/out" 2>&1; then
  sed -n '1,160p' "$TMP/out" >&2
  sed -n '1,200p' "$TMP/qm.log" >&2
  fail 'phase 2 verification failed'
fi
assert_contains '"status":"verified"' "$TMP/out"
assert_not_logged '^create '
assert_not_logged '^destroy '

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=1 STUB_FIREWALL_FAIL_AFTER_REBOOT=1 "$SCRIPT" --verify-fingerprint '256 SHA256:approved protec-gestion (ED25519)'
assert_contains 'post-reboot verification timed out' "$TMP/out"
! grep -Fq '"status":"verified"' "$TMP/out" || fail 'firewall failure was reported as verified'

: >"$TMP/qm.log"
assert_fails "${base_env[@]}" STUB_VM_EXISTS=1 "$SCRIPT" --verify-fingerprint '256 SHA256:wrong protec-gestion (ED25519)'
assert_contains 'fingerprint mismatch' "$TMP/out"
assert_not_logged '^destroy '

printf 'test-create-vm: ok\n'
