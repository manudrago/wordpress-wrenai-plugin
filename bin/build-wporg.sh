#!/usr/bin/env bash
#
# Build the copy that goes to WordPress.org:
#
#   ./bin/build-wporg.sh   ->  dist/ideagency-ai-data-dashboards.zip
#
# WordPress.org hosts complete plugins only, so this copy carries none of the
# paid code: no licence client, no email reports, no white label, no
# shortcodes. Those files are left out, and every block fenced with
#
#     // wporg:strip-start
#     ...
#     // wporg:strip-end
#
# (or the same markers inside <?php ?> tags in a view) is removed from the
# files that remain. What is left has nothing to unlock.
#
# The directory listing needs a name and prefix of its own, so the copy is
# renamed on the way out: "Ideagency AI Data Dashboards", slug and text domain
# ideagency-ai-data-dashboards, and the iadd_ / IADD_ / iadd- prefix in place
# of wwd_ / WWD_ / wwd-. Its readme is wporg/readme.txt.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ROOT}/dist"
SLUG="ideagency-ai-data-dashboards"
BUILD="$(mktemp -d)"
OUT="${BUILD}/${SLUG}"

mkdir -p "${OUT}" "${DIST}"

tar -cf - -C "${ROOT}" \
	--exclude './.git' \
	--exclude './.github' \
	--exclude './dist' \
	--exclude './bin' \
	--exclude './tests' \
	--exclude './deploy' \
	--exclude './docs' \
	--exclude './wporg' \
	--exclude './README.md' \
	--exclude './readme.txt' \
	--exclude './.gitignore' \
	--exclude './edition.php' \
	--exclude './includes/class-wwd-license.php' \
	--exclude './includes/class-wwd-reports.php' \
	--exclude './includes/class-wwd-brand.php' \
	. | tar -xf - -C "${OUT}"

cp "${ROOT}/wporg/readme.txt" "${OUT}/readme.txt"
mv "${OUT}/datachat-ai.php" "${OUT}/${SLUG}.php"

# Rename files: class-wwd-admin.php -> class-iadd-admin.php, and so on.
find "${OUT}" -depth -name '*wwd*' | while read -r path; do
	mv "${path}" "$(dirname "${path}")/$(basename "${path}" | sed 's/wwd/iadd/g')"
done

python3 - "${OUT}" "${SLUG}" <<'PY'
import os, re, sys

out, slug = sys.argv[1], sys.argv[2]
fence = re.compile(r'^[^\n]*wporg:strip-start[^\n]*\n.*?^[^\n]*wporg:strip-end[^\n]*\n', re.S | re.M)

for base, _, files in os.walk(out):
    for name in files:
        if not name.endswith(('.php', '.js', '.css')):
            continue
        path = os.path.join(base, name)
        with open(path, encoding='utf-8') as f:
            s = f.read()

        s = fence.sub('', s)
        if 'wporg:strip' in s:
            sys.exit('Unbalanced wporg:strip markers in ' + path)

        s = re.sub(r'@package\s+\S+', '@package Ideagency_AI_Data_Dashboards', s)
        s = s.replace('https://github.com/manudrago/wordpress-wrenai-plugin\n', 'https://ideagency.co.uk/our-plugins/\n')
        s = s.replace('datachat-ai', slug)
        s = s.replace('DataChat AI', 'Ideagency AI Data Dashboards')
        s = s.replace('DataChat', 'AI Data Dashboards')
        s = s.replace('datachat', 'iadd')
        s = s.replace('WWD', 'IADD').replace('wwd', 'iadd')

        # No shortcodes here, so the class that draws the ask form and the
        # dashboards for the admin screens goes by what it does.
        for a, b in (
            ('IADD_Shortcodes', 'IADD_Renderer'),
            ('class-iadd-shortcodes.php', 'class-iadd-renderer.php'),
            ('function shortcodes()', 'function renderer()'),
            ('->shortcodes()', '->renderer()'),
            ('$this->shortcodes', '$this->renderer'),
            ('protected $shortcodes', 'protected $renderer'),
            ('$shortcodes', '$renderer'),
            (' * Front-end shortcodes.', ' * The ask form and dashboard renderer.'),
            (' * Register shortcodes.', ' * Register the assets.'),
            ('shared by the shortcodes and the\n\t * admin screens that are the main way to reach them.', 'drawn on the admin screens.'),
            ('list from the shortcode', 'list'),
            ('Shortcode attributes.', 'Attributes.'),
        ):
            s = s.replace(a, b)

        # Blank lines left behind where a block was removed.
        s = re.sub(r'\n{3,}', '\n\n', s)

        with open(path, 'w', encoding='utf-8') as f:
            f.write(s)
PY

mv "${OUT}/includes/class-iadd-shortcodes.php" "${OUT}/includes/class-iadd-renderer.php"

# Nothing paid may survive.
if grep -rniE "licen[cs]e_?key|is_licensed|WWD_License|IADD_License|IADD_Brand|IADD_Reports|edition\(\)|Pro and Agency|wren-ai/v1|add_shortcode|IADD_Shortcodes" "${OUT}" --include=*.php --include=*.js; then
	echo "Paid code left in the WordPress.org build." >&2
	exit 1
fi

for f in $(find "${OUT}" -name '*.php'); do
	php -l "$f" > /dev/null
done

rm -f "${DIST}/${SLUG}.zip"
(cd "${BUILD}" && zip -qr "${DIST}/${SLUG}.zip" "${SLUG}")
rm -rf "${BUILD}"

echo "Built ${DIST}/${SLUG}.zip"
