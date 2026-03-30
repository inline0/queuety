#!/usr/bin/env bash
#
# Run Queuety core E2E tests that do not require wp-env.
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASS=0
FAIL=0

run_test() {
    local test_script="$1"
    local test_name
    test_name="$(basename "$test_script")"

    echo ""
    echo "=== Running: ${test_name} ==="
    if bash "$test_script"; then
        echo "--- PASS: ${test_name} ---"
        PASS=$((PASS + 1))
    else
        echo "--- FAIL: ${test_name} ---"
        FAIL=$((FAIL + 1))
    fi
}

for test_file in "$SCRIPT_DIR"/test-*.sh; do
    [ -f "$test_file" ] || continue
    [ "$(basename "$test_file")" = "test-wp-env.sh" ] && continue
    run_test "$test_file"
done

echo ""
echo "=============================="
echo "Core E2E Results: ${PASS} passed, ${FAIL} failed"
echo "=============================="

[ "$FAIL" -eq 0 ] || exit 1
