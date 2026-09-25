#!/usr/bin/env bash
#
# Wren AI for the DataChat AI plugin, on any Ubuntu/Debian machine.
#
# Installs Docker and the Wren AI service stack, wires it to a model - a free
# hosted one, or a local Ollama - and puts an authenticating gateway in front,
# so the plugin has an endpoint to call.
#
# Three shapes, all of them free to run:
#
#   # Free hosted brain (Google AI Studio). Runs on a 2 GB box, no model
#   # download, answers in seconds. Get a key at aistudio.google.com/apikey
#   sudo bash install-wren-ai.sh --llm google --llm-api-key AIza... \
#       --quick-tunnel --token "$(openssl rand -hex 16)"
#
#   # Everything local, nothing leaves the machine. Wants 8 GB of RAM.
#   sudo bash install-wren-ai.sh --llm ollama \
#       --gateway-port 80 --token SECRET --allow-ip <WORDPRESS_SERVER_IP>
#
#   # Hosted chat model, local embeddings (Groq has no embeddings endpoint).
#   sudo bash install-wren-ai.sh --llm groq --llm-api-key gsk_... --quick-tunnel
#
# Re-running it is safe: every step checks before it acts.
set -euo pipefail

# ---------------------------------------------------------------------------
# Options
# ---------------------------------------------------------------------------

LLM_PROVIDER="ollama"
EMBED_PROVIDER=""
LLM_API_KEY=""
EMBED_API_KEY=""
MODEL=""
EMBEDDER=""
EMBEDDING_DIM=""
WREN_DIR="/opt/wrenai"
WREN_REF="legacy/v1"
PORT="5555"
GATEWAY_PORT="8080"
ALLOW_IP=""
TOKEN=""
SKIP_MODELS="no"
DRY_RUN="no"
TUNNEL_TOKEN=""
QUICK_TUNNEL="no"
PAIR_URL=""
PAIR_CODE=""

usage() {
	cat <<'USAGE'
Usage: sudo bash install-wren-ai.sh [options]

Model
  --llm NAME        Where the thinking happens: ollama (local, free, needs
                    8 GB) | google (AI Studio, free tier) | groq (free tier)
                    | openai (paid). Default: ollama.
  --llm-api-key KEY Key for a hosted --llm. Required unless --llm ollama.
  --model NAME      Override the model. Defaults per provider:
                    ollama qwen2.5-coder:7b | google gemini-3.6-flash
                    groq llama-3.3-70b-versatile | openai gpt-4.1-mini
  --embedder-provider NAME
                    ollama | google | openai. Defaults to --llm, except with
                    groq (no embeddings endpoint), which falls back to a local
                    Ollama embedder - a 274 MB model, comfortable on CPU.
  --embedder NAME   Override the embedding model.
  --embedder-api-key KEY
                    Only when the embedder is hosted somewhere else than --llm.
  --embedding-dim N Dimensions of the embedder, if you override it.
  --skip-models     Don't pull the Ollama models (they are already there).

Reaching it from WordPress
  --token SECRET    Put an authenticating gateway in front of Wren AI. The
                    plugin sends this as its API key. Always use one.
  --quick-tunnel    Publish through a free Cloudflare quick tunnel: an https://
                    address, no open ports, no domain, no account. The address
                    changes whenever the tunnel restarts.
  --tunnel-token T  Same, but through a named Cloudflare tunnel you created in
                    the dashboard, which keeps its hostname. Point it at
                    http://wren-gateway:<gateway port>.
  --allow-ip IP     Direct exposure instead of a tunnel: open the gateway port
                    to this address only (your WordPress server).
  --gateway-port N  Port the gateway listens on (default: 8080). Use 80 when
                    the WordPress host only allows standard ports outbound -
                    plenty of shared hosting does. Ignored when tunnelling.

Telling WordPress about it
  --pair-url URL    The plugin's pairing route, https://<site>/wp-json/wren-ai/v1/pair.
  --pair-code CODE  The code that route is expecting. Generate both in
                    wp-admin under Wren AI -> Settings -> "Connect a server
                    automatically", which prints this whole command for you.
                    With a quick tunnel a timer also reports the new address
                    whenever the tunnel restarts, so the setup survives it.

Other
  --dry-run         Print what would be installed and stop, without touching
                    the machine. Works without sudo.
  --dir PATH        Where to keep the Wren AI checkout (default: /opt/wrenai).
  -h, --help        This text.
USAGE
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--llm) LLM_PROVIDER="$2"; shift 2 ;;
		--llm-api-key) LLM_API_KEY="$2"; shift 2 ;;
		--embedder-provider) EMBED_PROVIDER="$2"; shift 2 ;;
		--embedder-api-key) EMBED_API_KEY="$2"; shift 2 ;;
		--allow-ip) ALLOW_IP="$2"; shift 2 ;;
		--token) TOKEN="$2"; shift 2 ;;
		--model) MODEL="$2"; shift 2 ;;
		--embedder) EMBEDDER="$2"; shift 2 ;;
		--embedding-dim) EMBEDDING_DIM="$2"; shift 2 ;;
		--gateway-port) GATEWAY_PORT="$2"; shift 2 ;;
		--tunnel-token) TUNNEL_TOKEN="$2"; shift 2 ;;
		--quick-tunnel) QUICK_TUNNEL="yes"; shift ;;
		--pair-url) PAIR_URL="$2"; shift 2 ;;
		--pair-code) PAIR_CODE="$2"; shift 2 ;;
		--dir) WREN_DIR="$2"; shift 2 ;;
		--skip-models) SKIP_MODELS="yes"; shift ;;
		--dry-run) DRY_RUN="yes"; shift ;;
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

[[ "$(id -u)" -eq 0 || "$DRY_RUN" == "yes" ]] || die "Run this with sudo."

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
	# Connections that are already running must survive the rules being
	# rewritten, or a long download dies halfway through.
	iptables -A "$chain" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true
	iptables -A "$chain" -i lo -j ACCEPT
	iptables -A "$chain" -i docker0 -j ACCEPT
	# Compose creates its own bridge (br-xxxx), and containers on it are not
	# docker0 traffic: without this the service cannot reach Ollama at all.
	iptables -A "$chain" -i br+ -j ACCEPT
	iptables -A "$chain" -j DROP

	iptables -C INPUT -p tcp --dport "$port" -j "$chain" 2>/dev/null \
		|| iptables -I INPUT 1 -p tcp --dport "$port" -j "$chain"
}

# Set a variable in the compose .env, replacing whatever was there.
set_env() {
	local key="$1" value="$2" file="$3"

	if grep -q "^${key}=" "$file"; then
		# The value can hold slashes and other sed metacharacters, so rewrite
		# the line rather than substituting inside it.
		grep -v "^${key}=" "$file" > "${file}.tmp"
		mv "${file}.tmp" "$file"
	fi

	printf '%s=%s\n' "$key" "$value" >> "$file"
}

# cloud-init runs scripts with no HOME at all, and the Ollama CLI panics on
# that ("$HOME is not defined") before it does anything useful.
export HOME="${HOME:-/root}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# 1. Work out what we are being asked to build
# ---------------------------------------------------------------------------

step "Planning"

case "$LLM_PROVIDER" in
	ollama|google|groq|openai) ;;
	*) die "Unknown --llm '${LLM_PROVIDER}'. Pick one of: ollama, google, groq, openai." ;;
esac

# Groq serves chat only, so its embeddings have to come from somewhere else.
if [[ -z "$EMBED_PROVIDER" ]]; then
	if [[ "$LLM_PROVIDER" == "groq" ]]; then
		EMBED_PROVIDER="ollama"
	else
		EMBED_PROVIDER="$LLM_PROVIDER"
	fi
fi

case "$EMBED_PROVIDER" in
	ollama|google|openai) ;;
	*) die "Unknown --embedder-provider '${EMBED_PROVIDER}'. Pick one of: ollama, google, openai." ;;
esac

# Not the docker0 gateway: the service runs on the compose network, whose
# gateway is a different address entirely. host-gateway (set in the compose
# override) resolves to the host from whichever bridge the container is on.
OLLAMA_URL="http://host.docker.internal:11434"

LLM_API_BASE=""
LLM_KEY_NAME=""

case "$LLM_PROVIDER" in
	ollama)
		MODEL="${MODEL:-qwen2.5-coder:7b}"
		LLM_MODEL_ID="ollama_chat/${MODEL}"
		LLM_API_BASE="$OLLAMA_URL"
		;;
	google)
		MODEL="${MODEL:-gemini-3.6-flash}"
		LLM_MODEL_ID="gemini/${MODEL}"
		LLM_KEY_NAME="GEMINI_API_KEY"
		;;
	groq)
		MODEL="${MODEL:-llama-3.3-70b-versatile}"
		LLM_MODEL_ID="groq/${MODEL}"
		LLM_API_BASE="https://api.groq.com/openai/v1"
		LLM_KEY_NAME="GROQ_API_KEY"
		;;
	openai)
		MODEL="${MODEL:-gpt-4.1-mini}"
		LLM_MODEL_ID="openai/${MODEL}"
		LLM_KEY_NAME="OPENAI_API_KEY"
		;;
esac

EMBED_API_BASE=""
EMBED_KEY_NAME=""

case "$EMBED_PROVIDER" in
	ollama)
		EMBEDDER="${EMBEDDER:-nomic-embed-text}"
		# Ollama's native LiteLLM route has a long-standing embeddings bug;
		# its OpenAI-compatible endpoint works.
		EMBED_MODEL_ID="openai/${EMBEDDER}"
		EMBED_API_BASE="${OLLAMA_URL}/v1"
		EMBED_KEY_NAME="LLM_OPENAI_API_KEY"
		EMBEDDING_DIM="${EMBEDDING_DIM:-768}"
		;;
	google)
		EMBEDDER="${EMBEDDER:-text-embedding-004}"
		EMBED_MODEL_ID="gemini/${EMBEDDER}"
		EMBED_KEY_NAME="GEMINI_API_KEY"
		EMBEDDING_DIM="${EMBEDDING_DIM:-768}"
		;;
	openai)
		EMBEDDER="${EMBEDDER:-text-embedding-3-large}"
		EMBED_MODEL_ID="openai/${EMBEDDER}"
		EMBED_KEY_NAME="OPENAI_API_KEY"
		EMBEDDING_DIM="${EMBEDDING_DIM:-3072}"
		;;
esac

[[ "$LLM_PROVIDER" == "ollama" || -n "$LLM_API_KEY" ]] \
	|| die "--llm ${LLM_PROVIDER} needs --llm-api-key. Google AI Studio hands one out free at https://aistudio.google.com/apikey"

# One key covers both when they are the same provider.
if [[ -z "$EMBED_API_KEY" && "$EMBED_PROVIDER" == "$LLM_PROVIDER" ]]; then
	EMBED_API_KEY="$LLM_API_KEY"
fi

[[ "$EMBED_PROVIDER" == "ollama" || -n "$EMBED_API_KEY" ]] \
	|| die "The ${EMBED_PROVIDER} embedder needs --embedder-api-key."

NEEDS_OLLAMA="no"
[[ "$LLM_PROVIDER" == "ollama" || "$EMBED_PROVIDER" == "ollama" ]] && NEEDS_OLLAMA="yes"

info "chat model    : ${LLM_MODEL_ID}"
info "embedder      : ${EMBED_MODEL_ID} (${EMBEDDING_DIM} dims)"
info "local Ollama  : ${NEEDS_OLLAMA}"

if [[ -n "$TUNNEL_TOKEN" ]]; then
	EXPOSURE="named tunnel"
elif [[ "$QUICK_TUNNEL" == "yes" ]]; then
	EXPOSURE="quick tunnel"
else
	EXPOSURE="port ${GATEWAY_PORT}${ALLOW_IP:+ (open to ${ALLOW_IP} only)}"
fi

info "reachable via : ${EXPOSURE}"

if [[ -n "$PAIR_URL" && -n "$PAIR_CODE" ]]; then
	info "reports back to: ${PAIR_URL}"
elif [[ -n "$PAIR_URL" || -n "$PAIR_CODE" ]]; then
	die "--pair-url and --pair-code go together. wp-admin prints both."
fi

[[ -n "$TOKEN" ]] || warn "No --token: anyone who finds the address can query your data. Pass one."

if [[ "$DRY_RUN" == "yes" ]]; then
	info "dry run: nothing was changed on this machine."

	exit 0
fi

# ---------------------------------------------------------------------------
# 2. Sanity checks
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

if [[ "$LLM_PROVIDER" == "ollama" ]]; then
	if [[ "$RAM_MB" -lt 7000 ]]; then
		warn "Under 8 GB of RAM. The Wren AI stack alone wants ~4 GB and ${MODEL} adds several more."
		warn "Either give this machine more memory, or run the model elsewhere:"
		warn "  --llm google --llm-api-key <key from https://aistudio.google.com/apikey>"
	fi

	[[ "$DISK_GB" -lt 25 ]] && warn "Under 25 GB free. Images plus models need roughly 20 GB."
else
	[[ "$RAM_MB" -lt 2200 ]] && warn "Under 2.5 GB of RAM. The service and its vector store want about that much."
	[[ "$DISK_GB" -lt 12 ]] && warn "Under 12 GB free. The images need roughly 8 GB."
fi

command -v iptables >/dev/null 2>&1 || warn "iptables missing: skipping firewall rules."

# ---------------------------------------------------------------------------
# 3. Packages
# ---------------------------------------------------------------------------

step "Installing base packages"

export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq ca-certificates curl git jq python3-yaml netfilter-persistent iptables-persistent >/dev/null

info "done"

# ---------------------------------------------------------------------------
# 4. Docker
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
for candidate in ubuntu opc debian "${SUDO_USER:-}"; do
	if [[ -n "$candidate" ]] && id "$candidate" >/dev/null 2>&1; then
		usermod -aG docker "$candidate" || true
	fi
done

# ---------------------------------------------------------------------------
# 5. Ollama, when something local needs it
# ---------------------------------------------------------------------------

if [[ "$NEEDS_OLLAMA" == "yes" ]]; then
	step "Installing Ollama"

	if command -v ollama >/dev/null 2>&1; then
		info "already installed: $(ollama --version 2>/dev/null | head -1)"
	else
		curl -fsSL https://ollama.com/install.sh | sh >/dev/null
		info "installed"
	fi

	# Containers reach the host over the docker bridge, so Ollama must listen
	# on more than loopback.
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

	curl -sf http://127.0.0.1:11434/api/version >/dev/null \
		|| die "Ollama did not come up. Check: journalctl -u ollama -n 50"

	info "listening on 0.0.0.0:11434"

	if [[ "$SKIP_MODELS" == "no" ]]; then
		step "Pulling models"

		if [[ "$LLM_PROVIDER" == "ollama" ]]; then
			info "chat model: ${MODEL} (several GB, this is the slow part)"
			ollama pull "$MODEL"

			# The pull talks to the daemon, so the weights land in the
			# service's own store: confirm the daemon really has them.
			ollama list | grep -q "$(cut -d: -f1 <<<"$MODEL")" \
				|| die "Ollama does not list ${MODEL} after pulling it. Check: journalctl -u ollama -n 50"
		fi

		if [[ "$EMBED_PROVIDER" == "ollama" ]]; then
			info "embedding model: ${EMBEDDER}"
			ollama pull "$EMBEDDER"

			ollama list | grep -q "$(cut -d: -f1 <<<"$EMBEDDER")" \
				|| die "Ollama does not list ${EMBEDDER} after pulling it."
		fi

		info "models ready"
	fi

	# Ollama has no auth of its own: only this host and its containers may talk
	# to it. This comes after the pulls on purpose - a firewall change
	# mid-download kills the transfer.
	restrict_port 11434 WREN_OLLAMA

	info "port 11434 closed to everything but localhost and the docker bridge"
fi

# ---------------------------------------------------------------------------
# 6. Wren AI
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

ENV_FILE="${DOCKER_DIR}/.env"

# The stock file pins x86; on Ampere that pulls images that cannot run.
set_env PLATFORM "$PLATFORM" "$ENV_FILE"
set_env AI_SERVICE_FORWARD_PORT "$PORT" "$ENV_FILE"

# The entrypoint only waits for wren-ui when SHOULD_FORCE_DEPLOY is set, and
# that wait exits 1 after 60 seconds, so with restart: on-failure the service
# would cycle roughly every minute. The plugin deploys the model itself through
# the API, so the startup force-deploy has nothing to do here.
set_env SHOULD_FORCE_DEPLOY "" "$ENV_FILE"

set_env TELEMETRY_ENABLED false "$ENV_FILE"

# LiteLLM insists on a key being present even when the endpoint ignores it.
set_env LLM_OPENAI_API_KEY ollama "$ENV_FILE"

# The whole .env is handed to the service container (env_file in the compose
# file), so the provider keys reach LiteLLM from here.
[[ -n "$LLM_KEY_NAME" && -n "$LLM_API_KEY" ]] && set_env "$LLM_KEY_NAME" "$LLM_API_KEY" "$ENV_FILE"
[[ -n "$EMBED_KEY_NAME" && -n "$EMBED_API_KEY" ]] && set_env "$EMBED_KEY_NAME" "$EMBED_API_KEY" "$ENV_FILE"

chmod 600 "$ENV_FILE"

if ! grep -q '^USER_UUID=..' "$ENV_FILE"; then
	set_env USER_UUID "$(cat /proc/sys/kernel/random/uuid)" "$ENV_FILE"
fi

info "PLATFORM=${PLATFORM}, AI service on port ${PORT}"

step "Generating config.yaml"

[[ -f "${SCRIPT_DIR}/make-wren-config.py" ]] || die "make-wren-config.py is missing next to this script."

if [[ -f "${DOCKER_DIR}/config.yaml" ]]; then
	cp "${DOCKER_DIR}/config.yaml" "${DOCKER_DIR}/config.yaml.bak.$(date +%s)"
	info "kept a backup of the previous config.yaml"
fi

python3 "${SCRIPT_DIR}/make-wren-config.py" \
	"${DOCKER_DIR}/config.example.yaml" \
	--llm-model "$LLM_MODEL_ID" \
	--llm-api-base "$LLM_API_BASE" \
	--llm-api-key-name "$LLM_KEY_NAME" \
	--embed-model "$EMBED_MODEL_ID" \
	--embed-api-base "$EMBED_API_BASE" \
	--embed-api-key-name "$EMBED_KEY_NAME" \
	--embedding-dim "$EMBEDDING_DIM" \
	--engine-url "http://wren-sql-validator:3000" \
	--output "${DOCKER_DIR}/config.yaml"

step "Starting the stack"

cd "$DOCKER_DIR"

# Docker publishes container ports through PREROUTING/FORWARD, which never
# passes the INPUT chain, so a host firewall rule cannot protect a published
# port. Publish the service on loopback instead and let the gateway reach it
# over the compose network: then the only way in from outside is the gateway.
cat > "${DOCKER_DIR}/docker-compose.override.yaml" <<OVERRIDE
services:
  wren-ai-service:
    extra_hosts:
      - "host.docker.internal:host-gateway"
    ports: !override
      - "127.0.0.1:${PORT}:${PORT}"
OVERRIDE

# The plugin runs its own SQL and only needs the AI service (and the vector
# store it indexes into). The UI, engine and ibis containers would idle at a
# gigabyte or so of RAM for nothing. Recreate rather than start, so a changed
# .env or config.yaml actually reaches the running container.
docker compose up -d --force-recreate qdrant wren-ai-service

WREN_NET="$(docker network ls --format '{{.Name}}' | grep -m1 -E '(^|_)wren$' || echo bridge)"

if [[ "$NEEDS_OLLAMA" == "yes" ]]; then
	# The failure this catches is silent otherwise: indexing and every question
	# die inside the embedder with "Connection error".
	if ! docker run --rm --add-host host.docker.internal:host-gateway \
		--network "$WREN_NET" \
		curlimages/curl:latest -sf -m 10 http://host.docker.internal:11434/api/version >/dev/null 2>&1; then
		warn "containers cannot reach Ollama on the host - questions will fail in the embedder."
		warn "Check the firewall rules for port 11434: iptables -S WREN_OLLAMA"
	else
		info "containers can reach Ollama"
	fi
fi

# Wren AI dry-runs every statement it writes through the "engine" before
# returning it, and treats a failure as "no relevant SQL". That engine is the
# UI we deliberately do not run, so questions died there. The plugin executes
# the SQL itself, behind its own guard, so all this needs to do is answer the
# dry-run - which is exactly what this 10 MB container does.
step "Starting the SQL validator"

mkdir -p /etc/wren-sql-validator

cat > /etc/wren-sql-validator/nginx.conf <<'VALIDATOR'
events { worker_connections 64; }

http {
	server {
		listen 3000;

		# The shape WrenUI.execute_sql expects back from a PreviewSql mutation.
		location /api/graphql {
			default_type application/json;
			return 200 '{"data":{"previewSql":{"columns":["ok"],"data":[[1]]}}}';
		}

		location / {
			default_type application/json;
			return 200 '{"status":"ok"}';
		}
	}
}
VALIDATOR

docker rm -f wren-sql-validator >/dev/null 2>&1 || true

docker run -d --name wren-sql-validator --restart unless-stopped \
	--network "$WREN_NET" \
	--network-alias wren-ui \
	-v /etc/wren-sql-validator/nginx.conf:/etc/nginx/nginx.conf:ro \
	nginx:alpine >/dev/null

info "SQL validator up (also answers to the name wren-ui)"

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
# 7. Exposure
# ---------------------------------------------------------------------------

TUNNELLING="no"
[[ -n "$TUNNEL_TOKEN" || "$QUICK_TUNNEL" == "yes" ]] && TUNNELLING="yes"

if [[ -n "$TOKEN" ]]; then
	step "Putting an authenticating gateway in front"

	# Wren AI has no authentication of its own. nginx checks the same bearer
	# token the plugin already sends in its API key field.
	# wren-gateway-80 is a hand-made container from troubleshooting: it carries
	# an older config and sits outside the compose network, so it answers 502
	# once the service stops listening on the bridge address.
	docker rm -f wren-gateway wren-gateway-80 >/dev/null 2>&1 || true

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

			proxy_pass http://wren-ai-service:${PORT};
			proxy_set_header Host \$host;
		}
	}
}
NGINX

	# Same network as the service, so it can be addressed by name now that it
	# no longer listens on the bridge address. Behind a tunnel nothing needs
	# to be published to the host at all.
	if [[ "$TUNNELLING" == "yes" ]]; then
		docker run -d --name wren-gateway --restart unless-stopped \
			--network "$WREN_NET" \
			-v /etc/wren-gateway/nginx.conf:/etc/nginx/nginx.conf:ro \
			nginx:alpine >/dev/null

		info "gateway up on the internal network, token required, no host port open"
	else
		docker run -d --name wren-gateway --restart unless-stopped \
			--network "$WREN_NET" \
			-p "${GATEWAY_PORT}:${GATEWAY_PORT}" \
			-v /etc/wren-gateway/nginx.conf:/etc/nginx/nginx.conf:ro \
			nginx:alpine >/dev/null

		info "gateway on port ${GATEWAY_PORT}, token required"
	fi

	# No INPUT rule for the service port on purpose: the compose override
	# publishes it on 127.0.0.1, which is what actually keeps it private.

	UPSTREAM="wren-gateway:${GATEWAY_PORT}"
	PUBLIC_PORT="$GATEWAY_PORT"
else
	# Without a token there is no gateway, and the service listens on loopback
	# only - so nothing outside this machine can reach it. Say so, because the
	# summary below otherwise reads like a working setup.
	warn "No --token given: no gateway was set up, and the service is published on 127.0.0.1 only."
	warn "Nothing outside this machine can reach it. Re-run with --token <secret> to restore the gateway."

	docker rm -f wren-gateway wren-gateway-80 >/dev/null 2>&1 || true

	UPSTREAM="wren-ai-service:${PORT}"
	PUBLIC_PORT="$PORT"
fi

# ---------------------------------------------------------------------------
# 8. Cloudflare tunnel, when asked for one
# ---------------------------------------------------------------------------

PUBLIC_URL=""

if [[ "$TUNNELLING" == "yes" ]]; then
	step "Publishing through Cloudflare"

	docker rm -f wren-tunnel >/dev/null 2>&1 || true

	if [[ -n "$TUNNEL_TOKEN" ]]; then
		docker run -d --name wren-tunnel --restart unless-stopped \
			--network "$WREN_NET" \
			cloudflare/cloudflared:latest tunnel --no-autoupdate run --token "$TUNNEL_TOKEN" >/dev/null

		info "named tunnel running"
		info "in the Cloudflare dashboard point its public hostname at http://${UPSTREAM}"
	else
		# A quick tunnel needs no account and no domain: Cloudflare hands out a
		# random https://<name>.trycloudflare.com and terminates TLS there, so
		# the token never crosses the internet in clear. The address changes
		# every time this container restarts.
		docker run -d --name wren-tunnel --restart unless-stopped \
			--network "$WREN_NET" \
			cloudflare/cloudflared:latest tunnel --no-autoupdate \
			--url "http://${UPSTREAM}" >/dev/null

		for _ in $(seq 1 30); do
			PUBLIC_URL="$(docker logs wren-tunnel 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | head -1 || true)"
			[[ -n "$PUBLIC_URL" ]] && break
			sleep 2
		done

		if [[ -n "$PUBLIC_URL" ]]; then
			info "quick tunnel: ${PUBLIC_URL}"
		else
			warn "The tunnel did not print an address yet. Find it with:"
			warn "  docker logs wren-tunnel | grep trycloudflare.com"
		fi
	fi
fi

step "Firewall"

if [[ "$TUNNELLING" == "yes" ]]; then
	info "nothing to open: the tunnel dials out, so no inbound port is used"
elif command -v iptables >/dev/null 2>&1; then
	if [[ -n "$ALLOW_IP" ]]; then
		iptables -C INPUT -p tcp -s "$ALLOW_IP" --dport "$PUBLIC_PORT" -j ACCEPT 2>/dev/null \
			|| iptables -I INPUT 1 -p tcp -s "$ALLOW_IP" --dport "$PUBLIC_PORT" -j ACCEPT

		info "port ${PUBLIC_PORT} open to ${ALLOW_IP} only"
	else
		warn "No --allow-ip given: the port stays closed from outside this machine."
		warn "Re-run with --allow-ip <your WordPress server IP>, or with --quick-tunnel."
	fi

	netfilter-persistent save >/dev/null 2>&1 || true
fi

if [[ -z "$PUBLIC_URL" ]]; then
	if [[ -n "$TUNNEL_TOKEN" ]]; then
		PUBLIC_URL="https://<the hostname you gave the tunnel>"
	else
		PUBLIC_IP="$(curl -s --max-time 5 https://api.ipify.org || echo 'YOUR_SERVER_IP')"
		PUBLIC_URL="http://${PUBLIC_IP}:${PUBLIC_PORT}"
	fi
fi

# ---------------------------------------------------------------------------
# 9. Tell WordPress where we are
# ---------------------------------------------------------------------------

PAIRED="no"

pair_once() {
	local url="$1" code="$2" endpoint="$3" token="$4"
	local body status

	body="$(jq -n --arg c "$code" --arg e "$endpoint" --arg k "$token" \
		'{code: $c, endpoint: $e, api_key: $k, api_prefix: "/v1"}')"

	status="$(curl -sS -m 30 -o /tmp/wren-pair-response -w '%{http_code}' \
		-X POST -H 'Content-Type: application/json' \
		--data-binary "$body" "$url" 2>/dev/null || echo 000)"

	[[ "$status" == "200" ]]
}

if [[ -n "$PAIR_URL" && -n "$PAIR_CODE" ]]; then
	step "Telling WordPress where to find this server"

	if pair_once "$PAIR_URL" "$PAIR_CODE" "$PUBLIC_URL" "$TOKEN"; then
		PAIRED="yes"
		info "endpoint and API key stored in WordPress"
	else
		warn "WordPress did not accept the pairing (HTTP $(cat /tmp/wren-pair-response 2>/dev/null | head -c 200))."
		warn "The code may have expired, or this machine cannot reach ${PAIR_URL}."
		warn "Generate a new code in wp-admin, or fill the settings in by hand with the values below."
	fi

	rm -f /tmp/wren-pair-response

	# A quick tunnel gets a different address every time it restarts, which
	# would silently break the plugin. A small timer reports the current one.
	if [[ "$QUICK_TUNNEL" == "yes" ]]; then
		mkdir -p /var/lib/wren-pair

		umask 077
		cat > /etc/wren-pair.conf <<CONF
PAIR_URL='${PAIR_URL}'
PAIR_CODE='${PAIR_CODE}'
WREN_TOKEN='${TOKEN}'
CONF
		umask 022

		cat > /usr/local/bin/wren-pair-refresh <<'REFRESH'
#!/usr/bin/env bash
# Report the current quick-tunnel address to WordPress when it changes, and
# once in a while anyway so the pairing code stays alive.
set -euo pipefail

CONF=/etc/wren-pair.conf
STATE=/var/lib/wren-pair/last

[[ -r "$CONF" ]] || exit 0
# shellcheck source=/dev/null
. "$CONF"

URL="$(docker logs wren-tunnel 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1 || true)"

[[ -n "$URL" ]] || exit 0

LAST=""
[[ -f "$STATE" ]] && LAST="$(cat "$STATE")"

STALE="yes"

if [[ -f "$STATE" ]] && [[ $(( $(date +%s) - $(stat -c %Y "$STATE") )) -lt 900 ]]; then
	STALE="no"
fi

if [[ "$URL" == "$LAST" && "$STALE" == "no" ]]; then
	exit 0
fi

BODY="$(jq -n --arg c "$PAIR_CODE" --arg e "$URL" --arg k "$WREN_TOKEN" \
	'{code: $c, endpoint: $e, api_key: $k, api_prefix: "/v1"}')"

if curl -sS -m 30 -o /dev/null -f -X POST -H 'Content-Type: application/json' \
	--data-binary "$BODY" "$PAIR_URL"; then
	printf '%s' "$URL" > "$STATE"
	touch "$STATE"
else
	# A rejected code means the administrator closed pairing: stop nagging.
	systemctl disable --now wren-pair-refresh.timer >/dev/null 2>&1 || true
fi
REFRESH

		chmod 755 /usr/local/bin/wren-pair-refresh

		cat > /etc/systemd/system/wren-pair-refresh.service <<'UNIT'
[Unit]
Description=Report the Wren AI tunnel address to WordPress
After=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/bin/wren-pair-refresh
UNIT

		cat > /etc/systemd/system/wren-pair-refresh.timer <<'UNIT'
[Unit]
Description=Keep WordPress pointed at the current Wren AI tunnel address

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=wren-pair-refresh.service

[Install]
WantedBy=timers.target
UNIT

		systemctl daemon-reload
		systemctl enable --now wren-pair-refresh.timer >/dev/null 2>&1 || true

		[[ "$PAIRED" == "yes" ]] && printf '%s' "$PUBLIC_URL" > /var/lib/wren-pair/last

		info "a timer will report a new tunnel address within 5 minutes of it changing"
		info "stop it with: systemctl disable --now wren-pair-refresh.timer"
	fi
fi

# ---------------------------------------------------------------------------
# 10. What to do next
# ---------------------------------------------------------------------------

cat <<SUMMARY

$(printf '\033[1;32m')Done.$(printf '\033[0m')

  Wren AI health : $(curl -sf "http://127.0.0.1:${PORT}/health" >/dev/null && echo 'ok' || echo 'not answering yet')
  Chat model     : ${LLM_MODEL_ID}
  Embedder       : ${EMBED_MODEL_ID} (${EMBEDDING_DIM} dims)
  Stack          : ${DOCKER_DIR}

$(if [[ "$PAIRED" == "yes" ]]; then
	printf 'WordPress already has these - the settings screen filled itself in:\n'
else
	printf 'In WordPress, under Wren AI -> Settings:\n'
fi)

  Endpoint   : ${PUBLIC_URL}
  API prefix : /v1
  API key    : ${TOKEN:-(none - the endpoint is unauthenticated, keep it private)}
  Timeout    : 30 seconds

Then Wren AI -> Data & schema: pick the tables, write the business context,
press "Build & deploy schema", and wait for it to say finished.

Useful afterwards:

  docker compose -f ${DOCKER_DIR}/docker-compose.yaml ps
  docker compose -f ${DOCKER_DIR}/docker-compose.yaml logs -f wren-ai-service
  docker logs wren-tunnel | grep trycloudflare.com    # current tunnel address

SUMMARY
