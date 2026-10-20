#!/usr/bin/env bash
#
# Builds dist/fluxis-for-woocommerce-<version>.zip with a top-level
# fluxis-for-woocommerce/ folder, leaving out everything listed in .distignore.
#
# The plugin loads src/ with its own PSR-4 autoloader and has no runtime
# Composer dependencies, so the zip ships without a vendor/ directory.

set -euo pipefail

SLUG="fluxis-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="$ROOT/$SLUG.php"

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -n 1)"

if [[ -z "$VERSION" ]]; then
    echo "Could not read the Version header from $MAIN_FILE" >&2
    exit 1
fi

STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

mkdir -p "$STAGING/$SLUG" "$ROOT/dist"

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGING/$SLUG/"

ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"

(cd "$STAGING" && zip -rqX "$ZIP" "$SLUG")

echo "$ZIP"
