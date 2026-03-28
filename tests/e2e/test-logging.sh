#!/usr/bin/env bash
#
# E2E test: database logging.
#
# Tests that all lifecycle events are logged to the queuety_logs table
# and that log queries work correctly.

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
    use Queuety\Worker;
    use Queuety\Config;
    use Queuety\Handler;
    use Queuety\Step;
    use Queuety\HandlerRegistry;
    use Queuety\Queuety;
    use Queuety\Enums\LogEvent;

    class LogTestHandler implements Handler {
        public function handle(array \$payload): void {}
        public function config(): array { return []; }
    }

    class LogTestStep implements Step {
        public function handle(array \$state): array {
            return ['logged' => true];
        }
        public function config(): array { return []; }
    }

    \$conn = new Connection('${DB_HOST}', '${DB_NAME}', '${DB_USER}', '${DB_PASS}', '${DB_PREFIX}');

    Schema::uninstall(\$conn);
    Schema::install(\$conn);

    Queuety::reset();
    Queuety::init(\$conn);

    \$queue = new Queue(\$conn);
    \$logger = new Logger(\$conn);
    \$workflow_mgr = new Workflow(\$conn, \$queue, \$logger);
    \$registry = new HandlerRegistry();
    \$registry->register('log_test', LogTestHandler::class);
    \$worker = new Worker(\$conn, \$queue, \$logger, \$workflow_mgr, \$registry, new Config());

    // Test 1: Simple job logs started + completed.
    echo \"Test 1: Job logging\n\";
    \$job_id = \$queue->dispatch('log_test', ['x' => 1]);
    \$worker->flush('default');

    \$job_logs = \$logger->for_job(\$job_id);
    \$events = array_column(\$job_logs, 'event');
    assert(in_array('started', \$events), 'Should have started event');
    assert(in_array('completed', \$events), 'Should have completed event');
    echo \"PASS: job logs started + completed\n\";

    // Test 2: Workflow logs lifecycle.
    echo \"Test 2: Workflow logging\n\";
    \$wf_id = Queuety::workflow('log_workflow')
        ->then(LogTestStep::class)
        ->then(LogTestStep::class)
        ->dispatch([]);

    \$worker->flush('default');
    \$worker->flush('default');

    \$wf_logs = \$logger->for_workflow(\$wf_id);
    \$events = array_column(\$wf_logs, 'event');
    assert(in_array('workflow_started', \$events), 'Should have workflow_started');
    assert(in_array('workflow_completed', \$events), 'Should have workflow_completed');
    assert(in_array('completed', \$events), 'Should have step completed events');
    echo \"PASS: workflow logs full lifecycle (\" . count(\$wf_logs) . \" entries)\n\";

    // Test 3: Query by handler.
    echo \"Test 3: Query by handler\n\";
    \$handler_logs = \$logger->for_handler('log_test');
    assert(count(\$handler_logs) >= 2, 'Should have logs for log_test handler');
    echo \"PASS: query by handler\n\";

    // Test 4: Query by event.
    echo \"Test 4: Query by event\n\";
    \$completed_logs = \$logger->for_event(LogEvent::Completed);
    assert(count(\$completed_logs) >= 3, 'Should have multiple completed events');
    echo \"PASS: query by event\n\";

    // Test 5: Query since timestamp.
    echo \"Test 5: Query since timestamp\n\";
    \$since = new DateTimeImmutable('-1 hour');
    \$recent_logs = \$logger->since(\$since, 100);
    assert(count(\$recent_logs) > 0, 'Should have recent logs');
    echo \"PASS: query since timestamp\n\";

    // Test 6: Log entries have duration.
    echo \"Test 6: Duration tracking\n\";
    \$completed_entries = array_filter(\$job_logs, fn(\$l) => \$l['event'] === 'completed');
    \$completed_entry = reset(\$completed_entries);
    assert(\$completed_entry['duration_ms'] !== null, 'Completed entries should have duration');
    echo \"PASS: duration tracking\n\";

    // Cleanup.
    Schema::uninstall(\$conn);
    echo \"\nAll logging E2E tests passed.\n\";
"

echo "Logging E2E: PASS"
