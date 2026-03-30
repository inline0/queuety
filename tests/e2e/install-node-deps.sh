#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ATTEMPTS="${1:-3}"

if [ -f "$PROJECT_DIR/package-lock.json" ]; then
    INSTALL_CMD=(npm ci --no-audit --no-fund --prefer-offline)
else
    INSTALL_CMD=(npm install --no-audit --no-fund --prefer-offline)
fi

cd "$PROJECT_DIR"

attempt=1
while [ "$attempt" -le "$ATTEMPTS" ]; do
    if "${INSTALL_CMD[@]}"; then
        exit 0
    fi

    if [ "$attempt" -eq "$ATTEMPTS" ]; then
        break
    fi

    sleep_seconds=$((attempt * 5))
    echo "Node dependency install failed on attempt $attempt/$ATTEMPTS. Retrying in ${sleep_seconds}s..." >&2
    sleep "$sleep_seconds"
    attempt=$((attempt + 1))
done

echo "Node dependency install failed after $ATTEMPTS attempts." >&2
exit 1
