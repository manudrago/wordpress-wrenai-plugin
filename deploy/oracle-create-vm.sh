#!/usr/bin/env bash
#
# Create an Oracle Cloud Always Free ARM instance and install Wren AI + Ollama
# on it, unattended.
#
# Run this inside the Oracle Cloud Shell (the >_ icon in the console): the OCI
# CLI there is already authenticated as you, so nothing needs credentials.
#
#   bash oracle-create-vm.sh --wp-ip <YOUR_WORDPRESS_SERVER_IP>
#
# It builds, reusing anything that already exists:
#   - a VCN with an internet gateway, route rule, security list and subnet
#   - an SSH key pair, if you have none for this
#   - a VM.Standard.A1.Flex instance, 4 OCPU / 24 GB / 100 GB (Always Free)
#   - a cloud-init payload that installs Docker, Ollama and Wren AI on boot
#
# Capacity for A1 shapes runs out regularly; the script walks every
# availability domain and tells you plainly if the region has none right now.
set -euo pipefail

NAME="wren-ai"
OCPUS="4"
MEMORY_GB="24"
BOOT_GB="100"
MODEL="qwen2.5-coder:7b"
WP_IP=""
TOKEN=""
COMPARTMENT=""
SSH_KEY="${HOME}/.ssh/wren_ai_ed25519"
GATEWAY_PORT="8080"
REPO_RAW="https://raw.githubusercontent.com/manudrago/wordpress-wrenai-plugin/main/deploy"

usage() {
	cat <<'USAGE'
Usage: bash oracle-create-vm.sh [options]

  --wp-ip IP        Public IP of the server running WordPress. The Wren AI port
                    is opened to that address only. Without it nothing is opened
                    and you can add the rule later.
  --token SECRET    Bearer token the plugin must send. Generated if omitted.
  --model NAME      Ollama model (default qwen2.5-coder:7b; phi4:14b is better
                    and still fits in 24 GB).
  --name NAME       Instance display name (default wren-ai).
  --ocpus N         OCPUs (default 4, the whole Always Free ARM allowance).
  --memory GB       Memory (default 24).
  --boot-gb GB      Boot volume (default 100; Always Free covers 200 total).
  --compartment ID  Compartment OCID (default: the tenancy root).
  --ssh-key PATH    Private key path to use or create (default ~/.ssh/wren_ai_ed25519).
  -h, --help        This text.
USAGE
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--wp-ip) WP_IP="$2"; shift 2 ;;
		--token) TOKEN="$2"; shift 2 ;;
		--model) MODEL="$2"; shift 2 ;;
		--name) NAME="$2"; shift 2 ;;
		--ocpus) OCPUS="$2"; shift 2 ;;
		--memory) MEMORY_GB="$2"; shift 2 ;;
		--boot-gb) BOOT_GB="$2"; shift 2 ;;
		--compartment) COMPARTMENT="$2"; shift 2 ;;
		--ssh-key) SSH_KEY="$2"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown option: $1" >&2; usage; exit 1 ;;
	esac
done

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$1"; }
die()  { printf '\033[1;31m!!  %s\033[0m\n' "$1" >&2; exit 1; }

command -v oci >/dev/null 2>&1 || die "The OCI CLI is missing. Run this in the Oracle Cloud Shell."
command -v jq  >/dev/null 2>&1 || die "jq is missing. Run this in the Oracle Cloud Shell."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Identity
# ---------------------------------------------------------------------------

step "Account"

TENANCY="${OCI_TENANCY:-}"

if [[ -z "$TENANCY" ]]; then
	TENANCY="$(oci iam compartment list --access-level ACCESSIBLE --compartment-id-in-subtree true 2>/dev/null \
		| jq -r '.data[0]."compartment-id" // empty')"
fi

[[ -n "$TENANCY" ]] || die "Could not work out your tenancy OCID. Pass --compartment explicitly."

COMPARTMENT="${COMPARTMENT:-$TENANCY}"
REGION="$(oci iam region-subscription list | jq -r '.data[] | select(."is-home-region"==true) | ."region-name"' 2>/dev/null || true)"
REGION="${OCI_REGION:-${REGION:-unknown}}"

info "compartment: ${COMPARTMENT}"
info "region: ${REGION}"

[[ -n "$TOKEN" ]] || TOKEN="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32)"

# ---------------------------------------------------------------------------
# SSH key
# ---------------------------------------------------------------------------

step "SSH key"

if [[ -f "${SSH_KEY}.pub" ]]; then
	info "using existing ${SSH_KEY}"
else
	mkdir -p "$(dirname "$SSH_KEY")"
	ssh-keygen -t ed25519 -N "" -f "$SSH_KEY" -C "wren-ai" >/dev/null
	info "created ${SSH_KEY}"
fi

PUBLIC_KEY="$(cat "${SSH_KEY}.pub")"

# ---------------------------------------------------------------------------
# Network
# ---------------------------------------------------------------------------

step "Network"

VCN_ID="$(oci network vcn list --compartment-id "$COMPARTMENT" --display-name "${NAME}-vcn" \
	| jq -r '.data[0].id // empty')"

if [[ -z "$VCN_ID" ]]; then
	VCN_ID="$(oci network vcn create --compartment-id "$COMPARTMENT" \
		--cidr-blocks '["10.20.0.0/16"]' --display-name "${NAME}-vcn" --wait-for-state AVAILABLE \
		| jq -r '.data.id')"
	info "VCN created"
else
	info "VCN already there"
fi

IG_ID="$(oci network internet-gateway list --compartment-id "$COMPARTMENT" --vcn-id "$VCN_ID" \
	| jq -r '.data[0].id // empty')"

if [[ -z "$IG_ID" ]]; then
	IG_ID="$(oci network internet-gateway create --compartment-id "$COMPARTMENT" --vcn-id "$VCN_ID" \
		--is-enabled true --display-name "${NAME}-ig" --wait-for-state AVAILABLE \
		| jq -r '.data.id')"
	info "internet gateway created"
fi

RT_ID="$(oci network vcn get --vcn-id "$VCN_ID" | jq -r '.data."default-route-table-id"')"

oci network route-table update --rt-id "$RT_ID" --force \
	--route-rules "[{\"destination\":\"0.0.0.0/0\",\"destinationType\":\"CIDR_BLOCK\",\"networkEntityId\":\"${IG_ID}\"}]" \
	>/dev/null

info "default route points at the internet gateway"

SL_ID="$(oci network vcn get --vcn-id "$VCN_ID" | jq -r '.data."default-security-list-id"')"

# SSH from anywhere (the Cloud Shell address moves around), and the Wren AI
# gateway only for the WordPress server.
INGRESS='[{"protocol":"6","source":"0.0.0.0/0","isStateless":false,"tcpOptions":{"destinationPortRange":{"min":22,"max":22}}}'

if [[ -n "$WP_IP" ]]; then
	INGRESS="${INGRESS},{\"protocol\":\"6\",\"source\":\"${WP_IP}/32\",\"isStateless\":false,\"tcpOptions\":{\"destinationPortRange\":{\"min\":${GATEWAY_PORT},\"max\":${GATEWAY_PORT}}}}"
	info "port ${GATEWAY_PORT} will be open to ${WP_IP} only"
else
	warn "No --wp-ip: only SSH is opened. Add the rule when you know the address."
fi

INGRESS="${INGRESS}]"

oci network security-list update --security-list-id "$SL_ID" --force \
	--ingress-security-rules "$INGRESS" \
	--egress-security-rules '[{"protocol":"all","destination":"0.0.0.0/0","isStateless":false}]' \
	>/dev/null

SUBNET_ID="$(oci network subnet list --compartment-id "$COMPARTMENT" --vcn-id "$VCN_ID" --display-name "${NAME}-subnet" \
	| jq -r '.data[0].id // empty')"

if [[ -z "$SUBNET_ID" ]]; then
	SUBNET_ID="$(oci network subnet create --compartment-id "$COMPARTMENT" --vcn-id "$VCN_ID" \
		--cidr-block 10.20.1.0/24 --display-name "${NAME}-subnet" \
		--prohibit-public-ip-on-vnic false --wait-for-state AVAILABLE \
		| jq -r '.data.id')"
	info "subnet created"
else
	info "subnet already there"
fi

# ---------------------------------------------------------------------------
# Image
# ---------------------------------------------------------------------------

step "Ubuntu image for ARM"

IMAGE_ID="$(oci compute image list --compartment-id "$COMPARTMENT" \
	--operating-system "Canonical Ubuntu" --operating-system-version "24.04" \
	--shape "VM.Standard.A1.Flex" --sort-by TIMECREATED --sort-order DESC --limit 1 \
	| jq -r '.data[0].id // empty')"

if [[ -z "$IMAGE_ID" ]]; then
	IMAGE_ID="$(oci compute image list --compartment-id "$COMPARTMENT" \
		--operating-system "Canonical Ubuntu" --operating-system-version "22.04" \
		--shape "VM.Standard.A1.Flex" --sort-by TIMECREATED --sort-order DESC --limit 1 \
		| jq -r '.data[0].id // empty')"
fi

[[ -n "$IMAGE_ID" ]] || die "No Ubuntu ARM image found in this region."

info "$(oci compute image get --image-id "$IMAGE_ID" | jq -r '.data."display-name"')"

# ---------------------------------------------------------------------------
# cloud-init
# ---------------------------------------------------------------------------

step "Building the install payload"

fetch_or_local() {
	local name="$1"

	if [[ -f "${SCRIPT_DIR}/${name}" ]]; then
		cat "${SCRIPT_DIR}/${name}"
	else
		curl -fsSL "${REPO_RAW}/${name}" || die "Could not find ${name} next to this script, nor download it from ${REPO_RAW}. Upload the deploy/ folder to Cloud Shell (⋮ menu -> Upload) and run it from there."
	fi
}

INSTALLER_B64="$(fetch_or_local oracle-arm-install.sh | gzip -9 | base64 -w0)"
GENERATOR_B64="$(fetch_or_local make-ollama-config.py | gzip -9 | base64 -w0)"

ALLOW_ARG=""
[[ -n "$WP_IP" ]] && ALLOW_ARG="--allow-ip ${WP_IP}"

CLOUD_INIT="$(cat <<CLOUDINIT
#!/bin/bash
# Installs Wren AI + Ollama on first boot. Progress: /var/log/wren-install.log
exec > >(tee -a /var/log/wren-install.log) 2>&1
set -x

mkdir -p /opt/wren-deploy
echo '${INSTALLER_B64}' | base64 -d | gunzip > /opt/wren-deploy/oracle-arm-install.sh
echo '${GENERATOR_B64}' | base64 -d | gunzip > /opt/wren-deploy/make-ollama-config.py
chmod +x /opt/wren-deploy/oracle-arm-install.sh

# Oracle's Ubuntu images block everything but SSH by default; the installer
# adds its own rules on top.
bash /opt/wren-deploy/oracle-arm-install.sh --model '${MODEL}' --token '${TOKEN}' ${ALLOW_ARG}

date > /opt/wren-deploy/INSTALL_COMPLETE
CLOUDINIT
)"

USER_DATA="$(printf '%s' "$CLOUD_INIT" | base64 -w0)"

info "payload: $(( ${#USER_DATA} / 1024 )) KB of metadata"

[[ ${#USER_DATA} -lt 30000 ]] || die "cloud-init payload too large for instance metadata."

METADATA="$(jq -n --arg key "$PUBLIC_KEY" --arg data "$USER_DATA" \
	'{ssh_authorized_keys: $key, user_data: $data}')"

# ---------------------------------------------------------------------------
# Instance
# ---------------------------------------------------------------------------

step "Launching the instance (Always Free ARM)"

mapfile -t ADS < <(oci iam availability-domain list --compartment-id "$TENANCY" | jq -r '.data[].name')

[[ ${#ADS[@]} -gt 0 ]] || die "No availability domains returned."

INSTANCE_ID=""

for AD in "${ADS[@]}"; do
	info "trying ${AD}"

	set +e
	OUT="$(oci compute instance launch \
		--availability-domain "$AD" \
		--compartment-id "$COMPARTMENT" \
		--shape "VM.Standard.A1.Flex" \
		--shape-config "{\"ocpus\":${OCPUS},\"memoryInGBs\":${MEMORY_GB}}" \
		--image-id "$IMAGE_ID" \
		--subnet-id "$SUBNET_ID" \
		--assign-public-ip true \
		--display-name "$NAME" \
		--boot-volume-size-in-gbs "$BOOT_GB" \
		--metadata "$METADATA" \
		--wait-for-state RUNNING 2>&1)"
	RC=$?
	set -e

	if [[ $RC -eq 0 ]]; then
		INSTANCE_ID="$(printf '%s' "$OUT" | jq -r '.data.id' 2>/dev/null || true)"
		break
	fi

	if grep -qi "Out of host capacity" <<<"$OUT"; then
		warn "no free ARM capacity in ${AD} right now"
		continue
	fi

	if grep -qi "LimitExceeded\|QuotaExceeded" <<<"$OUT"; then
		die "Your Always Free ARM allowance is already used up (4 OCPU / 24 GB in total). Free an existing instance or lower --ocpus/--memory."
	fi

	printf '%s\n' "$OUT" >&2
	die "Launch failed."
done

[[ -n "$INSTANCE_ID" ]] || die "Every availability domain is out of ARM capacity. This is normal in busy regions: retry later, or try another region. Nothing was left half-built - re-running this script picks up where it stopped."

PUBLIC_IP="$(oci compute instance list-vnics --instance-id "$INSTANCE_ID" | jq -r '.data[0]."public-ip"')"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------

cat <<SUMMARY

$(printf '\033[1;32m')The VM is up. Wren AI is installing on it right now.$(printf '\033[0m')

  Instance : ${NAME} (${OCPUS} OCPU / ${MEMORY_GB} GB)
  Public IP: ${PUBLIC_IP}
  SSH      : ssh -i ${SSH_KEY} ubuntu@${PUBLIC_IP}
  Token    : ${TOKEN}

The install pulls a few GB of model weights, so give it 15-30 minutes. Watch it:

  ssh -i ${SSH_KEY} ubuntu@${PUBLIC_IP} 'tail -f /var/log/wren-install.log'

It is finished when this answers ok:

  ssh -i ${SSH_KEY} ubuntu@${PUBLIC_IP} 'curl -s localhost:5555/health'

Then, in WordPress under Wren AI -> Settings:

  Endpoint   : http://${PUBLIC_IP}:${GATEWAY_PORT}
  API prefix : /v1
  API key    : ${TOKEN}

and press "Test connection". Keep this token: it is the only thing standing
between the internet and your Wren AI service.
SUMMARY
