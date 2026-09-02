#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${VERSION:-0.1.0}"
PLUGIN_FILE="$ROOT_DIR/plugin/wpx/wpx.php"
OUTPUT_DIR="$ROOT_DIR/build"
OUTPUT_FILE="$OUTPUT_DIR/wpx.zip"

header_version="$(sed -n 's/^ \* Version: \([^[:space:]]*\).*/\1/p' "$PLUGIN_FILE")"
constant_version="$(sed -n "s/^define( 'WPX_VERSION', '\([^']*\)' );/\1/p" "$PLUGIN_FILE")"

if [[ "$header_version" != "$VERSION" || "$constant_version" != "$VERSION" ]]; then
    printf 'Version mismatch: requested=%s, plugin header=%s, WPX_VERSION=%s\n' \
        "$VERSION" "$header_version" "$constant_version" >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"
rm -f "$OUTPUT_FILE"
(
    cd "$ROOT_DIR/plugin"
    zip -q -r "$OUTPUT_FILE" wpx \
        -x '*.DS_Store' '*/.git/*' '*/node_modules/*' '*/vendor/*'
)

printf 'Created %s\n' "$OUTPUT_FILE"
