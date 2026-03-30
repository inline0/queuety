#!/usr/bin/env bash
#
# E2E test: full WordPress integration via wp-env.
#
# Tests plugin activation, table creation, WP-CLI commands,
# job dispatch/processing, workflow lifecycle, and logging
# against a real WordPress + MySQL environment.
#
# Requires: Docker, Node.js, npm.
# Skips gracefully if Docker is not available.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
EXPECTED_VERSION="$(sed -n "s/^define( 'QUEUETY_VERSION', '\\([^']*\\)' );$/\\1/p" "$PROJECT_DIR/queuety.php" | head -n 1)"
PASS=0
FAIL=0
TOTAL=0

# Helpers.
pass() {
    PASS=$((PASS + 1))
    TOTAL=$((TOTAL + 1))
    echo "  PASS: $1"
}

fail() {
    FAIL=$((FAIL + 1))
    TOTAL=$((TOTAL + 1))
    echo "  FAIL: $1"
    echo "        $2"
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"
    if echo "$haystack" | grep -q "$needle"; then
        pass "$label"
    else
        fail "$label" "Expected to find '$needle' in output"
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"
    if echo "$haystack" | grep -q "$needle"; then
        fail "$label" "Did not expect to find '$needle' in output"
    else
        pass "$label"
    fi
}

assert_equals() {
    local expected="$1"
    local actual="$2"
    local label="$3"
    if [ "$expected" = "$actual" ]; then
        pass "$label"
    else
        fail "$label" "Expected '$expected', got '$actual'"
    fi
}

wp_cli() {
    cd "$PROJECT_DIR" && npx wp-env run cli wp "$@" 2>/dev/null
}

wp_eval() {
    local code="$1"
    wp_cli eval "require_once WP_PLUGIN_DIR . '/queuety/tests/e2e/fixtures/wp-env-workflows.php'; ${code}"
}

wp_flush() {
    wp_eval "Queuety\\Queuety::worker()->flush();"
}

wait_for_wordpress() {
    local port="$1"
    local tries="${2:-30}"
    local i

    for i in $(seq 1 "$tries"); do
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:${port}/" | grep -q "200\\|301\\|302"; then
            return 0
        fi
        sleep 1
    done

    return 1
}

cleanup() {
    cd "$PROJECT_DIR" && npx wp-env stop >/dev/null 2>&1 || true
}

trap cleanup EXIT

# Check Docker is available.
if ! docker info > /dev/null 2>&1; then
    echo "SKIP: Docker is not available"
    exit 0
fi

# Check Node.js is available.
if ! command -v npx > /dev/null 2>&1; then
    echo "SKIP: npx is not available"
    exit 0
fi

echo "=== Queuety wp-env E2E Tests ==="
echo ""

# Install dependencies if needed.
if [ ! -d "$PROJECT_DIR/node_modules/@wordpress/env" ]; then
    echo "Installing @wordpress/env..."
    cd "$PROJECT_DIR" && npm install --no-audit --no-fund 2>/dev/null
fi

# Start wp-env.
echo "Starting wp-env..."
set +e
WP_ENV_START_OUT="$(cd "$PROJECT_DIR" && npx wp-env start 2>&1)"
WP_ENV_START_STATUS=$?
set -e
printf '%s\n' "$WP_ENV_START_OUT"

# Wait for WordPress to be ready.
echo "Waiting for WordPress..."
if ! wait_for_wordpress 8888 30; then
    fail "wp-env startup" "WordPress did not become ready on http://localhost:8888/"
    exit 1
fi

if [ "$WP_ENV_START_STATUS" -ne 0 ]; then
    echo "wp-env start exited with status $WP_ENV_START_STATUS after the environment became ready."
fi

echo "Provisioning wp-env PHP runtime..."
if bash "$PROJECT_DIR/tests/e2e/provision-wp-env-pdo.sh"; then
    pass "wp-env runtime provisioning succeeds"
else
    fail "wp-env runtime provisioning succeeds" "Could not provision pdo_mysql in the wp-env containers"
    exit 1
fi

echo "Waiting for WordPress after provisioning..."
if ! wait_for_wordpress 8888 30; then
    fail "wp-env restart after provisioning" "WordPress did not recover on http://localhost:8888/"
    exit 1
fi

echo ""
echo "--- Plugin Activation ---"

# Test: plugin is active.
ACTIVE_PLUGINS=$(wp_cli plugin list --status=active --field=name 2>/dev/null || true)
assert_contains "$ACTIVE_PLUGINS" "queuety" "Plugin is active"

# Test: version constant is defined.
VERSION=$(wp_cli eval "echo QUEUETY_VERSION;" 2>/dev/null || true)
assert_equals "$EXPECTED_VERSION" "$VERSION" "QUEUETY_VERSION matches plugin constant"

PDO_MYSQL_AVAILABLE=$(wp_cli eval "echo extension_loaded('pdo_mysql') ? 'yes' : 'no';" 2>/dev/null || true)
assert_equals "yes" "$PDO_MYSQL_AVAILABLE" "wp-env CLI has pdo_mysql"

WORDPRESS_PDO_MYSQL=$(cd "$PROJECT_DIR" && npx wp-env run wordpress php -r "echo extension_loaded('pdo_mysql') ? 'yes' : 'no';" 2>/dev/null || true)
assert_equals "yes" "$WORDPRESS_PDO_MYSQL" "wp-env WordPress runtime has pdo_mysql"

echo ""
echo "--- Table Creation ---"

# Test: tables exist.
TABLES=$(wp_cli db query "SHOW TABLES LIKE '%queuety%';" --skip-column-names 2>/dev/null || true)
assert_contains "$TABLES" "queuety_jobs" "queuety_jobs table exists"
assert_contains "$TABLES" "queuety_workflows" "queuety_workflows table exists"
assert_contains "$TABLES" "queuety_logs" "queuety_logs table exists"
assert_contains "$TABLES" "queuety_artifacts" "queuety_artifacts table exists"

echo "--- WP-CLI: Status ---"

# Test: status command works.
STATUS=$(wp_cli queuety status 2>/dev/null || true)
assert_contains "$STATUS" "Pending" "status command shows Pending column"
assert_contains "$STATUS" "Buried" "status command shows Buried column"

echo ""
echo "--- WP-CLI: Webhooks ---"

WEBHOOK_ADD_OUT=$(wp_cli queuety webhook add job.completed https://example.com/hook 2>/dev/null || true)
assert_contains "$WEBHOOK_ADD_OUT" "registered" "webhook add command succeeds"

WEBHOOK_LIST_OUT=$(wp_cli queuety webhook list 2>/dev/null || true)
assert_contains "$WEBHOOK_LIST_OUT" "job.completed" "webhook list shows registered event"
assert_contains "$WEBHOOK_LIST_OUT" "https://example.com/hook" "webhook list shows registered URL"

WEBHOOK_ID=$(wp_cli db query "SELECT id FROM wp_queuety_webhooks ORDER BY id DESC LIMIT 1;" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)
WEBHOOK_REMOVE_OUT=$(wp_cli queuety webhook remove "$WEBHOOK_ID" 2>/dev/null || true)
assert_contains "$WEBHOOK_REMOVE_OUT" "removed" "webhook remove command succeeds"

echo ""
echo "--- WP-CLI: Dispatch and List ---"

# Test: dispatch a job.
DISPATCH_OUT=$(wp_cli queuety dispatch test_handler --payload='{"key":"value"}' 2>/dev/null || true)
assert_contains "$DISPATCH_OUT" "Dispatched job" "dispatch command succeeds"

# Test: list shows the job.
LIST_OUT=$(wp_cli queuety list 2>/dev/null || true)
assert_contains "$LIST_OUT" "test_handler" "list shows dispatched job"
assert_contains "$LIST_OUT" "pending" "list shows pending status"

# Dispatch more jobs for testing.
wp_cli queuety dispatch test_handler --payload='{"n":2}' > /dev/null 2>&1 || true
wp_cli queuety dispatch test_handler --payload='{"n":3}' > /dev/null 2>&1 || true

# Test: status shows correct count.
STATUS2=$(wp_cli queuety status 2>/dev/null || true)
assert_contains "$STATUS2" "3" "status shows 3 pending jobs"

echo ""
echo "--- WP-CLI: Bury and Retry ---"

# Get the first job ID.
JOB_ID=$(wp_cli db query "SELECT id FROM wp_queuety_jobs ORDER BY id ASC LIMIT 1;" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)

# Test: bury a job.
BURY_OUT=$(wp_cli queuety bury "$JOB_ID" 2>/dev/null || true)
assert_contains "$BURY_OUT" "buried" "bury command succeeds"

# Verify it's buried.
BURIED_STATUS=$(wp_cli db query "SELECT status FROM wp_queuety_jobs WHERE id=$JOB_ID;" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)
assert_equals "buried" "$BURIED_STATUS" "job is in buried status"

# Test: retry buried.
RETRY_OUT=$(wp_cli queuety retry-buried 2>/dev/null || true)
assert_contains "$RETRY_OUT" "Retried" "retry-buried command succeeds"

# Verify it's pending again.
RETRIED_STATUS=$(wp_cli db query "SELECT status FROM wp_queuety_jobs WHERE id=$JOB_ID;" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)
assert_equals "pending" "$RETRIED_STATUS" "job is pending after retry"

echo ""
echo "--- WP-CLI: Delete and Purge ---"

# Test: delete a job.
DELETE_OUT=$(wp_cli queuety delete "$JOB_ID" 2>/dev/null || true)
assert_contains "$DELETE_OUT" "deleted" "delete command succeeds"

# Verify it's gone.
DELETED_COUNT=$(wp_cli db query "SELECT COUNT(*) FROM wp_queuety_jobs WHERE id=$JOB_ID;" --skip-column-names 2>/dev/null | tr -d '[:space:]' || true)
assert_equals "0" "$DELETED_COUNT" "job is deleted from database"

echo ""
echo "--- WP-CLI: Workflow Commands ---"

# Create a workflow via direct PHP (WP-CLI dispatch doesn't support workflows).
WF_ID=$(wp_cli eval "
    \$wf_id = Queuety\Queuety::workflow('test_wf')
        ->then('Queuety\Tests\Integration\Fixtures\AccumulatingStep')
        ->then('Queuety\Tests\Integration\Fixtures\AccumulatingStep')
        ->dispatch(['counter' => 0]);
    echo \$wf_id;
" 2>/dev/null || true)

if [ -n "$WF_ID" ] && [ "$WF_ID" != "0" ]; then
    pass "workflow dispatch via PHP API (ID: $WF_ID)"

    # Test: workflow status command.
    WF_STATUS=$(wp_cli queuety workflow status "$WF_ID" 2>/dev/null || true)
    assert_contains "$WF_STATUS" "test_wf" "workflow status shows name"
    assert_contains "$WF_STATUS" "running" "workflow status shows running"
    assert_contains "$WF_STATUS" "0/2" "workflow status shows step 0/2"

    wp_eval "Queuety\\Queuety::put_artifact($WF_ID, 'brief', ['status' => 'ready']);" > /dev/null 2>&1 || true
    WF_ARTIFACTS=$(wp_cli queuety workflow artifacts "$WF_ID" 2>/dev/null || true)
    assert_contains "$WF_ARTIFACTS" "brief" "workflow artifacts lists stored artifact"
    WF_ARTIFACT=$(wp_cli queuety workflow artifact "$WF_ID" brief 2>/dev/null || true)
    assert_contains "$WF_ARTIFACT" "\"key\": \"brief\"" "workflow artifact shows stored artifact"

    # Test: workflow list command.
    WF_LIST=$(wp_cli queuety workflow list 2>/dev/null || true)
    assert_contains "$WF_LIST" "test_wf" "workflow list shows workflow"
    assert_contains "$WF_LIST" "running" "workflow list shows status"

    # Test: workflow pause.
    PAUSE_OUT=$(wp_cli queuety workflow pause "$WF_ID" 2>/dev/null || true)
    assert_contains "$PAUSE_OUT" "paused" "workflow pause succeeds"

    # Verify paused.
    WF_PAUSED=$(wp_cli queuety workflow status "$WF_ID" 2>/dev/null || true)
    assert_contains "$WF_PAUSED" "paused" "workflow is paused"

    # Process the current step while paused so resume can enqueue the next step.
    wp_cli eval "Queuety\\Queuety::worker()->flush();" > /dev/null 2>&1 || true

    WF_AFTER_PAUSED_FLUSH=$(wp_cli queuety workflow status "$WF_ID" 2>/dev/null || true)
    assert_contains "$WF_AFTER_PAUSED_FLUSH" "paused" "workflow stays paused after current step finishes"
    assert_contains "$WF_AFTER_PAUSED_FLUSH" "1/2" "workflow advanced while paused without enqueuing the next step"

    # Test: workflow resume.
    RESUME_OUT=$(wp_cli queuety workflow resume "$WF_ID" 2>/dev/null || true)
    assert_contains "$RESUME_OUT" "resumed" "workflow resume succeeds"

    WF_RESUMED=$(wp_cli queuety workflow status "$WF_ID" 2>/dev/null || true)
    assert_contains "$WF_RESUMED" "running" "workflow is running after resume"
else
    fail "workflow dispatch via PHP API" "Could not create workflow"
fi

echo ""
echo "--- WP-CLI: Review Workflow ---"

REVIEW_WF_ID=$(wp_eval "
    \$wf_id = Queuety\Queuety::workflow('review_gate')
        ->await_approval(result_key: 'approval')
        ->await_input(result_key: 'revision_notes')
        ->then('WpEnvReviewFinalizeStep')
        ->dispatch(['draft_id' => 9]);
    echo \$wf_id;
" 2>/dev/null || true)

if [ -n "$REVIEW_WF_ID" ] && [ "$REVIEW_WF_ID" != "0" ]; then
    pass "review workflow dispatch via PHP API (ID: $REVIEW_WF_ID)"

    wp_flush > /dev/null 2>&1 || true

    REVIEW_WAIT=$(wp_cli queuety workflow status "$REVIEW_WF_ID" 2>/dev/null || true)
    assert_contains "$REVIEW_WAIT" "waiting_signal" "review workflow waits for approval"
    assert_contains "$REVIEW_WAIT" "approval" "review workflow status shows approval wait"

    REVIEW_APPROVE=$(wp_cli queuety workflow approve "$REVIEW_WF_ID" --data='{\"approved\":true,\"by\":\"editor\"}' 2>/dev/null || true)
    assert_contains "$REVIEW_APPROVE" "Approval sent" "workflow approve command succeeds"

    wp_flush > /dev/null 2>&1 || true

    REVIEW_INPUT_WAIT=$(wp_cli queuety workflow status "$REVIEW_WF_ID" 2>/dev/null || true)
    assert_contains "$REVIEW_INPUT_WAIT" "waiting_signal" "review workflow waits for input after approval"
    assert_contains "$REVIEW_INPUT_WAIT" "editor" "approval payload is visible in workflow state"

    REVIEW_INPUT=$(wp_cli queuety workflow input "$REVIEW_WF_ID" --data='{\"note\":\"ship it\"}' 2>/dev/null || true)
    assert_contains "$REVIEW_INPUT" "Input sent" "workflow input command succeeds"

    wp_flush > /dev/null 2>&1 || true

    REVIEW_DONE=$(wp_cli queuety workflow status "$REVIEW_WF_ID" 2>/dev/null || true)
    assert_contains "$REVIEW_DONE" "completed" "review workflow completes after input"
    assert_contains "$REVIEW_DONE" "\"review_outcome\": \"approved\"" "review workflow stores approved outcome"
    assert_contains "$REVIEW_DONE" "\"revision_note\": \"ship it\"" "review workflow stores revision note"
else
    fail "review workflow dispatch via PHP API" "Could not create review workflow"
fi

echo ""
echo "--- WP-CLI: Agent Workflow Group ---"

AGENT_WF_ID=$(wp_eval "
    \$child = Queuety\Queuety::workflow('wp_env_agent')
        ->then('WpEnvAgentTaskStep')
        ->max_attempts(1);

    \$wf_id = Queuety\Queuety::workflow('wp_env_agent_parent')
        ->spawn_agents('agent_tasks', \$child, group_key: 'researchers')
        ->await_agent_group('researchers', \Queuety\Enums\WaitMode::Quorum, 2, 'agent_results')
        ->then('WpEnvAgentSummaryStep')
        ->dispatch([
            'agent_tasks' => [
                ['topic' => 'pricing'],
                ['topic' => 'reviews'],
                ['topic' => 'faq', 'should_fail' => true]
            ]
        ]);

    echo \$wf_id;
" 2>/dev/null || true)

if [ -n "$AGENT_WF_ID" ] && [ "$AGENT_WF_ID" != "0" ]; then
    pass "agent workflow group dispatch via PHP API (ID: $AGENT_WF_ID)"

    wp_flush > /dev/null 2>&1 || true
    wp_flush > /dev/null 2>&1 || true

    AGENT_WAIT=$(wp_cli queuety workflow status "$AGENT_WF_ID" 2>/dev/null || true)
    assert_contains "$AGENT_WAIT" "waiting_workflow" "agent workflow waits on spawned children"
    assert_contains "$AGENT_WAIT" "WaitMode: quorum" "agent workflow status shows quorum wait mode"
    assert_contains "$AGENT_WAIT" "researchers" "agent workflow wait details show the group key"

    for _ in 1 2 3 4 5 6; do
        wp_flush > /dev/null 2>&1 || true
    done

    AGENT_DONE=$(wp_cli queuety workflow status "$AGENT_WF_ID" 2>/dev/null || true)
    assert_contains "$AGENT_DONE" "completed" "agent workflow completes after quorum"
    assert_contains "$AGENT_DONE" "\"joined_count\": 2" "agent workflow stores joined quorum result count"
    assert_contains "$AGENT_DONE" "pricing" "agent workflow summary includes successful topics"
    assert_contains "$AGENT_DONE" "reviews" "agent workflow summary includes multiple successful topics"
else
    fail "agent workflow group dispatch via PHP API" "Could not create agent workflow"
fi

echo ""
echo "--- WP-CLI: Worker ---"

# Clear all existing jobs for a clean test.
wp_cli db query "DELETE FROM wp_queuety_jobs;" > /dev/null 2>&1 || true
wp_cli db query "DELETE FROM wp_queuety_workflows;" > /dev/null 2>&1 || true
wp_cli db query "DELETE FROM wp_queuety_logs;" > /dev/null 2>&1 || true

# Register a test handler and dispatch a job.
WORK_RESULT=$(wp_cli eval "
    class E2eTestHandler implements Queuety\Handler {
        public function handle(array \\\$payload): void {
            update_option('queuety_e2e_result', json_encode(\\\$payload));
        }
        public function config(): array { return []; }
    }
    Queuety\Queuety::register('e2e_test', 'E2eTestHandler');
    Queuety\Queuety::dispatch('e2e_test', ['msg' => 'hello from e2e'])->id();

    // Process the job inline since we can't run work --once in a separate process easily.
    \\\$worker = Queuety\Queuety::worker();
    \\\$worker->flush();

    echo get_option('queuety_e2e_result', 'NOT_SET');
" 2>/dev/null || true)

assert_contains "$WORK_RESULT" "hello from e2e" "worker processes job with correct payload"

echo ""
echo "--- WP-CLI: Log ---"

# Test: log command shows entries.
LOG_OUT=$(wp_cli queuety log 2>/dev/null || true)
if echo "$LOG_OUT" | grep -q "started\|completed"; then
    pass "log command shows entries"
else
    # Might be empty if previous tests cleaned up. Check it at least doesn't error.
    assert_not_contains "$LOG_OUT" "Error" "log command does not error"
fi

echo ""
echo "--- WP-CLI: Recover ---"

RECOVER_OUT=$(wp_cli queuety recover 2>/dev/null || true)
assert_contains "$RECOVER_OUT" "Recovered" "recover command succeeds"

echo ""
echo "--- WP-CLI: Purge ---"

PURGE_OUT=$(wp_cli queuety purge --older-than=0 2>/dev/null || true)
assert_contains "$PURGE_OUT" "Purged" "purge command succeeds"

echo ""
echo "--- WP-CLI: Full Workflow E2E ---"

# Run a complete workflow through the worker.
FULL_WF=$(wp_cli eval "
    class FetchStep implements Queuety\Step {
        public function handle(array \\\$state): array {
            return ['fetched' => 'data for user ' . \\\$state['user_id']];
        }
        public function config(): array { return []; }
    }
    class ProcessStep implements Queuety\Step {
        public function handle(array \\\$state): array {
            return ['processed' => 'result from: ' . \\\$state['fetched']];
        }
        public function config(): array { return []; }
    }

    \\\$wf_id = Queuety\Queuety::workflow('full_e2e')
        ->then('FetchStep')
        ->then('ProcessStep')
        ->dispatch(['user_id' => 42]);

    // Process all steps.
    Queuety\Queuety::worker()->flush();
    Queuety\Queuety::worker()->flush();

    \\\$status = Queuety\Queuety::workflow_status(\\\$wf_id);
    echo \\\$status->status->value . '|' . (\\\$status->state['processed'] ?? 'MISSING');
" 2>/dev/null || true)

if echo "$FULL_WF" | grep -q "completed"; then
    pass "full workflow completes via worker"
    assert_contains "$FULL_WF" "result from: data for user 42" "workflow state accumulates correctly"
else
    fail "full workflow completes via worker" "Got: $FULL_WF"
fi

echo ""
echo "--- WP-CLI: Runtime Artifacts ---"

ARTIFACT_WF_ID=$(wp_eval "
    \$wf_id = Queuety\Queuety::workflow('artifact_runtime')
        ->then('WpEnvArtifactStep')
        ->dispatch(['topic' => 'launch']);
    echo \$wf_id;
" 2>/dev/null || true)

if [ -n "$ARTIFACT_WF_ID" ] && [ "$ARTIFACT_WF_ID" != "0" ]; then
    pass "runtime artifact workflow dispatch via PHP API (ID: $ARTIFACT_WF_ID)"
    wp_flush > /dev/null 2>&1 || true

    ARTIFACT_STATUS=$(wp_cli queuety workflow status "$ARTIFACT_WF_ID" 2>/dev/null || true)
    assert_contains "$ARTIFACT_STATUS" "Artifacts: 1" "workflow status shows runtime artifact summary"

    RUNTIME_ARTIFACT=$(wp_cli queuety workflow artifact "$ARTIFACT_WF_ID" draft 2>/dev/null || true)
    assert_contains "$RUNTIME_ARTIFACT" "\"topic\": \"launch\"" "runtime artifact stores the workflow topic"
    assert_contains "$RUNTIME_ARTIFACT" "\"source\": \"wp-env\"" "runtime artifact stores metadata"
else
    fail "runtime artifact workflow dispatch via PHP API" "Could not create artifact workflow"
fi

echo ""
echo "=============================="
echo "Results: ${PASS} passed, ${FAIL} failed (${TOTAL} total)"
echo "=============================="

[ "$FAIL" -eq 0 ] || exit 1
