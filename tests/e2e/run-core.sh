#!/usr/bin/env bash
#
# Run Queuety core E2E tests that do not require wp-env.
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASS=0
FAIL=0
BOLD='\033[1m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BOLD}Running Queuety core E2E tests${NC}"
echo ""

run_test() {
    local test_script="$1"
    local test_name
    test_name="$(basename "$test_script")"

    echo -e "${BOLD}━━━ ${test_name} ━━━${NC}"
    if bash "$test_script"; then
        PASS=$((PASS + 1))
        echo -e "${GREEN}PASS${NC}"
    else
        FAIL=$((FAIL + 1))
        echo -e "${RED}FAIL${NC}"
    fi
    echo ""
}

for test_file in "$SCRIPT_DIR"/test-*.sh; do
    [ -f "$test_file" ] || continue
    [ "$(basename "$test_file")" = "test-wp-env.sh" ] && continue
    run_test "$test_file"
done

echo "==========================================="
echo -e "${BOLD}Core E2E Results:${NC} ${PASS} passed, ${FAIL} failed"
if [ "$FAIL" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All core E2E test suites passed!${NC}"
else
    echo -e "${RED}${BOLD}Some core E2E test suites failed${NC}"
fi

[ "$FAIL" -eq 0 ] || exit 1
