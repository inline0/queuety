#!/usr/bin/env bash
#
# E2E test: spawned top-level workflows, quorum waits, and impossible quorum failure.
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
                'joined_count'  => count(\$results),
                'joined_topics' => \$topics,
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

    \$process_until = static function (callable \$condition, int \$maxJobs = 24) use (\$queue, \$worker): void {
        for (\$i = 0; \$i < \$maxJobs; \$i++) {
            if (\$condition()) {
                return;
            }

            \$job = \$queue->claim();
            assert(null !== \$job, 'Expected a queued job while processing the workflow graph.');
            \$worker->process_job(\$job);
        }

        assert(\$condition(), 'Condition was not satisfied within the allotted job budget.');
    };

    echo \"Test 1: spawned agent group quorum success\\n\";
    \$child = Queuety::workflow('agent_task')
        ->then(AgentTaskStep::class)
        ->max_attempts(1);

    \$success_id = Queuety::workflow('agent_parent_success')
        ->with_priority(Priority::Urgent)
        ->spawn_agents('agent_tasks', \$child, group_key: 'researchers')
        ->await_agent_group('researchers', WaitMode::Quorum, 2, 'agent_results')
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

    \$process_until(
        static fn (): bool => WorkflowStatus::WaitingWorkflow === Queuety::workflow_status(\$success_id)->status
    );

    \$status = Queuety::workflow_status(\$success_id);
    assert(\$status->wait_mode === 'quorum', 'Parent should be waiting with quorum mode.');

    \$process_until(
        static fn (): bool => WorkflowStatus::Completed === Queuety::workflow_status(\$success_id)->status
    );

    \$status = Queuety::workflow_status(\$success_id);
    assert((\$status->state['joined_count'] ?? null) === 2, 'Parent should continue after two agent results.');
    assert((\$status->state['joined_topics'] ?? array()) === array('pricing', 'reviews'), 'Parent should summarize the completed agent topics.');
    echo \"PASS: spawned agent group quorum success\\n\";

    echo \"Test 2: spawned agent group impossible quorum failure\\n\";
    \$failure_id = Queuety::workflow('agent_parent_failure')
        ->with_priority(Priority::Urgent)
        ->spawn_agents('agent_tasks', \$child, group_key: 'researchers')
        ->await_agent_group('researchers', WaitMode::Quorum, 2, 'agent_results')
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

    \$process_until(
        static fn (): bool => WorkflowStatus::Failed === Queuety::workflow_status(\$failure_id)->status
    );

    \$status = Queuety::workflow_status(\$failure_id);
    assert(\$status->status === WorkflowStatus::Failed, 'Parent should fail once quorum becomes impossible.');
    echo \"PASS: spawned agent group impossible quorum failure\\n\";

    Schema::uninstall(\$conn);
    echo \"\\nAll agent workflow E2E tests passed.\\n\";
"

echo "Agent Workflow E2E: PASS"
