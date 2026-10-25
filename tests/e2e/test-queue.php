<?php

require __DIR__ . '/database.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Queuety\Connection;
use Queuety\Schema;
use Queuety\Queue;
use Queuety\Logger;
use Queuety\Workflow;
use Queuety\Queuety;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;

$conn = new Connection($db_host, $db_name, $db_user, $db_pass, $db_prefix);

// Clean slate.
Schema::uninstall($conn);
Schema::install($conn);

Queuety::reset();
Queuety::init($conn);

// Test 1: Dispatch a job.
$pending = Queuety::dispatch('test_handler', ['key' => 'value']);
$job_id = $pending->id();
assert($job_id > 0, 'Job ID should be positive');
echo "PASS: dispatch (job #" . $job_id . ")\n";

// Test 2: Stats show 1 pending.
$stats = Queuety::stats();
assert($stats['pending'] === 1, 'Should have 1 pending job');
echo "PASS: stats shows 1 pending\n";

// Test 3: Claim the job.
$queue = new Queue($conn);
$job = $queue->claim('default');
assert($job !== null, 'Should claim a job');
assert($job->handler === 'test_handler', 'Handler should match');
assert($job->payload === ['key' => 'value'], 'Payload should match');
assert($job->status === JobStatus::Processing, 'Status should be processing');
echo "PASS: claim returns job with correct data\n";

// Test 4: Complete the job.
$queue->complete($job->id, claim_token: $job->claim_token);
$completed = $queue->find($job->id);
assert($completed->status === JobStatus::Completed, 'Should be completed');
echo "PASS: complete\n";

// Test 5: Dispatch and bury.
$id2 = $queue->dispatch('fail_handler', ['x' => 1]);
$job2 = $queue->claim('default');
$queue->bury($id2, 'Test error', claim_token: $job2->claim_token);
$buried = Queuety::buried();
assert(count($buried) === 1, 'Should have 1 buried job');
echo "PASS: bury and buried()\n";

// Test 6: Priority ordering.
$low = $queue->dispatch('handler', priority: Priority::Low);
$high = $queue->dispatch('handler', priority: Priority::High);
$urgent = $queue->dispatch('handler', priority: Priority::Urgent);
$claimed = $queue->claim('default');
assert($claimed->id === $urgent, 'Urgent should be claimed first');
$queue->complete($claimed->id, claim_token: $claimed->claim_token);
$claimed = $queue->claim('default');
assert($claimed->id === $high, 'High should be claimed second');
$queue->complete($claimed->id, claim_token: $claimed->claim_token);
echo "PASS: priority ordering\n";

// Cleanup.
Schema::uninstall($conn);
echo "\nAll queue E2E tests passed.\n";
