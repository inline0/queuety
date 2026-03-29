#!/usr/bin/env bash
#
# E2E test: workflow lifecycle.
#
# Tests multi-step workflow dispatch, step advancement, state accumulation,
# pause/resume, failure and retry.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

DB_HOST="${QUEUETY_TEST_DB_HOST:-127.0.0.1}"
DB_NAME="${QUEUETY_TEST_DB_NAME:-queuety_test}"
DB_USER="${QUEUETY_TEST_DB_USER:-root}"
DB_PASS="${QUEUETY_TEST_DB_PASS:-}"
DB_PREFIX="${QUEUETY_TEST_DB_PREFIX:-e2e_}"

# Check MySQL is available.
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
    use Queuety\Schema;
    use Queuety\Queue;
    use Queuety\Logger;
    use Queuety\Workflow;
    use Queuety\WorkflowBuilder;
    use Queuety\Queuety;
    use Queuety\Step;
    use Queuety\Handler;
    use Queuety\HandlerRegistry;
    use Queuety\Worker;
    use Queuety\Config;
    use Queuety\Enums\WorkflowStatus;
    use Queuety\Enums\JobStatus;

    // Test step handlers.
    class Step1 implements Step {
        public function handle(array \$state): array {
            return ['step1_result' => 'hello from step 1', 'user_name' => 'Test User'];
        }
        public function config(): array { return []; }
    }

    class Step2 implements Step {
        public function handle(array \$state): array {
            // Verify we have data from step 1.
            assert(\$state['step1_result'] === 'hello from step 1', 'Step 2 should see step 1 data');
            assert(\$state['user_name'] === 'Test User', 'Step 2 should see user_name');
            return ['step2_result' => 'processed by step 2'];
        }
        public function config(): array { return []; }
    }

    class Step3 implements Step {
        public function handle(array \$state): array {
            // Verify accumulated state.
            assert(\$state['step1_result'] === 'hello from step 1', 'Step 3 should see step 1 data');
            assert(\$state['step2_result'] === 'processed by step 2', 'Step 3 should see step 2 data');
            return ['final_output' => 'done'];
        }
        public function config(): array { return []; }
    }

    class FailingStep implements Step {
        public function handle(array \$state): array {
            throw new RuntimeException('Intentional failure');
        }
        public function config(): array { return []; }
    }

    \$conn = new Connection('${DB_HOST}', '${DB_NAME}', '${DB_USER}', '${DB_PASS}', '${DB_PREFIX}');

    Schema::uninstall(\$conn);
    Schema::install(\$conn);

    Queuety::reset();
    Queuety::init(\$conn);

    \$queue_ops = new Queue(\$conn);
    \$logger = new Logger(\$conn);
    \$workflow_mgr = new Workflow(\$conn, \$queue_ops, \$logger);
    \$registry = new HandlerRegistry();
    \$worker = new Worker(\$conn, \$queue_ops, \$logger, \$workflow_mgr, \$registry, new Config());

    // Test 1: Full 3-step workflow.
    echo \"Test 1: Full 3-step workflow lifecycle\n\";

    \$wf_id = Queuety::workflow('test_workflow')
        ->then(Step1::class)
        ->then(Step2::class)
        ->then(Step3::class)
        ->dispatch(['initial_data' => 42]);

    assert(\$wf_id > 0, 'Workflow ID should be positive');

    \$status = Queuety::workflow_status(\$wf_id);
    assert(\$status->status === WorkflowStatus::Running, 'Workflow should be running');
    assert(\$status->current_step === 0, 'Should be at step 0');
    assert(\$status->total_steps === 3, 'Should have 3 steps');

    // Process step 0.
    \$worker->flush('default');

    \$status = Queuety::workflow_status(\$wf_id);
    assert(\$status->current_step === 1, 'Should advance to step 1');
    assert(\$status->state['step1_result'] === 'hello from step 1', 'State should have step 1 data');

    // Process step 1.
    \$worker->flush('default');

    \$status = Queuety::workflow_status(\$wf_id);
    assert(\$status->current_step === 2, 'Should advance to step 2');
    assert(\$status->state['step2_result'] === 'processed by step 2', 'State should have step 2 data');

    // Process step 2.
    \$worker->flush('default');

    \$status = Queuety::workflow_status(\$wf_id);
    assert(\$status->status === WorkflowStatus::Completed, 'Workflow should be completed');
    assert(\$status->state['final_output'] === 'done', 'Should have final output');
    assert(\$status->state['initial_data'] === 42, 'Should preserve initial data');
    echo \"PASS: 3-step workflow completed with state accumulation\n\";

    // Test 2: Logs were written.
    echo \"Test 2: Workflow logs\n\";
    \$logs = \$logger->for_workflow(\$wf_id);
    assert(count(\$logs) > 0, 'Should have log entries');
    echo \"PASS: \" . count(\$logs) . \" log entries for workflow\n\";

    // Test 3: Pause/resume.
    echo \"Test 3: Pause and resume\n\";
    Schema::uninstall(\$conn);
    Schema::install(\$conn);

    Queuety::reset();
    Queuety::init(\$conn);
    \$queue_ops = new Queue(\$conn);
    \$logger = new Logger(\$conn);
    \$workflow_mgr = new Workflow(\$conn, \$queue_ops, \$logger);
    \$worker = new Worker(\$conn, \$queue_ops, \$logger, \$workflow_mgr, \$registry, new Config());

    \$wf_id2 = Queuety::workflow('pausable')
        ->then(Step1::class)
        ->then(Step2::class)
        ->then(Step3::class)
        ->dispatch([]);

    // Pause before step 0 completes so the current step can finish, but the next
    // step is not enqueued until resume.
    Queuety::pause_workflow(\$wf_id2);
    \$status = Queuety::workflow_status(\$wf_id2);
    assert(\$status->status === WorkflowStatus::Paused, 'Should be paused');

    // Process step 0 while paused. The workflow advances, but step 1 is not enqueued yet.
    \$worker->flush('default');
    \$status = Queuety::workflow_status(\$wf_id2);
    assert(\$status->status === WorkflowStatus::Paused, 'Should remain paused');
    assert(\$status->current_step === 1, 'Should have advanced to step 1 position');
    assert(\$queue_ops->claim('default') === null, 'Next step should not be enqueued while paused');

    // Resume.
    Queuety::resume_workflow(\$wf_id2);
    \$status = Queuety::workflow_status(\$wf_id2);
    assert(\$status->status === WorkflowStatus::Running, 'Should be running again');

    // Process remaining steps.
    \$worker->flush('default');
    \$worker->flush('default');
    \$status = Queuety::workflow_status(\$wf_id2);
    assert(\$status->status === WorkflowStatus::Completed, 'Should complete after resume');
    echo \"PASS: pause and resume\n\";

    // Test 4: Failure and retry.
    echo \"Test 4: Failure and retry\n\";
    Schema::uninstall(\$conn);
    Schema::install(\$conn);

    Queuety::reset();
    Queuety::init(\$conn);
    \$queue_ops = new Queue(\$conn);
    \$logger = new Logger(\$conn);
    \$workflow_mgr = new Workflow(\$conn, \$queue_ops, \$logger);
    \$worker = new Worker(\$conn, \$queue_ops, \$logger, \$workflow_mgr, \$registry, new Config());

    \$wf_id3 = Queuety::workflow('failing')
        ->then(Step1::class)
        ->then(FailingStep::class)
        ->then(Step3::class)
        ->max_attempts(1)
        ->dispatch([]);

    // Process step 0.
    \$worker->flush('default');

    // Process step 1 (fails, max_attempts=1 so it buries immediately).
    \$worker->flush('default');

    \$status = Queuety::workflow_status(\$wf_id3);
    assert(\$status->status === WorkflowStatus::Failed, 'Workflow should be failed');
    assert(\$status->state['step1_result'] === 'hello from step 1', 'Should preserve state from completed steps');
    echo \"PASS: workflow failure preserves state\n\";

    // Cleanup.
    Schema::uninstall(\$conn);
    echo \"\nAll workflow E2E tests passed.\n\";
"

echo "Workflow E2E: PASS"
