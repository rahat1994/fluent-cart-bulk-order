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

command -v wp >/dev/null 2>&1 || {
	echo "error: wp-cli is required and was not found on PATH." >&2
	exit 1
}

# wp-content/plugins is this checkout's parent.
PLUGINS_DIR="$(cd "$ROOT/.." && pwd)"
STAGE="$ROOT/build/$SLUG"

# A slug unique to THIS run, and claimed with mkdir rather than assumed.
#
# The earlier version used a fixed `<slug>-distcheck` and began with
# `rm -rf` on it. That deletes whatever happens to be sitting at that path —
# another checkout, a colleague's working copy, an interrupted run someone is
# still reading — and it did so before the build had even succeeded. A script
# that exists to lint must not be the most destructive thing in the repository.
#
# mkdir fails if the directory already exists, which is exactly the atomic
# claim wanted here: either this run owns the path, or it stops.
TMP_SLUG="${SLUG}-distcheck-$$"
TARGET="$PLUGINS_DIR/$TMP_SLUG"

mkdir "$TARGET" || {
	echo "error: $TARGET already exists. Remove it and run again." >&2
	exit 1
}

# Registered only AFTER the mkdir above succeeded, so this can only ever remove
# a directory this run created.
REPORT=""
cleanup() {
	rm -rf "$TARGET"
	[ -n "$REPORT" ] && rm -f "$REPORT"
	return 0
}
trap cleanup EXIT

echo "Building the distributable..."
"$ROOT/bin/build-zip.sh" >/dev/null

[ -d "$STAGE" ] || {
	echo "error: expected a staged copy at $STAGE and found none." >&2
	exit 1
}

cp -R "$STAGE/." "$TARGET/"

echo "Checking $STAGE (as $TMP_SLUG)"
echo

REPORT="$(mktemp)"
STDERR="$(mktemp)"
trap 'cleanup; rm -f "$STDERR"' EXIT

# The exit code is captured, not discarded.
#
# `wp plugin check` exits 1 when it finds errors, which is a normal outcome
# here and must not kill the script under `set -e`. But it also exits non-zero
# when it could not run at all — WordPress unreachable, the Plugin Check plugin
# missing, a fatal in the plugin being checked. The earlier version wrote
# `|| true` and discarded stderr, so both cases produced an empty report and
# the script cheerfully announced "No findings". A lint tool that reports
# success when it did not run is worse than no lint tool.
STATUS=0
wp plugin check "$TMP_SLUG" >"$REPORT" 2>"$STDERR" || STATUS=$?

# A real report always names at least one file. An empty one alongside a
# non-zero status means the command failed rather than passed.
if [ "$STATUS" -ne 0 ] && ! grep -q "^FILE:" "$REPORT"; then
	echo "error: 'wp plugin check' did not run (exit $STATUS)." >&2
	echo >&2
	sed 's/^/  /' "$STDERR" >&2
	exit 1
fi

# Text-domain mismatches are filtered ONLY when the domain actually found is
# the correct one.
#
# Plugin Check derives the expected domain from the directory name, and the
# staged copy has to be checked under a temporary one (the real name is taken
# by this checkout), so every correct `fluent-cart-bulk-order` is reported as a
# mismatch. Those are noise.
#
# A mismatch naming any OTHER domain is a genuine bug — a typo, or a string
# copied in from another plugin — and matching on $SLUG keeps it visible. The
# earlier version dropped every mismatch line regardless of what it said, which
# would have hidden exactly the mistake this check exists to catch.
FINDINGS="$(grep -E "	(ERROR|WARNING)	" "$REPORT" \
	| grep -vE "(TextDomainMismatch|textdomain_mismatch).*${SLUG}" \
	|| true)"

if [ -z "$FINDINGS" ]; then
	echo "No findings in the distributable."
	echo
	echo "(Text-domain mismatches naming the correct domain were filtered — they"
	echo " are an artefact of the temporary slug. See the top of this script.)"
	exit 0
fi

echo "$FINDINGS"
echo
echo "$(printf '%s\n' "$FINDINGS" | wc -l | tr -d ' ') finding(s) in the shipped plugin."
exit 1
