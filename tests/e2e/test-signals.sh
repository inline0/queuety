#!/usr/bin/env bash
#
# E2E test: workflow signal waits and human review helpers.
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

DB_HOST="${QUEUETY_TEST_DB_HOST:-127.0.0.1}"
DB_NAME="${QUEUETY_TEST_DB_NAME:-queuety_test}"
DB_USER="${QUEUETY_TEST_DB_USER:-root}"
DB_PASS="${QUEUETY_TEST_DB_PASS:-}"
DB_PREFIX="${QUEUETY_TEST_DB_PREFIX:-e2e_}"

if ! php -r "new PDO('mysql:host=${DB_HOST}', '${DB_USER}', '${DB_PASS}');" 2>/dev/null; then
    echo "SKIP: MySQL not available at ${DB_HOST}"
    exit 0
fi

php -r "
    \$pdo = new PDO('mysql:host=${DB_HOST}', '${DB_USER}', '${DB_PASS}');
    \$pdo->exec('CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`');
"

php -r "
    require '${PROJECT_DIR}/vendor/autoload.php';

    use Queuety\Connection;
    use Queuety\Config;
    use Queuety\Enums\WorkflowStatus;
    use Queuety\HandlerRegistry;
    use Queuety\Logger;
    use Queuety\Queue;
    use Queuety\Queuety;
    use Queuety\Schema;
    use Queuety\Step;
    use Queuety\Worker;
    use Queuety\Workflow;

    class ReviewFinalizeStep implements Step {
        public function handle(array \$state): array {
            return array(
                'review_outcome' => ! empty(\$state['approval']['approved']) ? 'approved' : 'pending',
                'revision_note'  => \$state['revision_notes']['note'] ?? null,
            );
        }
        public function config(): array { return array(); }
    }

    class DecisionFinalizeStep implements Step {
        public function handle(array \$state): array {
            return array(
                'decision_outcome' => \$state['review']['outcome'] ?? 'unknown',
                'decision_reason'  => \$state['review']['data']['reason'] ?? null,
            );
        }
        public function config(): array { return array(); }
    }

    \$conn = new Connection('${DB_HOST}', '${DB_NAME}', '${DB_USER}', '${DB_PASS}', '${DB_PREFIX}');

    Schema::uninstall(\$conn);
    Schema::install(\$conn);

    Queuety::reset();
    Queuety::init(\$conn);

    \$queue = new Queue(\$conn);
    \$logger = new Logger(\$conn);
    \$workflow_mgr = new Workflow(\$conn, \$queue, \$logger);
    \$worker = new Worker(\$conn, \$queue, \$logger, \$workflow_mgr, new HandlerRegistry(), new Config());

    \$process_one = static function () use (\$queue, \$worker) {
        \$job = \$queue->claim();
        assert(null !== \$job, 'Expected a queued job to process.');
        \$worker->process_job(\$job);
    };

    echo \"Test 1: approval and input flow\\n\";
    \$approval_id = Queuety::workflow('approval_flow')
        ->wait_for_approval(result_key: 'approval')
        ->wait_for_input(result_key: 'revision_notes')
        ->then(ReviewFinalizeStep::class)
        ->dispatch(array('draft_id' => 7));

    \$process_one();

    \$status = Queuety::workflow_status(\$approval_id);
    assert(\$status->status === WorkflowStatus::WaitingForSignal, 'Workflow should wait for approval.');
    assert(\$status->waiting_for === array('approval'), 'Workflow should wait for approval signal.');

    Queuety::approve_workflow(\$approval_id, array('approved' => true, 'by' => 'editor'));
    \$process_one();

    \$status = Queuety::workflow_status(\$approval_id);
    assert(\$status->status === WorkflowStatus::WaitingForSignal, 'Workflow should wait for input after approval.');
    assert((\$status->state['approval']['by'] ?? null) === 'editor', 'Approval payload should be stored.');

    Queuety::submit_workflow_input(\$approval_id, array('note' => 'ship it'));
    \$process_one();

    \$status = Queuety::workflow_status(\$approval_id);
    assert(\$status->status === WorkflowStatus::Completed, 'Workflow should complete after input.');
    assert((\$status->state['review_outcome'] ?? null) === 'approved', 'Review outcome should be approved.');
    assert((\$status->state['revision_note'] ?? null) === 'ship it', 'Revision note should be persisted.');
    echo \"PASS: approval and input flow\\n\";

    echo \"Test 2: decision reject flow\\n\";
    \$decision_id = Queuety::workflow('decision_flow')
        ->wait_for_decision(result_key: 'review')
        ->then(DecisionFinalizeStep::class)
        ->dispatch();

    \$process_one();

    \$status = Queuety::workflow_status(\$decision_id);
    assert(\$status->status === WorkflowStatus::WaitingForSignal, 'Decision workflow should wait for a decision.');
    assert(\$status->wait_mode === 'any', 'Decision workflow should wait for any matching signal.');

    Queuety::reject_workflow(\$decision_id, array('reason' => 'needs citations'));
    \$process_one();

    \$status = Queuety::workflow_status(\$decision_id);
    assert(\$status->status === WorkflowStatus::Completed, 'Decision workflow should complete after rejection.');
    assert((\$status->state['decision_outcome'] ?? null) === 'rejected', 'Decision outcome should be rejected.');
    assert((\$status->state['decision_reason'] ?? null) === 'needs citations', 'Decision reason should be captured.');
    echo \"PASS: decision reject flow\\n\";

    Schema::uninstall(\$conn);
    echo \"\\nAll signal E2E tests passed.\\n\";
"

echo "Signals E2E: PASS"
