#!/usr/bin/env bash
set -Eeuo pipefail

VMID=${VMID:-115}
VM_NAME=${VM_NAME:-protec-gestion}
NODE=${PROXMOX_NODE:-pve1}
STORAGE=${PROXMOX_STORAGE:-local-lvm}
BRIDGE=${PROXMOX_BRIDGE:-vmbr0}
IMAGE=${DEBIAN_IMAGE:-/var/lib/vz/template/iso/debian-13-genericcloud-amd64.qcow2}
CHECKSUM_FILE=${DEBIAN_CHECKSUM_FILE:-$IMAGE.sha512}
SNIPPET_DIR=${PROXMOX_SNIPPET_DIR:-/var/lib/vz/snippets}
HTTP_PORT=${HTTP_PORT:-8080}
BACKUP_STORAGE=nas-backup
CLOUD_INIT_TIMEOUT_SECONDS=${CLOUD_INIT_TIMEOUT_SECONDS:-900}

owned_vm=0
preserve_vm=0
started_existing=0
tmp_image=''
tmp_sum=''
snippet=''
host_keys=''

cleanup() {
  rc=$?
  [[ -n $tmp_image ]] && rm -f -- "$tmp_image"
  [[ -n $tmp_sum ]] && rm -f -- "$tmp_sum"
  [[ -n $host_keys ]] && rm -f -- "$host_keys"
  if ((owned_vm && ! preserve_vm)); then
    qm stop "$VMID" >/dev/null 2>&1 || true
    qm destroy "$VMID" --purge 1 >/dev/null 2>&1 || true
    [[ -n $snippet ]] && rm -f -- "$snippet"
  elif ((started_existing && rc != 0)); then
    qm shutdown "$VMID" --timeout 60 >/dev/null 2>&1 || qm stop "$VMID" >/dev/null 2>&1 || true
  fi
  return "$rc"
}
trap cleanup EXIT

die() { printf 'provision: %s\n' "$*" >&2; exit 1; }

require_inputs() {
  local variable
  for variable in SSH_PUBLIC_KEY STATIC_IP_CIDR GATEWAY DNS_SERVER ADMIN_CIDR LAN_CIDR VPN_CIDR DHCP_LEASE_FILE DHCP_RESERVATION_FILE; do
    [[ -n ${!variable:-} ]] || die "$variable is required"
  done
}

verify_checksum() {
  local base matches
  base=$(basename "$IMAGE")
  matches=$(awk -v f="$base" '$2==f || $2=="*"f {print $1}' "$CHECKSUM_FILE")
  [[ $(wc -l <<<"$matches") -eq 1 && $matches =~ ^[0-9a-fA-F]{128}$ ]] || die 'checksum file must contain exactly one official image entry'
  printf '%s  %s\n' "$matches" "$IMAGE" | sha512sum -c - >/dev/null || die 'image checksum failed'
}

active_storage_available_kib() {
  local storage=$1 content=${2:-} output
  if [[ -n $content ]]; then
    output=$(pvesm status --storage "$storage" --content "$content") || return 1
  else
    output=$(pvesm status --storage "$storage") || return 1
  fi
  awk -v storage="$storage" '$1 == storage && $3 == "active" && $6 ~ /^[0-9]+$/ { print $6; found=1; exit } END { if (!found) exit 1 }' <<<"$output"
}

ensure_image() {
  if [[ ! -s $IMAGE || ! -s $CHECKSUM_FILE ]]; then
    [[ ${DEBIAN_IMAGE_URL:-} =~ ^https://cloud\.debian\.org/images/cloud/trixie/ && ${DEBIAN_CHECKSUM_URL:-} =~ ^https://cloud\.debian\.org/images/cloud/trixie/ ]] || die 'official cloud.debian.org trixie image/checksum URLs are required'
    tmp_image="$IMAGE.download"
    tmp_sum="$CHECKSUM_FILE.download"
    curl --fail --location --proto '=https' --tlsv1.2 "$DEBIAN_IMAGE_URL" -o "$tmp_image"
    curl --fail --location --proto '=https' --tlsv1.2 "$DEBIAN_CHECKSUM_URL" -o "$tmp_sum"
    mv "$tmp_image" "$IMAGE"; tmp_image=''
    mv "$tmp_sum" "$CHECKSUM_FILE"; tmp_sum=''
  fi
  verify_checksum
}

validate_expected_vm() {
  local config status
  status=$(qm status "$VMID") || die "expected VMID $VMID does not exist"
  grep -Eq '^status:[[:space:]]+stopped$' <<<"$status" || die 'expected VM is not stopped in fingerprint-approval state'
  config=$(qm config "$VMID") || die "cannot inspect expected VMID $VMID"
  grep -Fxq "name: $VM_NAME" <<<"$config" || die 'existing VM name does not match the expected provisioned VM'
  grep -Eq '^tags: .*protec-gestion' <<<"$config" || die 'existing VM lacks the protec-gestion ownership tag'
  grep -Eq "^net0: .*bridge=$BRIDGE(,|$)" <<<"$config" || die 'existing VM bridge does not match'
}

preflight() {
  local allow_existing=$1 address node_json free_mem avail_kib key live_gateway direct_dns
  require_inputs
  command -v jq >/dev/null 2>&1 && jq --version >/dev/null 2>&1 || die 'jq is required on the Proxmox host'
  address=${STATIC_IP_CIDR%/*}
  [[ $VM_NAME =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]] || die 'invalid VM_NAME'
  pvecm status | grep -q 'Quorate:.*Yes' || die 'cluster is not quorate'
  node_json=$(pvesh get "/nodes/$NODE/status" --output-format json) || die 'target node unavailable'
  jq -e 'type=="object" and (.memory|type=="object")' <<<"$node_json" >/dev/null || die 'invalid node JSON'
  free_mem=$(jq -r '.memory.available // (.memory.total-.memory.used) // .memory.free // 0' <<<"$node_json")
  [[ $free_mem -ge 4294967296 ]] || die 'insufficient node RAM'
  if ((allow_existing)); then
    validate_expected_vm
  else
    ! qm status "$VMID" >/dev/null 2>&1 || die "VMID $VMID is occupied"
    ! qm list | awk 'NR>1 {print $2}' | grep -Fxq "$VM_NAME" || die "VM name $VM_NAME is occupied"
  fi
  avail_kib=$(active_storage_available_kib "$STORAGE") || die "storage $STORAGE inactive"
  [[ $avail_kib -ge 41943040 ]] || die 'insufficient storage'
  ip link show "$BRIDGE" >/dev/null || die 'unknown bridge'
  [[ -s $SSH_PUBLIC_KEY ]] || die 'SSH public key is absent'
  key=$(<"$SSH_PUBLIC_KEY")
  [[ $key =~ ^ssh-(ed25519|rsa)[[:space:]][A-Za-z0-9+/=]+([[:space:]][A-Za-z0-9@._-]+)?$ && $key != *$'\n'* ]] || die 'invalid SSH public key'

  live_gateway=$(ip route show default | awk '$1=="default" && $2=="via" {print $3; exit}')
  [[ $live_gateway == "$GATEWAY" ]] || die "live gateway $live_gateway does not match requested gateway $GATEWAY"
  ip route get "$DNS_SERVER" | grep -q . || die 'requested DNS server has no live route'
  direct_dns=$(dig +time=2 +tries=1 +short "@$DNS_SERVER" deb.debian.org A) || die 'direct query to requested DNS server failed'
  grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' <<<"$direct_dns" || die 'direct query to requested DNS server failed'

  [[ -r $DHCP_LEASE_FILE && -r $DHCP_RESERVATION_FILE ]] || die 'readable DHCP lease and reservation proofs are required'
  ! grep -Eq "(^|[^0-9.])${address//./\\.}([^0-9.]|$)" "$DHCP_LEASE_FILE" || die 'candidate IP is leased by DHCP'
  grep -Eq "(^|[^0-9.])${address//./\\.}([^0-9.]|$)" "$DHCP_RESERVATION_FILE" || die 'candidate IP lacks DHCP reservation/exclusion proof'

  if ((! allow_existing)); then
    ! ping -c 1 -W 1 "$address" >/dev/null 2>&1 || die 'candidate IP answers ping'
    ! ip neigh show "$address" | grep -qE 'lladdr|REACHABLE|STALE' || die 'candidate IP has ARP evidence'
    [[ -z $(dig +time=2 +tries=1 +short "@$DNS_SERVER" -x "$address") ]] || die 'candidate IP has reverse DNS'
    [[ -z $(dig +time=2 +tries=1 +short "@$DNS_SERVER" "$VM_NAME.cuperly" A) ]] || die 'DNS name occupied'
  fi
  pvesh get "/nodes/$NODE/firewall/options" --output-format json >/dev/null || die 'firewall policy unavailable'
  active_storage_available_kib "$BACKUP_STORAGE" backup >/dev/null || die "required backup storage $BACKUP_STORAGE is not active with backup content"
  verify_checksum
  printf 'preflight=ok node=%s vmid=%s ip=%s gateway=%s dns=%s bridge=%s storage=%s vpn=%s\n' "$NODE" "$VMID" "$address" "$GATEWAY" "$DNS_SERVER" "$BRIDGE" "$STORAGE" "$VPN_CIDR"
}

guest_exec() {
  local result
  result=$(qm guest exec "$VMID" -- "$@")
  jq -e 'type=="object" and .exitcode == 0 and (.exited == true or .exited == 1)' <<<"$result" >/dev/null
}

wait_cloud_init() {
  local attempt attempts
  attempts=$(((CLOUD_INIT_TIMEOUT_SECONDS + 4) / 5))
  for ((attempt = 1; attempt <= attempts; attempt++)); do
    guest_exec cloud-init status --wait && return 0
    sleep 5
  done
  return 1
}

verify_guest() {
  guest_exec sh -c "test \"\$(hostname)\" = '$VM_NAME' && ip -4 addr show | grep -F '$STATIC_IP_CIDR' && ip route | grep -F 'default via $GATEWAY' && getent hosts deb.debian.org && timedatectl show -p NTPSynchronized --value | grep -qx yes && grep -qx 'VERSION_ID=\"13\"' /etc/os-release && docker compose version && systemctl is-active nftables docker && systemctl is-active --quiet protec-docker-firewall.service && iptables -S DOCKER-USER 1 | grep -Eq -- '^-A DOCKER-USER .*--ctstate (ESTABLISHED,RELATED|RELATED,ESTABLISHED).* -j ACCEPT$' && iptables -S DOCKER-USER 2 | grep -Eq -- '^-A DOCKER-USER .*(-s $LAN_CIDR.*--dport 80|--dport 80.*-s $LAN_CIDR).* -j ACCEPT$' && iptables -S DOCKER-USER 3 | grep -Eq -- '^-A DOCKER-USER .*(-s $VPN_CIDR.*--dport 80|--dport 80.*-s $VPN_CIDR).* -j ACCEPT$' && iptables -S DOCKER-USER 4 | grep -Eq -- '^-A DOCKER-USER .*--dport 80.* -j DROP$'"
}

scan_fingerprint() {
  local address=$1 output_variable=$2 fingerprint
  [[ -n $host_keys ]] && rm -f -- "$host_keys"
  host_keys=$(mktemp)
  ssh-keyscan -T 5 "$address" >"$host_keys" 2>/dev/null || die 'unable to scan SSH host key'
  fingerprint=$(ssh-keygen -lf "$host_keys" | head -1)
  printf -v "$output_variable" '%s' "$fingerprint"
}

mode=create
expected_fingerprint=''
case ${1:-} in
  --preflight-only) [[ $# -eq 1 ]] || die 'usage: create-vm.sh [--preflight-only | --verify-fingerprint <exact>]'; mode=preflight;;
  --verify-fingerprint) [[ $# -eq 2 && -n ${2:-} ]] || die 'usage: create-vm.sh [--preflight-only | --verify-fingerprint <exact>]'; mode=resume; expected_fingerprint=$2;;
  '') [[ $# -eq 0 ]] || die 'usage: create-vm.sh [--preflight-only | --verify-fingerprint <exact>]' ;;
  *) die 'usage: create-vm.sh [--preflight-only | --verify-fingerprint <exact>]' ;;
esac

require_inputs
for cidr in "$ADMIN_CIDR" "$LAN_CIDR" "$VPN_CIDR"; do
  [[ $cidr =~ ^[0-9a-fA-F:.]+/[0-9]{1,3}$ ]] || die "invalid CIDR: $cidr"
done
[[ $CLOUD_INIT_TIMEOUT_SECONDS =~ ^[0-9]+$ && $CLOUD_INIT_TIMEOUT_SECONDS -ge 300 && $CLOUD_INIT_TIMEOUT_SECONDS -le 1800 ]] || die 'CLOUD_INIT_TIMEOUT_SECONDS must be an integer between 300 and 1800'

if [[ $mode == preflight ]]; then
  [[ -s $IMAGE && -s $CHECKSUM_FILE ]] || die 'verified image cache required for read-only preflight'
  preflight 0
  exit 0
fi

address=${STATIC_IP_CIDR%/*}
if [[ $mode == resume ]]; then
  [[ -s $IMAGE && -s $CHECKSUM_FILE ]] || die 'verified image cache required to resume'
  preflight 1
  qm start "$VMID"
  started_existing=1
  wait_cloud_init || die 'guest agent/cloud-init timed out'
  scan_fingerprint "$address" observed_fingerprint
  [[ $observed_fingerprint == "$expected_fingerprint" ]] || die "SSH host fingerprint mismatch: observed $observed_fingerprint"
  ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$host_keys" "protec-deploy@$address" true || die 'SSH public-key access failed'
  verify_guest || die 'guest validation failed'
  qm reboot "$VMID"
  for attempt in {1..60}; do verify_guest && rebooted=1 && break; sleep 5; done
  [[ ${rebooted:-0} == 1 ]] || die 'post-reboot verification timed out'
  scan_fingerprint "$address" fingerprint_after
  [[ $fingerprint_after == "$expected_fingerprint" ]] || die 'SSH fingerprint changed across reboot'
  ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$host_keys" "protec-deploy@$address" true || die 'SSH access failed after reboot'
  started_existing=0
  jq -n --arg vmid "$VMID" --arg name "$VM_NAME" --arg node "$NODE" --arg ip "$address" --arg ssh_fingerprint "$fingerprint_after" --arg status verified '{vmid:($vmid|tonumber),name:$name,node:$node,ip:$ip,ssh_fingerprint:$ssh_fingerprint,status:$status}'
  exit 0
fi

ensure_image
preflight 0
snippet="$SNIPPET_DIR/protec-gestion-$VMID-user.yaml"
install -d -m 0755 "$SNIPPET_DIR"
key=$(<"$SSH_PUBLIC_KEY")
sed -e "s|ADMIN_CIDR|$ADMIN_CIDR|g" -e "s|LAN_CIDR|$LAN_CIDR|g" -e "s|VPN_CIDR|$VPN_CIDR|g" -e "s|HTTP_PORT|$HTTP_PORT|g" -e "s|SSH_AUTHORIZED_KEY|$key|g" "$(dirname "$0")/protec-gestion-cloud-init.yaml" >"$snippet"
chmod 0644 "$snippet"
qm create "$VMID" --name "$VM_NAME" --cores 2 --memory 4096 --scsihw virtio-scsi-single --agent enabled=1 --serial0 socket --vga serial0 --onboot 1 --tags 'webapp;debian13;protec-gestion' --net0 "virtio,bridge=$BRIDGE,firewall=1"
owned_vm=1
qm importdisk "$VMID" "$IMAGE" "$STORAGE"
qm set "$VMID" --scsi0 "$STORAGE:vm-$VMID-disk-0,discard=on,ssd=1" --boot order=scsi0 --ide2 "$STORAGE:cloudinit" --ipconfig0 "ip=$STATIC_IP_CIDR,gw=$GATEWAY" --nameserver "$DNS_SERVER" --cicustom "user=local:snippets/${snippet##*/}"
qm resize "$VMID" scsi0 40G
qm start "$VMID"
wait_cloud_init || die 'guest agent/cloud-init timed out'
scan_fingerprint "$address" observed_fingerprint
preserve_vm=1
qm shutdown "$VMID" --timeout 60
jq -n --arg vmid "$VMID" --arg name "$VM_NAME" --arg node "$NODE" --arg ip "$address" --arg ssh_fingerprint "$observed_fingerprint" --arg status awaiting_fingerprint_approval '{vmid:($vmid|tonumber),name:$name,node:$node,ip:$ip,ssh_fingerprint:$ssh_fingerprint,status:$status}'
