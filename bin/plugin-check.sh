#!/usr/bin/env bash
#
# Run WordPress' Plugin Check against what this plugin ACTUALLY SHIPS.
#
#   composer check
#
# ---------------------------------------------------------------------------
# WHY THIS SCRIPT EXISTS
# ---------------------------------------------------------------------------
#
# `wp plugin check fluent-cart-bulk-order` run against the working copy reports
# a long list of errors, and every one of them is about a file that never
# reaches a store:
#
#   compressed_files                       build/*.zip
#   hidden_files                           .DS_Store, .phpunit.result.cache
#   application_detected                   bin/build-zip.sh, phpcs.xml.dist,
#                                          phpunit.xml.dist
#   missing_direct_file_access_protection  tests/bootstrap.php
#   PrefixAllGlobals                       tests/bootstrap.php's WordPress stubs
#   unexpected_markdown_file               CONCEPTS.md
#
# All of those are listed in .distignore and are absent from the zip. The
# report is not wrong; it is answering a different question — "what is in this
# directory" rather than "what does this plugin ship" — because the development
# checkout happens to live inside wp-content/plugins.
#
# Two of those findings cannot be fixed in place even in principle.
# tests/bootstrap.php declares `__()`, `esc_html()` and friends, so it must NOT
# prefix them; and it runs under PHPUnit with no WordPress, so
# `defined('ABSPATH') || exit;` would end the test run on the first line.
#
# So the check is run against the staged copy `composer build` produces, which
# is the zip's contents exactly.
#
# ---------------------------------------------------------------------------
# THE TEXT DOMAIN FILTER
# ---------------------------------------------------------------------------
#
# Plugin Check resolves a plugin by its directory name under wp-content/plugins,
# and the real name is taken by this checkout. The staged copy is therefore
# checked under a temporary slug — which makes Plugin Check expect a text domain
# matching that slug, and report every correct `'fluent-cart-bulk-order'` as a
# mismatch.
#
# Those, and only those, are filtered out below. They are an artefact of the
# rename and cannot occur under the real slug. Everything else is reported.
#
# To confirm the text domain itself, run the plain check on the working copy:
# it uses the real directory name and will say so.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="fluent-cart-bulk-order"
TMP_SLUG="${SLUG}-distcheck"

command -v wp >/dev/null 2>&1 || {
	echo "error: wp-cli is required and was not found on PATH." >&2
	exit 1
}

# wp-content/plugins is this checkout's parent.
PLUGINS_DIR="$(cd "$ROOT/.." && pwd)"
STAGE="$ROOT/build/$SLUG"
TARGET="$PLUGINS_DIR/$TMP_SLUG"

# Always remove the temporary copy, however this script ends. Leaving a second
# copy of the plugin in wp-content/plugins would be worse than any lint finding.
cleanup() { rm -rf "$TARGET"; }
trap cleanup EXIT

echo "Building the distributable..."
"$ROOT/bin/build-zip.sh" >/dev/null

[ -d "$STAGE" ] || {
	echo "error: expected a staged copy at $STAGE and found none." >&2
	exit 1
}

rm -rf "$TARGET"
cp -R "$STAGE" "$TARGET"

echo "Checking $STAGE (as $TMP_SLUG)"
echo

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"; cleanup' EXIT

# `|| true`: a non-zero exit means findings, which is this script's job to
# report rather than to die on.
wp plugin check "$TMP_SLUG" 2>/dev/null > "$REPORT" || true

FINDINGS="$(grep -E "	(ERROR|WARNING)	" "$REPORT" \
	| grep -viE 'TextDomainMismatch|textdomain_mismatch' \
	|| true)"

if [ -z "$FINDINGS" ]; then
	echo "No findings in the distributable."
	echo
	echo "(Text-domain mismatches from the temporary slug were filtered — see the"
	echo " comments at the top of this script for why.)"
	exit 0
fi

echo "$FINDINGS"
echo
echo "$(printf '%s\n' "$FINDINGS" | wc -l | tr -d ' ') finding(s) in the shipped plugin."
exit 1
