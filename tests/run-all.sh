#!/usr/bin/env bash
#
# Run the full local validation suite with the same categories used in CI.
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
QUEUETY_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
EXIT_CODE=0
BOLD='\033[1m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

cd "$QUEUETY_DIR"

echo -e "${BOLD}Queuety: Full Validation Suite${NC}"
echo "==========================================="
echo ""

run_check() {
    local label="$1"
    local command="$2"

    echo -e "${BOLD}━━━ ${label} ━━━${NC}"
    if eval "$command"; then
        echo -e "${GREEN}${label} passed${NC}"
    else
        EXIT_CODE=1
        echo -e "${RED}${label} failed${NC}"
    fi
    echo ""
}

run_check "Coding Standards" "composer cs"
run_check "Static Analysis" "composer stan"
run_check "Unit Tests" "composer test:unit"
run_check "Integration Tests" "composer test:integration"
run_check "E2E Tests" "bash tests/e2e/run-all.sh"
run_check "Docs" "npm --prefix docs run build"

echo "==========================================="
if [[ $EXIT_CODE -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All checks passed!${NC}"
else
    echo -e "${RED}${BOLD}Some checks failed${NC}"
fi

exit $EXIT_CODE
