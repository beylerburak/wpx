#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/tests/integration/compose.yaml"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-wpx-integration-${UID:-local}}"

cleanup() {
    docker compose -f "$COMPOSE_FILE" down --volumes --remove-orphans
}
trap cleanup EXIT INT TERM

docker compose -f "$COMPOSE_FILE" up -d db wordpress
docker compose -f "$COMPOSE_FILE" run --build --rm test
