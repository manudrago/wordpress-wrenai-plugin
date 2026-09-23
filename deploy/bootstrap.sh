#!/usr/bin/env bash
#
# One-line entry point for install-wren-ai.sh.
#
# The settings screen of the WP Wren Dashboards plugin prints a command that
# fetches this file and runs it with a pairing code, so the machine installs
# Wren AI and reports its address back to WordPress by itself. Nothing has to
# be copied by hand in either direction.
#
# It only adds the defaults that make that flow work - a random token and a
# Cloudflare quick tunnel, unless you asked for something else - and hands
# every argument to install-wren-ai.sh, which does the actual work.
#
#   sudo bash bootstrap.sh --pair-url https://site/wp-json/wren-ai/v1/pair \
#       --pair-code <code> --llm google --llm-api-key AIza...
#
# Point it at your own copy of the repository by exporting WREN_REPO_RAW.
set -euo pipefail

REPO_RAW="${WREN_REPO_RAW:-https://raw.githubusercontent.com/manudrago/wordpress-wrenai-plugin/main/deploy}"
WORK="/opt/wren-deploy"

step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
die()  { printf '\033[1;31m!!  %s\033[0m\n' "$1" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run this with sudo."

ARGS=("$@")

has_flag() {
	local needle="$1" arg

	for arg in "${ARGS[@]:-}"; do
		[[ "$arg" == "$needle" ]] && return 0
	done

	return 1
}

step "Fetching the installer"

command -v curl >/dev/null 2>&1 || {
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq && apt-get install -y -qq curl ca-certificates >/dev/null
}

mkdir -p "$WORK"

for file in install-wren-ai.sh make-wren-config.py; do
	curl -fsSL "${REPO_RAW}/${file}" -o "${WORK}/${file}" \
		|| die "Could not download ${file} from ${REPO_RAW}"
done

chmod +x "${WORK}/install-wren-ai.sh"

info "downloaded into ${WORK}"

# A gateway without a token is an open door to your data, and the plugin has a
# field for it anyway, so generate one rather than leaving it off.
if ! has_flag --token; then
	if command -v openssl >/dev/null 2>&1; then
		TOKEN="$(openssl rand -hex 16)"
	else
		TOKEN="$(tr -dc 'a-f0-9' < /dev/urandom | head -c 32)"
	fi

	ARGS+=(--token "$TOKEN")

	info "generated an API key: ${TOKEN}"
fi

# Without an inbound route nothing can reach the service. A quick tunnel is the
# one that works everywhere: behind NAT, without a public IP, without a domain.
if ! has_flag --quick-tunnel && ! has_flag --tunnel-token && ! has_flag --allow-ip; then
	ARGS+=(--quick-tunnel)

	info "no exposure chosen: using a Cloudflare quick tunnel"
fi

exec bash "${WORK}/install-wren-ai.sh" "${ARGS[@]}"
