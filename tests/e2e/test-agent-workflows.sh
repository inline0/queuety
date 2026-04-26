#!/usr/bin/env bash
#
# E2E test: started top-level workflows, quorum waits, and impossible quorum failure.
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
    use Queuety\Enums\Priority;
    use Queuety\Enums\WaitMode;
    use Queuety\Enums\WorkflowStatus;
    use Queuety\HandlerRegistry;
    use Queuety\Logger;
    use Queuety\Queue;
    use Queuety\Queuety;
    use Queuety\Schema;
    use Queuety\Step;
    use Queuety\Worker;
    use Queuety\Workflow;

    class AgentTaskStep implements Step {
        public function handle(array \$state): array {
            if (!empty(\$state['should_fail'])) {
                throw new RuntimeException('Simulated agent failure.');
            }

            return array(
                'topic' => \$state['topic'] ?? 'unknown',
                'done'  => true,
            );
        }
        public function config(): array { return array(); }
    }

    class AgentSummaryStep implements Step {
        public function handle(array \$state): array {
            \$results = \$state['agent_results'] ?? array();
            \$topics = array();
            foreach (\$results as \$result) {
                if (is_array(\$result) && isset(\$result['topic'])) {
                    \$topics[] = \$result['topic'];
                }
            }
            sort(\$topics);

            return array(
                'completed_count'  => count(\$results),
                'completed_topics' => \$topics,
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

    \$process_one = static function () use (\$queue, \$worker): void {
        \$job = \$queue->claim();
        assert(null !== \$job, 'Expected a queued job while processing the workflow graph.');
        \$worker->process_job(\$job);
    };

    echo \"Test 1: started agent group quorum success\\n\";
    \$child = Queuety::workflow('agent_task')
        ->then(AgentTaskStep::class)
        ->max_attempts(1);

    \$success_id = Queuety::workflow('agent_parent_success')
        ->with_priority(Priority::Urgent)
        ->start_agents('agent_tasks', \$child, group_key: 'researchers')
        ->wait_for_agent_group('researchers', WaitMode::Quorum, 2, 'agent_results')
        ->then(AgentSummaryStep::class)
        ->dispatch(
            array(
                'agent_tasks' => array(
                    array('topic' => 'pricing'),
                    array('topic' => 'reviews'),
                    array('topic' => 'faq', 'should_fail' => true),
                ),
            )
        );

    \$process_one();

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->status === WorkflowStatus::Running, 'Parent should still be running after starting agents.');

    \$process_one();

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->status === WorkflowStatus::WaitingForWorkflows, 'Parent should wait on the agent group.');
    assert(\$status->wait_mode === 'quorum', 'Parent should be waiting with quorum mode.');

    \$process_one();

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->status === WorkflowStatus::WaitingForWorkflows, 'Parent should keep waiting after the first matching agent completes.');
    assert(count(\$status->wait_details['matched'] ?? array()) === 1, 'Parent should track one matched child after the first success.');

    \$process_one();

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->status === WorkflowStatus::Running, 'Parent should resume once quorum is satisfied.');
    assert(count(\$status->state['agent_results'] ?? array()) === 2, 'Parent should expose the quorum-sized agent result set.');

    \$process_one();

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->status === WorkflowStatus::Completed, 'Parent should complete after running the summary step.');
    assert((\$status->state['completed_count'] ?? null) === 2, 'Parent should continue after two agent results.');
    assert((\$status->state['completed_topics'] ?? array()) === array('pricing', 'reviews'), 'Parent should summarize the completed agent topics.');
    echo \"PASS: started agent group quorum success\\n\";

    echo \"Test 2: started agent group impossible quorum failure\\n\";
    \$failure_id = Queuety::workflow('agent_parent_failure')
        ->with_priority(Priority::Urgent)
        ->start_agents('agent_tasks', \$child, group_key: 'researchers')
        ->wait_for_agent_group('researchers', WaitMode::Quorum, 2, 'agent_results')
        ->then(AgentSummaryStep::class)
        ->dispatch(
            array(
                'agent_tasks' => array(
                    array('topic' => 'pricing'),
                    array('topic' => 'reviews', 'should_fail' => true),
                    array('topic' => 'faq', 'should_fail' => true),
                ),
            )
        );

    \$process_one();

    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::Running, 'Failure parent should still be running after starting agents.');

    \$process_one();

    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::WaitingForWorkflows, 'Failure parent should wait on the agent group.');
    assert(\$status->wait_mode === 'quorum', 'Failure parent should wait with quorum mode.');

    \$process_one();
    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::WaitingForWorkflows, 'Failure parent should still be waiting after one success.');
    assert(count(\$status->wait_details['matched'] ?? array()) === 1, 'Failure parent should track the first success.');

    \$process_one();
    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::WaitingForWorkflows, 'Failure parent should still be waiting after the first failed child.');
    assert(count(\$status->wait_details['failed'] ?? array()) === 1, 'Failure parent should track one failed child.');

    \$process_one();

    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::Failed, 'Parent should fail once quorum becomes impossible.');
    echo \"PASS: started agent group impossible quorum failure\\n\";

    Schema::uninstall(\$conn);
    echo \"\\nAll agent workflow E2E tests passed.\\n\";
"

echo "Agent Workflow E2E: PASS"
