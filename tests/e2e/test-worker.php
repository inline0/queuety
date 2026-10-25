<?php

require __DIR__ . '/database.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Queuety\Connection;
use Queuety\Schema;
use Queuety\Queue;
use Queuety\Logger;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Config;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Queuety;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;

// Test handlers.
class SuccessHandler implements Handler {
    public function handle(array $payload): void {
        // Simulate work.
        file_put_contents(
            sys_get_temp_dir() . '/queuety_e2e_success.txt',
            json_encode($payload)
        );
    }
    public function config(): array { return []; }
}

class CountingHandler implements Handler {
    public function handle(array $payload): void {
        $file = sys_get_temp_dir() . '/queuety_e2e_count.txt';
        $count = file_exists($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, $count + 1);
    }
    public function config(): array { return []; }
}

class FailOnceHandler implements Handler {
    public function handle(array $payload): void {
        $file = sys_get_temp_dir() . '/queuety_e2e_fail_once.txt';
        if (!file_exists($file)) {
            file_put_contents($file, '1');
            throw new RuntimeException('First attempt fails');
        }
    }
    public function config(): array { return []; }
}

$conn = new Connection($db_host, $db_name, $db_user, $db_pass, $db_prefix);

Schema::uninstall($conn);
Schema::install($conn);

$queue = new Queue($conn);
$logger = new Logger($conn);
$workflow_mgr = new Workflow($conn, $queue, $logger);
$registry = new HandlerRegistry();
$registry->register('success', SuccessHandler::class);
$registry->register('counting', CountingHandler::class);
$registry->register('fail_once', FailOnceHandler::class);
$worker = new Worker($conn, $queue, $logger, $workflow_mgr, $registry, new Config());

// Cleanup temp files.
@unlink(sys_get_temp_dir() . '/queuety_e2e_success.txt');
@unlink(sys_get_temp_dir() . '/queuety_e2e_count.txt');
@unlink(sys_get_temp_dir() . '/queuety_e2e_fail_once.txt');

// Test 1: Worker processes a job successfully.
echo "Test 1: Successful job processing\n";
$queue->dispatch('success', ['msg' => 'hello world']);
$processed = $worker->flush('default');
assert($processed === 1, 'Should process 1 job');
$content = file_get_contents(sys_get_temp_dir() . '/queuety_e2e_success.txt');
assert(str_contains($content, 'hello world'), 'Handler should receive payload');
echo "PASS: worker processes job with correct payload\n";

// Test 2: Worker processes multiple jobs.
echo "Test 2: Multiple jobs\n";
for ($i = 0; $i < 5; $i++) {
    $queue->dispatch('counting');
}
$processed = $worker->flush('default');
assert($processed === 5, 'Should process 5 jobs');
$count = (int) file_get_contents(sys_get_temp_dir() . '/queuety_e2e_count.txt');
assert($count === 5, 'Handler should execute 5 times');
echo "PASS: processes 5 jobs\n";

// Test 3: Logs are written.
echo "Test 3: Logging\n";
$logs = $logger->for_event(LogEvent::Completed);
assert(count($logs) >= 6, 'Should have at least 6 completed log entries');
echo "PASS: " . count($logs) . " completed log entries\n";

// Test 4: Retry on failure.
echo "Test 4: Retry on failure\n";
$fail_id = $queue->dispatch('fail_once', max_attempts: 3);
$worker->flush('default');

// First attempt fails, gets retried with backoff.
// The retry sets available_at in the future, so flush won't pick it up immediately.
$job = $queue->find($fail_id);
// Job should be pending (retried) or completed (if backoff was 0).
assert(
    $job->status === JobStatus::Pending || $job->status === JobStatus::Completed,
    'Job should be pending (retry) or completed (processed on retry). Got: ' . $job->status->value
);
echo "PASS: retry on failure\n";

// Test 5: Stale recovery.
echo "Test 5: Stale job recovery\n";
$stale_id = $queue->dispatch('success');
// Manually set it to processing with old reserved_at.
$table = $conn->table(Config::table_jobs());
$conn->pdo()->prepare(
    "UPDATE {$table} SET status = 'processing', reserved_at = '2020-01-01 00:00:00', attempts = 1 WHERE id = :id"
)->execute(['id' => $stale_id]);

$recovered = $worker->recover_stale();
assert($recovered === 1, 'Should recover 1 stale job');
$job = $queue->find($stale_id);
assert($job->status === JobStatus::Pending, 'Stale job should be reset to pending');
echo "PASS: stale recovery\n";

// Cleanup.
@unlink(sys_get_temp_dir() . '/queuety_e2e_success.txt');
@unlink(sys_get_temp_dir() . '/queuety_e2e_count.txt');
@unlink(sys_get_temp_dir() . '/queuety_e2e_fail_once.txt');
Schema::uninstall($conn);
echo "\nAll worker E2E tests passed.\n";
