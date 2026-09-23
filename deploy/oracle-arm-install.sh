#!/usr/bin/env bash
#
# Kept so older instructions keep working: the installer is no longer
# Oracle-specific and lives in install-wren-ai.sh, which runs on any
# Ubuntu/Debian machine. Every option is the same.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '\033[1;33mNote:\033[0m oracle-arm-install.sh is now a thin wrapper around install-wren-ai.sh.\n'

exec bash "${DIR}/install-wren-ai.sh" "$@"
