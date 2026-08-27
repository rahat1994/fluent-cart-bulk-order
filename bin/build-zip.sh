#!/usr/bin/env bash
#
# Build the distributable zip for WordPress.org.
#
# Produces build/fluent-cart-bulk-order-<version>.zip whose single top-level
# folder is `fluent-cart-bulk-order/` — the wp.org slug, and the folder name
# WordPress will install. Anything listed in .distignore is left out.
#
#   composer build            # or: bin/build-zip.sh
#
# The version comes from the plugin header, and the script refuses to build if
# the header, FCBO_VERSION and readme.txt's Stable tag do not all agree. A
# mismatch there is the usual reason a wp.org update "did not go out".
#
# ---------------------------------------------------------------------------
# HOW A RELEASE GOES OUT
# ---------------------------------------------------------------------------
#
# `development` is the default branch and the one releases are cut from.
# `main` is a stale historical stub and is not part of this.
#
#   1. Bump the version in all three places — the `Version:` header,
#      FCBO_VERSION, and `Stable tag:` in readme.txt. Add the Changelog entry.
#   2. Merge that to `development`.
#   3. Tag the commit on `development` (`git tag v1.2.3 && git push origin v1.2.3`).
#   4. Run this script and copy the staged folder's contents into the wp.org
#      SVN checkout as BOTH `trunk/` and a new `tags/1.2.3/`, then `svn ci`.
#      wp.org serves whatever `Stable tag` names, so trunk alone changes nothing.
#
# The directory assets — icon, banners, screenshots — live in the SVN `assets/`
# folder, NOT in this zip. They are not built here and never ship to a store.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="fluent-cart-bulk-order"
PLUGIN_FILE="$ROOT/$SLUG.php"
BUILD_DIR="$ROOT/build"

cd "$ROOT"

for tool in rsync zip; do
	command -v "$tool" >/dev/null 2>&1 || {
		echo "error: '$tool' is required and was not found on PATH." >&2
		exit 1
	}
done

# ---------------------------------------------------------------------------
# Version agreement
# ---------------------------------------------------------------------------

header_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "$PLUGIN_FILE" | head -n 1)"
const_version="$(sed -n "s/^define('FCBO_VERSION', *'\([^']*\)').*/\1/p" "$PLUGIN_FILE" | head -n 1)"
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/readme.txt" | head -n 1)"

if [ -z "$header_version" ]; then
	echo "error: could not read 'Version:' from the plugin header." >&2
	exit 1
fi

if [ "$header_version" != "$const_version" ] || [ "$header_version" != "$stable_tag" ]; then
	echo "error: version mismatch — refusing to build." >&2
	echo "  Plugin header 'Version:'   $header_version" >&2
	echo "  FCBO_VERSION               $const_version" >&2
	echo "  readme.txt 'Stable tag:'   $stable_tag" >&2
	exit 1
fi

echo "Building $SLUG $header_version"

# ---------------------------------------------------------------------------
# Stage, using .distignore as the exclude list
# ---------------------------------------------------------------------------
#
# rsync rather than `zip -x`: it gives one staged copy that can be inspected
# before it is compressed, and the top-level folder is created explicitly
# instead of depending on the checkout directory happening to be named right.

STAGE="$BUILD_DIR/$SLUG"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE"

# Comments and blank lines out; everything else is an rsync exclude pattern.
EXCLUDES="$(mktemp)"
trap 'rm -f "$EXCLUDES"' EXIT
grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$ROOT/.distignore" > "$EXCLUDES"

rsync -a --exclude-from="$EXCLUDES" --exclude='/build' "$ROOT/" "$STAGE/"

# ---------------------------------------------------------------------------
# Zip
# ---------------------------------------------------------------------------

ZIP="$BUILD_DIR/$SLUG-$header_version.zip"

(cd "$BUILD_DIR" && zip -rq "$(basename "$ZIP")" "$SLUG")

echo
echo "Wrote $ZIP"
echo
echo "Top level of the archive:"
unzip -Z1 "$ZIP" | awk -F/ '{ print $1 "/" $2 }' | sed 's:/$::' | sort -u | sed 's/^/  /'
