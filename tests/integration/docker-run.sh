#!/usr/bin/env bash
set -euo pipefail

WP_ROOT=/var/www/html
WP_URL="${WP_URL:-http://wordpress}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-4.2.4}"
export HOME=/tmp/wpx-home
mkdir -p "$HOME"

for _ in $(seq 1 60); do
    if wp --path="$WP_ROOT" core version >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

wp --path="$WP_ROOT" core version >/dev/null

if ! wp --path="$WP_ROOT" core is-installed >/dev/null 2>&1; then
    wp --path="$WP_ROOT" core install \
        --url="$WP_URL" \
        --title="WPX Integration" \
        --admin_user=beyler \
        --admin_password=integration-only \
        --admin_email=wpx@example.invalid \
        --skip-email
fi

wp --path="$WP_ROOT" plugin install \
    "https://downloads.wordpress.org/plugin/elementor.${ELEMENTOR_VERSION}.zip" \
    --force --activate

wp --path="$WP_ROOT" plugin install /workspace/build/wpx.zip --force --activate

if [[ "${RUN_PLUGIN_CHECK:-0}" == "1" ]]; then
    wp --path="$WP_ROOT" plugin install plugin-check --activate
    wp --path="$WP_ROOT" plugin check agent-control-plane-for-elementor \
        --mode=new \
        --format=json \
        --require="$WP_ROOT/wp-content/plugins/plugin-check/cli.php"
fi

wpx connect docker --local --path "$WP_ROOT" --wp-bin /usr/local/bin/wp >/dev/null

WP_ROOT="$WP_ROOT" \
WP_BIN=/usr/local/bin/wp \
WPX_BIN=/usr/local/bin/wpx \
    /workspace/tests/integration/regression.sh
