#!/usr/bin/env bash
#
# Build an installable zip. WordPress expects the plugin inside a folder named
# after its slug, so the archive wraps the repository in wp-wren-dashboards/.
#
#   ./bin/build-zip.sh  ->  dist/wp-wren-dashboards.zip
set -euo pipefail

SLUG="wp-wren-dashboards"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$(mktemp -d)"
DIST="${ROOT}/dist"

trap 'rm -rf "${BUILD}"' EXIT

mkdir -p "${BUILD}/${SLUG}" "${DIST}"

# Ship what a site needs: no VCS metadata, no tests, no build output.
# tar rather than rsync, which is missing on plenty of machines.
tar -cf - -C "${ROOT}" \
	--exclude './.git' \
	--exclude './.github' \
	--exclude './dist' \
	--exclude './bin' \
	--exclude './tests' \
	--exclude './.gitignore' \
	. | tar -xf - -C "${BUILD}/${SLUG}"

rm -f "${DIST}/${SLUG}.zip"
( cd "${BUILD}" && zip -rq "${DIST}/${SLUG}.zip" "${SLUG}" )

echo "dist/${SLUG}.zip"
unzip -l "${DIST}/${SLUG}.zip" | tail -1
