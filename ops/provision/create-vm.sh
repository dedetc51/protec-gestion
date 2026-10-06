#!/usr/bin/env bash
set -Eeuo pipefail
VMID=${VMID:-115}; VM_NAME=${VM_NAME:-protec-gestion}; NODE=${PROXMOX_NODE:-pve1}; STORAGE=${PROXMOX_STORAGE:-local-lvm}; BRIDGE=${PROXMOX_BRIDGE:-vmbr0}
IMAGE=${DEBIAN_IMAGE:-/var/lib/vz/template/iso/debian-13-genericcloud-amd64.qcow2}; CHECKSUM_FILE=${DEBIAN_CHECKSUM_FILE:-$IMAGE.sha512}; HTTP_PORT=${HTTP_PORT:-8080}
owned_vm=0; complete=0; tmp_image=''; tmp_sum=''; snippet=''
cleanup() { rc=$?; [[ -n $tmp_image ]] && rm -f -- "$tmp_image"; [[ -n $tmp_sum ]] && rm -f -- "$tmp_sum"; if ((owned_vm && ! complete)); then qm stop "$VMID" >/dev/null 2>&1 || true; qm destroy "$VMID" --purge 1 >/dev/null 2>&1 || true; [[ -n $snippet ]] && rm -f -- "$snippet"; fi; return "$rc"; }
trap cleanup EXIT
die() { printf 'provision: %s\n' "$*" >&2; exit 1; }
require_inputs() { for v in SSH_PUBLIC_KEY STATIC_IP_CIDR GATEWAY DNS_SERVER ADMIN_CIDR LAN_CIDR VPN_CIDR; do [[ -n ${!v:-} ]] || die "$v is required"; done; }
verify_checksum() {
  base=$(basename "$IMAGE"); matches=$(awk -v f="$base" '$2==f || $2=="*"f {print $1}' "$CHECKSUM_FILE")
  [[ $(wc -l <<<"$matches") -eq 1 && $matches =~ ^[0-9a-fA-F]{128}$ ]] || die 'checksum file must contain exactly one official image entry'
  printf '%s  %s\n' "$matches" "$IMAGE" | sha512sum -c - >/dev/null || die 'image checksum failed'
}
ensure_image() {
  if [[ ! -s $IMAGE || ! -s $CHECKSUM_FILE ]]; then
    [[ ${DEBIAN_IMAGE_URL:-} =~ ^https://cloud\.debian\.org/images/cloud/trixie/ && ${DEBIAN_CHECKSUM_URL:-} =~ ^https://cloud\.debian\.org/images/cloud/trixie/ ]] || die 'official cloud.debian.org trixie image/checksum URLs are required'
    tmp_image="$IMAGE.download"; tmp_sum="$CHECKSUM_FILE.download"
    curl --fail --location --proto '=https' --tlsv1.2 "$DEBIAN_IMAGE_URL" -o "$tmp_image"; curl --fail --location --proto '=https' --tlsv1.2 "$DEBIAN_CHECKSUM_URL" -o "$tmp_sum"
    mv "$tmp_image" "$IMAGE"; tmp_image=''; mv "$tmp_sum" "$CHECKSUM_FILE"; tmp_sum=''
  fi
  verify_checksum
}
preflight() {
  require_inputs; address=${STATIC_IP_CIDR%/*}
  [[ $VM_NAME =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]] || die 'invalid VM_NAME'
  pvecm status | grep -q 'Quorate:.*Yes' || die 'cluster is not quorate'
  node_json=$(pvesh get "/nodes/$NODE/status" --output-format json) || die 'target node unavailable'; jq -e 'type=="object" and (.memory|type=="object")' <<<"$node_json" >/dev/null || die 'invalid node JSON'
  free_mem=$(jq -r '.memory.free // (.memory.total-.memory.used) // 0' <<<"$node_json"); [[ $free_mem -ge 4294967296 ]] || die 'insufficient node RAM'
  qm status "$VMID" >/dev/null 2>&1 && die "VMID $VMID is occupied"
  ! qm list | awk 'NR>1 {print $2}' | grep -Fxq "$VM_NAME" || die "VM name $VM_NAME is occupied"
  storage_json=$(pvesm status --storage "$STORAGE" --output-format json); grep -q '"active"[[:space:]]*:[[:space:]]*1' <<<"$storage_json" || die 'storage inactive'
  avail=$(sed -n 's/.*"avail"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' <<<"$storage_json"); [[ -n $avail && $avail -ge 42949672960 ]] || die 'insufficient storage'
  ip link show "$BRIDGE" >/dev/null || die 'unknown bridge'; [[ -s $SSH_PUBLIC_KEY ]] || die 'SSH public key is absent'
  key=$(<"$SSH_PUBLIC_KEY"); [[ $key =~ ^ssh-(ed25519|rsa)[[:space:]][A-Za-z0-9+/=]+([[:space:]][A-Za-z0-9@._-]+)?$ && $key != *$'\n'* ]] || die 'invalid SSH public key'
  ! ping -c 1 -W 1 "$address" >/dev/null 2>&1 || die 'candidate IP answers ping'; ! ip neigh show "$address" | grep -qE 'lladdr|REACHABLE|STALE' || die 'candidate IP has ARP evidence'
  ! getent hosts "$address" >/dev/null || die 'candidate IP has reverse DNS'; ! getent hosts protec-gestion.cuperly >/dev/null || die 'DNS name occupied'
  [[ -r ${DHCP_LEASE_FILE:-} ]] || die 'readable DHCP_LEASE_FILE proof is required'; ! grep -Fq "$address" "$DHCP_LEASE_FILE" || die 'candidate IP is leased by DHCP'
  ip route get "$GATEWAY" | grep -q . || die 'gateway is not reachable through a live route'; getent hosts "$DNS_SERVER" >/dev/null || die 'DNS server cannot be resolved/reached'
  pvesh get "/nodes/$NODE/firewall/options" --output-format json >/dev/null || die 'firewall policy unavailable'
  pvesm status --content backup --output-format json | grep -q '"active"[[:space:]]*:[[:space:]]*1' || die 'no active backup storage'
  grep -q . <<<"$VPN_CIDR" || die 'VPN CIDR missing'; verify_checksum
  printf 'preflight=ok node=%s vmid=%s ip=%s bridge=%s storage=%s vpn=%s\n' "$NODE" "$VMID" "$address" "$BRIDGE" "$STORAGE" "$VPN_CIDR"
}
guest_exec() { result=$(qm guest exec "$VMID" -- "$@"); jq -e 'type=="object" and .exitcode == 0 and (.exited == true or .exited == 1)' <<<"$result" >/dev/null; }
verify_guest() {
  guest_exec sh -c "test \"\$(hostname)\" = '$VM_NAME' && ip -4 addr show | grep -F '$STATIC_IP_CIDR' && ip route | grep -F 'default via $GATEWAY' && getent hosts deb.debian.org && timedatectl show -p NTPSynchronized --value | grep -qx yes && grep -qx 'VERSION_ID=\"13\"' /etc/os-release && docker compose version && systemctl is-active nftables docker"
}
if [[ ${1:-} == --preflight-only ]]; then require_inputs; [[ -s $IMAGE && -s $CHECKSUM_FILE ]] || die 'verified image cache required for read-only preflight'; preflight; complete=1; exit 0; fi
[[ $# -eq 0 ]] || die 'usage: create-vm.sh [--preflight-only]'; require_inputs; ensure_image; preflight
for c in "$ADMIN_CIDR" "$LAN_CIDR" "$VPN_CIDR"; do [[ $c =~ ^[0-9a-fA-F:.]+/[0-9]{1,3}$ ]] || die "invalid CIDR: $c"; done
snippet_dir=/var/lib/vz/snippets; snippet="$snippet_dir/protec-gestion-$VMID-user.yaml"; install -d -m 0755 "$snippet_dir"
key=$(<"$SSH_PUBLIC_KEY"); sed -e "s|ADMIN_CIDR|$ADMIN_CIDR|g" -e "s|LAN_CIDR|$LAN_CIDR|g" -e "s|VPN_CIDR|$VPN_CIDR|g" -e "s|HTTP_PORT|$HTTP_PORT|g" -e "s|SSH_AUTHORIZED_KEY|$key|g" "$(dirname "$0")/protec-gestion-cloud-init.yaml" >"$snippet"; chmod 0644 "$snippet"
qm create "$VMID" --name "$VM_NAME" --cores 2 --memory 4096 --scsihw virtio-scsi-single --agent enabled=1 --serial0 socket --vga serial0 --onboot 1 --tags 'webapp;debian13;protec-gestion' --net0 "virtio,bridge=$BRIDGE,firewall=1"; owned_vm=1
qm importdisk "$VMID" "$IMAGE" "$STORAGE"; qm set "$VMID" --scsi0 "$STORAGE:vm-$VMID-disk-0,discard=on,ssd=1" --boot order=scsi0 --ide2 "$STORAGE:cloudinit" --ipconfig0 "ip=$STATIC_IP_CIDR,gw=$GATEWAY" --nameserver "$DNS_SERVER" --cicustom "user=local:snippets/${snippet##*/}"; qm resize "$VMID" scsi0 40G; qm start "$VMID"
for _ in {1..60}; do guest_exec cloud-init status --wait && booted=1 && break; sleep 5; done; [[ ${booted:-0} == 1 ]] || die 'guest agent/cloud-init timed out'; verify_guest
address=${STATIC_IP_CIDR%/*}; [[ -n ${SSH_HOST_FINGERPRINT:-} ]] || die 'SSH_HOST_FINGERPRINT is required'; host_keys=$(mktemp); ssh-keyscan -T 5 "$address" >"$host_keys" 2>/dev/null; fingerprint_before=$(ssh-keygen -lf "$host_keys" | head -1); [[ $fingerprint_before == "$SSH_HOST_FINGERPRINT" ]] || die 'SSH host fingerprint mismatch'
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$host_keys" "protec-deploy@$address" true || die 'SSH public-key access failed'
qm reboot "$VMID"; for _ in {1..60}; do verify_guest && rebooted=1 && break; sleep 5; done; [[ ${rebooted:-0} == 1 ]] || die 'post-reboot verification timed out'
fingerprint_after=$(ssh-keyscan -T 5 "$address" 2>/dev/null | ssh-keygen -lf - | head -1); [[ $fingerprint_after == "$SSH_HOST_FINGERPRINT" ]] || die 'SSH fingerprint changed across reboot'
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$host_keys" "protec-deploy@$address" true || die 'SSH access failed after reboot'; rm -f -- "$host_keys"
complete=1; printf '{"vmid":%s,"name":"%s","node":"%s","ip":"%s","ssh_fingerprint":"%s","status":"verified"}\n' "$VMID" "$VM_NAME" "$NODE" "$address" "$fingerprint_after"
