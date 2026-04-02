#!/usr/bin/env bash
#
# Provision pdo_mysql into the generated wp-env containers.
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$(
    cd "$PROJECT_DIR" && node - <<'NODE' 2>/dev/null
const { loadConfig } = require('@wordpress/env/lib/config');

(async () => {
  const config = await loadConfig(process.cwd(), null);
  process.stdout.write(config.dockerComposeConfigPath || '');
})().catch(() => process.exit(1));
NODE
)"

if [ -z "$COMPOSE_FILE" ]; then
    WP_ENV_DIR="$(cd "$PROJECT_DIR" && npx wp-env install-path 2>/dev/null | tail -n 1)"
    COMPOSE_FILE="${WP_ENV_DIR}/docker-compose.yml"
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "wp-env docker-compose.yml not found at ${COMPOSE_FILE}" >&2
    exit 1
fi

mapfile -t SERVICES < <(
    docker compose -f "$COMPOSE_FILE" config --services \
        | grep -E '^(wordpress|cli|tests-wordpress|tests-cli)$' || true
)

if [ "${#SERVICES[@]}" -eq 0 ]; then
    echo "No wp-env runtime services found in ${COMPOSE_FILE}" >&2
    exit 1
fi

restart_needed=0

for service in "${SERVICES[@]}"; do
    echo "Ensuring pdo_mysql is available in ${service}..."

    if docker compose -f "$COMPOSE_FILE" exec -T "$service" php -m | grep -qi '^pdo_mysql$'; then
        echo "  ${service}: already present"
        continue
    fi

    docker compose -f "$COMPOSE_FILE" exec -T -u root "$service" sh -lc '
        set -e
        if php -m | grep -qi "^pdo_mysql$"; then
            exit 0
        fi
        if ! command -v docker-php-ext-install >/dev/null 2>&1; then
            echo "docker-php-ext-install is not available in this container." >&2
            exit 1
        fi
        docker-php-ext-install pdo_mysql
    '

    restart_needed=1
done

if [ "$restart_needed" -eq 1 ]; then
    echo "Restarting wp-env containers to load pdo_mysql..."
    docker compose -f "$COMPOSE_FILE" restart "${SERVICES[@]}" >/dev/null
fi
