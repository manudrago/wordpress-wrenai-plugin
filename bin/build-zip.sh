#!/usr/bin/env bash
#
# Build an installable zip. WordPress expects the plugin inside a folder named
# after its slug, so the archive wraps the repository in that folder.
#
#   ./bin/build-zip.sh                     ->  dist/datachat-ai.zip        (free)
#   ./bin/build-zip.sh --edition pro       ->  dist/datachat-ai-pro.zip
#   ./bin/build-zip.sh --edition agency    ->  dist/datachat-ai-agency.zip
#   ./bin/build-zip.sh --all               ->  all three
#
# One codebase, three archives. A paid archive differs from the free one by a
# single generated file, edition.php, which names the edition and the shop that
# checks its keys; everything the licence unlocks is already in the code,
# waiting behind the wwd_is_licensed filter.
#
#   --slug NAME   Use this folder name instead of the edition's own. WordPress
#                 treats an upload as an update only when the folder matches
#                 the one already installed, so a site that installed this from
#                 a GitHub source archive needs its own name:
#                 ./bin/build-zip.sh --slug wordpress-wrenai-plugin-main
#
#   --endpoint U  Where paid builds check licence keys. Defaults to
#                 $WWD_LICENSE_ENDPOINT, or the placeholder below.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ROOT}/dist"

EDITION="free"
SLUG=""
ALL="no"
ENDPOINT="${WWD_LICENSE_ENDPOINT:-https://ideagency.co.uk/wp-json/datachat/v1/license}"

while [[ $# -gt 0 ]]; do
	case "$1" in
		--edition) EDITION="$2"; shift 2 ;;
		--slug) SLUG="$2"; shift 2 ;;
		--endpoint) ENDPOINT="$2"; shift 2 ;;
		--all) ALL="yes"; shift ;;
		-h|--help) sed -n '2,25p' "$0"; exit 0 ;;
		# A bare first argument is the slug, as this script used to take.
		*) SLUG="$1"; shift ;;
	esac
done

build() {
	local edition="$1" slug="$2"
	local build_dir
	build_dir="$(mktemp -d)"

	case "$edition" in
		free|pro|agency) ;;
		*) echo "Unknown edition: ${edition}" >&2; exit 1 ;;
	esac

	mkdir -p "${build_dir}/${slug}" "${DIST}"

	# Ship what a site needs: no VCS metadata, no tests, no build output, and
	# nothing out of deploy/ - the server installer is fetched from GitHub when
	# it is wanted, and the licence endpoint belongs on the shop, not here.
	# tar rather than rsync, which is missing on plenty of machines.
	tar -cf - -C "${ROOT}" \
		--exclude './.git' \
		--exclude './.github' \
		--exclude './dist' \
		--exclude './bin' \
		--exclude './tests' \
		--exclude './deploy' \
		--exclude './.gitignore' \
		--exclude './edition.php' \
		. | tar -xf - -C "${build_dir}/${slug}"

	if [[ "$edition" != "free" ]]; then
		local label
		label="$( [[ "$edition" == "pro" ]] && echo 'Pro' || echo 'Agency' )"

		cat > "${build_dir}/${slug}/edition.php" <<EDITION
<?php
/**
 * Which edition this copy is. Written by bin/build-zip.sh; not in the
 * repository, because the repository is the free plugin.
 *
 * @package DataChat_AI
 */

defined( 'ABSPATH' ) || exit;

define( 'WWD_EDITION', '${edition}' );
define( 'WWD_EDITION_LABEL', '${label}' );
define( 'WWD_LICENSE_ENDPOINT', '${ENDPOINT}' );
EDITION

		# The plugin header carries the edition too, so wp-admin and the
		# updater can tell two installed copies apart.
		sed -i "s/^ \* Plugin Name:       DataChat AI$/ * Plugin Name:       DataChat AI ${label}/" \
			"${build_dir}/${slug}/datachat-ai.php"
	fi

	rm -f "${DIST}/${slug}.zip"
	( cd "${build_dir}" && zip -rq "${DIST}/${slug}.zip" "${slug}" )

	rm -rf "${build_dir}"

	printf 'dist/%s.zip  (%s)  %s\n' \
		"$slug" \
		"$edition" \
		"$(unzip -l "${DIST}/${slug}.zip" | tail -1 | tr -s ' ')"
}

default_slug() {
	case "$1" in
		free) echo "datachat-ai" ;;
		*) echo "datachat-ai-$1" ;;
	esac
}

if [[ "$ALL" == "yes" ]]; then
	for edition in free pro agency; do
		build "$edition" "$( default_slug "$edition" )"
	done

	exit 0
fi

build "$EDITION" "${SLUG:-$( default_slug "$EDITION" )}"
