#!/usr/bin/env bash
#
# Wren AI + Ollama on an Oracle Cloud Always Free ARM instance.
#
# Installs Docker, Ollama (chat + embedding model), and the Wren AI service
# stack wired to talk to that local Ollama, so the WP Wren Dashboards plugin
# has an endpoint to call. Nothing here needs an OpenAI key.
#
# Tested target: Ubuntu 22.04 / 24.04 on VM.Standard.A1.Flex (arm64). Works on
# x86 too; the platform is detected.
#
#   sudo bash oracle-arm-install.sh --allow-ip <WORDPRESS_SERVER_IP> --token <SECRET>
#
# Re-running it is safe: every step checks before it acts.
set -euo pipefail

# ---------------------------------------------------------------------------
# Options
# ---------------------------------------------------------------------------

MODEL="qwen2.5-coder:7b"
EMBEDDER="nomic-embed-text"
EMBEDDING_DIM="768"
WREN_DIR="/opt/wrenai"
WREN_REF="legacy/v1"
PORT="5555"
GATEWAY_PORT="8080"
ALLOW_IP=""
TOKEN=""
SKIP_MODELS="no"

usage() {
	cat <<'USAGE'
Usage: sudo bash oracle-arm-install.sh [options]

  --allow-ip IP     Open the service only to this address (your WordPress server).
                    Without it the port stays closed to the internet.
  --token SECRET    Put an authenticating gateway in front of Wren AI. The plugin
                    sends this as its API key. Strongly recommended if the port
                    is reachable from anywhere but a private network.
  --model NAME      Ollama chat model (default: qwen2.5-coder:7b).
                    Roomier box? phi4:14b answers better and needs ~9 GB.
  --embedder NAME   Ollama embedding model (default: nomic-embed-text, 768 dims).
  --embedding-dim N Dimensions of that embedder (default: 768).
  --dir PATH        Where to keep the Wren AI checkout (default: /opt/wrenai).
  --skip-models     Don't pull the Ollama models (they are already there).
  -h, --help        This text.
USAGE
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--allow-ip) ALLOW_IP="$2"; shift 2 ;;
		--token) TOKEN="$2"; shift 2 ;;
		--model) MODEL="$2"; shift 2 ;;
		--embedder) EMBEDDER="$2"; shift 2 ;;
		--embedding-dim) EMBEDDING_DIM="$2"; shift 2 ;;
		--dir) WREN_DIR="$2"; shift 2 ;;
		--skip-models) SKIP_MODELS="yes"; shift ;;
		-h|--help) usage; exit 0 ;;
		*) echo "Unknown option: $1" >&2; usage; exit 1 ;;
	esac
done

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$1"; }
die()  { printf '\033[1;31m!!  %s\033[0m\n' "$1" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run this with sudo."

# Keep a port reachable from this machine and its containers, and from nowhere
# else. A plain "! -i docker0 -j DROP" also swallows loopback, which breaks
# every local client - the Ollama CLI and this script's own health checks
# included - so the decision lives in its own chain.
restrict_port() {
	local port="$1" chain="$2"

	command -v iptables >/dev/null 2>&1 || return 0

	# Drop the too-broad rule an earlier version of this script may have left.
	while iptables -C INPUT -p tcp --dport "$port" ! -i docker0 -j DROP 2>/dev/null; do
		iptables -D INPUT -p tcp --dport "$port" ! -i docker0 -j DROP
	done

	iptables -N "$chain" 2>/dev/null || iptables -F "$chain"
	iptables -A "$chain" -i lo -j ACCEPT
	iptables -A "$chain" -i docker0 -j ACCEPT
	iptables -A "$chain" -j DROP

	iptables -C INPUT -p tcp --dport "$port" -j "$chain" 2>/dev/null \
		|| iptables -I INPUT 1 -p tcp --dport "$port" -j "$chain"
}

# cloud-init runs scripts with no HOME at all, and the Ollama CLI panics on
# that ("$HOME is not defined") before it does anything useful.
export HOME="${HOME:-/root}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# 1. Sanity checks
# ---------------------------------------------------------------------------

step "Checking the machine"

ARCH="$(uname -m)"

case "$ARCH" in
	aarch64|arm64) PLATFORM="linux/arm64" ;;
	x86_64|amd64)  PLATFORM="linux/amd64" ;;
	*) die "Unsupported architecture: $ARCH" ;;
esac

RAM_MB="$(free -m | awk '/^Mem:/ {print $2}')"
DISK_GB="$(df -BG --output=avail / | tail -1 | tr -dc '0-9')"

info "architecture: $ARCH -> docker platform $PLATFORM"
info "memory: ${RAM_MB} MB, free disk: ${DISK_GB} GB"

if [[ "$RAM_MB" -lt 7000 ]]; then
	warn "Under 8 GB of RAM. The Wren AI stack alone wants ~4 GB and ${MODEL} adds several more."
	warn "On Oracle's Ampere A1 give the instance 4 OCPU / 24 GB, which is inside the Always Free allowance."
fi

[[ "$DISK_GB" -lt 25 ]] && warn "Under 25 GB free. Images plus models need roughly 20 GB."

# Ubuntu images ship a firewall that drops everything but SSH; note it now,
# open it deliberately later.
command -v iptables >/dev/null 2>&1 || warn "iptables missing: skipping firewall rules."

# ---------------------------------------------------------------------------
# 2. Packages
# ---------------------------------------------------------------------------

step "Installing base packages"

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq ca-certificates curl git jq python3-yaml netfilter-persistent iptables-persistent >/dev/null

info "done"

# ---------------------------------------------------------------------------
# 3. Docker
# ---------------------------------------------------------------------------

step "Installing Docker"

if command -v docker >/dev/null 2>&1; then
	info "already installed: $(docker --version)"
else
	curl -fsSL https://get.docker.com | sh >/dev/null
	info "installed: $(docker --version)"
fi

systemctl enable --now docker >/dev/null 2>&1 || true

docker compose version >/dev/null 2>&1 || die "The docker compose plugin is missing."

# Let the login user drive docker without sudo.
for candidate in ubuntu opc "${SUDO_USER:-}"; do
	if [[ -n "$candidate" ]] && id "$candidate" >/dev/null 2>&1; then
		usermod -aG docker "$candidate" || true
	fi
done

# ---------------------------------------------------------------------------
# 4. Ollama
# ---------------------------------------------------------------------------

step "Installing Ollama"

if command -v ollama >/dev/null 2>&1; then
	info "already installed: $(ollama --version 2>/dev/null | head -1)"
else
	curl -fsSL https://ollama.com/install.sh | sh >/dev/null
	info "installed"
fi

# Containers reach the host over the docker bridge, so Ollama must listen on
# more than loopback.
step "Letting containers reach Ollama"

mkdir -p /etc/systemd/system/ollama.service.d

cat > /etc/systemd/system/ollama.service.d/override.conf <<'UNIT'
[Service]
Environment="OLLAMA_HOST=0.0.0.0:11434"
# One model in memory at a time, kept warm: on CPU, reloading costs more than
# the RAM does.
Environment="OLLAMA_MAX_LOADED_MODELS=1"
Environment="OLLAMA_KEEP_ALIVE=30m"
UNIT

systemctl daemon-reload
systemctl enable --now ollama >/dev/null 2>&1 || true
systemctl restart ollama

for _ in $(seq 1 30); do
	curl -sf http://127.0.0.1:11434/api/version >/dev/null && break
	sleep 2
done

curl -sf http://127.0.0.1:11434/api/version >/dev/null || die "Ollama did not come up. Check: journalctl -u ollama -n 50"

info "listening on 0.0.0.0:11434"

# The bridge address the containers will use for the host.
BRIDGE_IP="$(ip -4 addr show docker0 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1 || true)"
BRIDGE_IP="${BRIDGE_IP:-172.17.0.1}"
OLLAMA_URL="http://${BRIDGE_IP}:11434"

info "containers will call Ollama at ${OLLAMA_URL}"

# Ollama has no auth of its own: only this host and its containers may talk
# to it.
restrict_port 11434 WREN_OLLAMA

info "port 11434 closed to everything but localhost and the docker bridge"

if [[ "$SKIP_MODELS" == "no" ]]; then
	step "Pulling models (several GB, this is the slow part)"

	info "chat model: ${MODEL}"
	ollama pull "$MODEL"

	info "embedding model: ${EMBEDDER}"
	ollama pull "$EMBEDDER"

	# The pull talks to the daemon, so the weights land in the service's own
	# store: confirm the daemon really has them before wiring Wren AI to it.
	ollama list | grep -q "$(cut -d: -f1 <<<"$MODEL")" \
		|| die "Ollama does not list ${MODEL} after pulling it. Check: journalctl -u ollama -n 50"

	ollama list | grep -q "$(cut -d: -f1 <<<"$EMBEDDER")" \
		|| die "Ollama does not list ${EMBEDDER} after pulling it."

	info "models ready"
fi

# ---------------------------------------------------------------------------
# 5. Wren AI
# ---------------------------------------------------------------------------

step "Fetching Wren AI (${WREN_REF})"

if [[ -d "$WREN_DIR/.git" ]]; then
	git -C "$WREN_DIR" fetch --depth 1 origin "$WREN_REF" >/dev/null 2>&1
	git -C "$WREN_DIR" checkout -q FETCH_HEAD
	info "updated $WREN_DIR"
else
	git clone --depth 1 -b "$WREN_REF" https://github.com/Canner/WrenAI.git "$WREN_DIR" >/dev/null 2>&1
	info "cloned into $WREN_DIR"
fi

DOCKER_DIR="${WREN_DIR}/docker"

[[ -f "${DOCKER_DIR}/.env.example" ]] || die "Unexpected checkout: ${DOCKER_DIR}/.env.example is missing."

step "Writing the environment"

if [[ ! -f "${DOCKER_DIR}/.env" ]]; then
	cp "${DOCKER_DIR}/.env.example" "${DOCKER_DIR}/.env"
fi

# The stock file pins x86; on Ampere that pulls images that cannot run.
sed -i "s|^PLATFORM=.*|PLATFORM=${PLATFORM}|" "${DOCKER_DIR}/.env"
sed -i "s|^AI_SERVICE_FORWARD_PORT=.*|AI_SERVICE_FORWARD_PORT=${PORT}|" "${DOCKER_DIR}/.env"

# LiteLLM insists on a key being present even when the endpoint ignores it.
grep -q '^LLM_OPENAI_API_KEY=' "${DOCKER_DIR}/.env" \
	|| echo 'LLM_OPENAI_API_KEY=ollama' >> "${DOCKER_DIR}/.env"

grep -q '^TELEMETRY_ENABLED=' "${DOCKER_DIR}/.env" \
	&& sed -i 's|^TELEMETRY_ENABLED=.*|TELEMETRY_ENABLED=false|' "${DOCKER_DIR}/.env"

if ! grep -q '^USER_UUID=..' "${DOCKER_DIR}/.env"; then
	sed -i "s|^USER_UUID=.*|USER_UUID=$(cat /proc/sys/kernel/random/uuid)|" "${DOCKER_DIR}/.env"
fi

info "PLATFORM=${PLATFORM}, AI service on port ${PORT}"

step "Generating config.yaml for Ollama"

[[ -f "${SCRIPT_DIR}/make-ollama-config.py" ]] || die "make-ollama-config.py is missing next to this script."

if [[ -f "${DOCKER_DIR}/config.yaml" ]]; then
	cp "${DOCKER_DIR}/config.yaml" "${DOCKER_DIR}/config.yaml.bak.$(date +%s)"
	info "kept a backup of the previous config.yaml"
fi

python3 "${SCRIPT_DIR}/make-ollama-config.py" \
	"${DOCKER_DIR}/config.example.yaml" \
	--ollama-url "$OLLAMA_URL" \
	--model "$MODEL" \
	--embedder "$EMBEDDER" \
	--embedding-dim "$EMBEDDING_DIM" \
	--output "${DOCKER_DIR}/config.yaml"

step "Starting the stack"

cd "$DOCKER_DIR"

# The plugin runs its own SQL and only needs the AI service (and the vector
# store it indexes into). The UI, engine and ibis containers would idle at a
# gigabyte or so of RAM for nothing.
docker compose up -d qdrant wren-ai-service

info "waiting for the service to answer"

READY="no"

for _ in $(seq 1 60); do
	if curl -sf "http://127.0.0.1:${PORT}/health" >/dev/null; then
		READY="yes"
		break
	fi

	sleep 5
done

[[ "$READY" == "yes" ]] || {
	warn "The service is not answering yet. Watch it with:"
	warn "  docker compose -f ${DOCKER_DIR}/docker-compose.yaml logs -f wren-ai-service"
}

# ---------------------------------------------------------------------------
# 6. Exposure
# ---------------------------------------------------------------------------

SERVICE_PORT="$PORT"

if [[ -n "$TOKEN" ]]; then
	step "Putting an authenticating gateway in front"

	# Wren AI has no authentication of its own. nginx checks the same bearer
	# token the plugin already sends in its API key field.
	docker rm -f wren-gateway >/dev/null 2>&1 || true

	mkdir -p /etc/wren-gateway

	cat > /etc/wren-gateway/nginx.conf <<NGINX
events { worker_connections 256; }

http {
	map \$http_authorization \$is_authorised {
		default 0;
		"Bearer ${TOKEN}" 1;
	}

	server {
		listen ${GATEWAY_PORT};

		# Long questions on a CPU model: do not cut them off.
		proxy_read_timeout 600s;
		proxy_send_timeout 600s;
		client_max_body_size 32m;

		location / {
			if (\$is_authorised = 0) {
				return 401;
			}

			proxy_pass http://${BRIDGE_IP}:${SERVICE_PORT};
			proxy_set_header Host \$host;
		}
	}
}
NGINX

	docker run -d --name wren-gateway --restart unless-stopped \
		-p "${GATEWAY_PORT}:${GATEWAY_PORT}" \
		-v /etc/wren-gateway/nginx.conf:/etc/nginx/nginx.conf:ro \
		nginx:alpine >/dev/null

	info "gateway on port ${GATEWAY_PORT}, token required"

	# With the gateway in place, the raw service must not be reachable from
	# outside - but the health checks still run on this machine.
	restrict_port "${PORT}" WREN_SERVICE

	PUBLIC_PORT="$GATEWAY_PORT"
else
	PUBLIC_PORT="$PORT"
fi

step "Firewall"

if command -v iptables >/dev/null 2>&1; then
	if [[ -n "$ALLOW_IP" ]]; then
		iptables -C INPUT -p tcp -s "$ALLOW_IP" --dport "$PUBLIC_PORT" -j ACCEPT 2>/dev/null \
			|| iptables -I INPUT 1 -p tcp -s "$ALLOW_IP" --dport "$PUBLIC_PORT" -j ACCEPT

		info "port ${PUBLIC_PORT} open to ${ALLOW_IP} only"
	else
		warn "No --allow-ip given: the port stays closed from outside this machine."
		warn "Re-run with --allow-ip <your WordPress server IP> when you know it."
	fi

	netfilter-persistent save >/dev/null 2>&1 || true
fi

PUBLIC_IP="$(curl -s --max-time 5 https://api.ipify.org || echo 'YOUR_VM_IP')"

# ---------------------------------------------------------------------------
# 7. What to do next
# ---------------------------------------------------------------------------

cat <<SUMMARY

$(printf '\033[1;32m')Done.$(printf '\033[0m')

  Wren AI health : $(curl -sf "http://127.0.0.1:${PORT}/health" >/dev/null && echo 'ok' || echo 'not answering yet')
  Chat model     : ${MODEL}
  Embedder       : ${EMBEDDER} (${EMBEDDING_DIM} dims)
  Stack          : ${DOCKER_DIR}

In WordPress, under Wren AI -> Settings:

  Endpoint   : http://${PUBLIC_IP}:${PUBLIC_PORT}
  API prefix : /v1
  API key    : ${TOKEN:-(none - the port is unauthenticated, keep it private)}
  Timeout    : 30 seconds

Then Wren AI -> Data & schema: pick the tables, write the business context,
press "Build & deploy schema", and wait for it to say finished. The first
deploy embeds every column through Ollama, so on CPU give it a few minutes.

Useful afterwards:

  docker compose -f ${DOCKER_DIR}/docker-compose.yaml ps
  docker compose -f ${DOCKER_DIR}/docker-compose.yaml logs -f wren-ai-service
  journalctl -u ollama -f
  ollama ps                      # what is loaded, and how much RAM it holds

SUMMARY
