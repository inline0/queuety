# Queuety - Product Requirements Document

## One-liner

A job queue and durable workflow engine for WordPress that doesn't boot WordPress.

## Problem

WordPress has no real background job system. `wp_cron` is fake cron that only fires when someone visits the site. Action Scheduler (used by WooCommerce) fills the gap but is slow, bloated, and stores millions of rows in the database on active sites.

The core issue: every existing solution boots the entire WordPress stack to process a single job. That means loading hundreds of PHP files, initializing plugins, parsing themes, and connecting to the database through `$wpdb` with all its overhead. For a job that might just send an email or update a row, this is absurd.

But there's a deeper problem: WordPress has no way to run **workflows**, multi-step processes that survive PHP timeouts, retries, and crashes. An LLM API call that takes 60 seconds will get killed by PHP's 30-second timeout. There's no built-in way to break long-running work into durable steps that persist state and resume where they left off.

## Solution

Queuety is two things in one:

1. **A fast job queue** that processes jobs from a minimal PHP bootstrap, not from inside WordPress. It connects to the database directly, claims jobs atomically, executes them, and moves on.

2. **A durable workflow engine** that breaks long-running processes into steps. Each step persists its output to a shared state bag. If PHP dies, the worker resumes at the failed step with all prior state intact. A simple job is just a workflow with one step.

For jobs/steps that DO need WordPress context (like calling `wp_mail` or using plugin APIs), Queuety can optionally boot WordPress. But the default path is fast: direct database, direct execution.

## PHP 8.2+

Queuety requires PHP 8.2 or higher. This enables:

### Enums for domain modeling

```php
enum JobStatus: string {
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
    case Buried     = 'buried';
}

enum WorkflowStatus: string {
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Paused    = 'paused';
}

enum Priority: int {
    case Low    = 0;
    case Normal = 1;
    case High   = 2;
    case Urgent = 3;
}

enum BackoffStrategy: string {
    case Fixed       = 'fixed';
    case Linear      = 'linear';
    case Exponential = 'exponential';
}
```

No magic strings, no integer constants. Type-safe, IDE-autocompleted, exhaustive `match()`.

### Readonly classes for immutable models

```php
readonly class WorkflowState {
    public function __construct(
        public int $workflowId,
        public string $name,
        public WorkflowStatus $status,
        public int $currentStep,
        public int $totalSteps,
        public array $state,
    ) {}
}
```

### Constructor property promotion

Clean models without boilerplate. Combined with readonly, the Job and Workflow data objects are immutable and concise.

### Named arguments for clean dispatch

```php
Queuety::dispatch(
    handler: 'send_email',
    payload: ['to' => $email],
    queue: 'emails',
    priority: Priority::High,
    delay: 300,
);
```

### Match expressions

Exhaustive, compiler-checked branching on enums. No missing cases, no fallthrough bugs.

## Architecture

### Schema

Three tables in WordPress's existing MySQL database (prefixed with `$wpdb->prefix`):

```sql
CREATE TABLE queuety_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(64) NOT NULL DEFAULT 'default',
    handler VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    priority TINYINT NOT NULL DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed', 'buried') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    failed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    workflow_id BIGINT UNSIGNED DEFAULT NULL,
    step_index TINYINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_status_available (queue, status, available_at, priority),
    INDEX idx_status (status),
    INDEX idx_reserved (status, reserved_at),
    INDEX idx_workflow (workflow_id, step_index)
) ENGINE=InnoDB;

CREATE TABLE queuety_workflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status ENUM('running', 'completed', 'failed', 'paused') NOT NULL DEFAULT 'running',
    state LONGTEXT NOT NULL DEFAULT '{}',
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total_steps TINYINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    failed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE queuety_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED DEFAULT NULL,
    workflow_id BIGINT UNSIGNED DEFAULT NULL,
    step_index TINYINT UNSIGNED DEFAULT NULL,
    handler VARCHAR(255) NOT NULL,
    queue VARCHAR(64) NOT NULL,
    event ENUM('started', 'completed', 'failed', 'buried', 'retried', 'workflow_started', 'workflow_completed', 'workflow_failed', 'workflow_paused', 'workflow_resumed') NOT NULL,
    attempt TINYINT UNSIGNED DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    memory_peak_kb INT UNSIGNED DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_class VARCHAR(255) DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    context JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job (job_id),
    INDEX idx_workflow (workflow_id),
    INDEX idx_handler (handler),
    INDEX idx_event (event),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
```

Simple jobs have `workflow_id = NULL` and work exactly like a traditional queue. Workflow steps reference their parent workflow and carry a step index. The workflow's `state` column is the durable state bag that accumulates data across steps.

Completed jobs/workflows are auto-purged after a configurable retention period (default: 7 days). **Logs are kept forever** by default, providing a permanent audit trail of all job and workflow executions. An optional `QUEUETY_LOG_RETENTION_DAYS` constant can be set to prune old log entries.

### Job lifecycle (simple jobs)

```
pending -> processing -> completed
                     \-> failed (retry) -> pending
                     \-> buried (max attempts reached)
```

- **Pending**: waiting to be picked up
- **Processing**: claimed by a worker, `reserved_at` set
- **Completed**: done, auto-purged after retention period
- **Failed**: execution threw an exception, will be retried after backoff
- **Buried**: exceeded max attempts, moved to dead letter state for manual inspection

### Workflow lifecycle

```
running -> completed (all steps done)
       \-> failed (step buried after max attempts)
       \-> paused (manually paused or waiting for external input)
```

When a workflow is created, only the first step is enqueued as a job. When that step completes, its return data is merged into the workflow's `state`, `current_step` is advanced, and the next step is enqueued. Each step is a normal job that gets claimed and executed by workers. The workflow orchestration happens at the boundary between steps.

If a step fails and retries are exhausted (buried), the workflow is marked as failed. The state bag preserves everything up to the point of failure, so the workflow can be retried from the failed step without re-running previous steps.

```
Workflow: generate_report (3 steps)

Step 0: fetch_data     -> completes -> state: {user_data: {...}}
Step 1: call_llm       -> PHP dies  -> retry -> completes -> state: {user_data: {...}, llm_response: "..."}
Step 2: format_output  -> completes -> state: {user_data: {...}, llm_response: "...", report_url: "/reports/42.pdf"}

Workflow: completed
```

The key insight: each step is short enough to fit within PHP's execution limits. The workflow is the durable unit that spans across multiple PHP processes.

### Resumability: how it works

The critical moment is the boundary between steps. When a step completes, the worker performs these operations **in a single database transaction**:

```sql
BEGIN;
-- 1. Merge step output into workflow state, advance current_step
UPDATE queuety_workflows
SET state = '{"user_data": {...}, "llm_response": "..."}',
    current_step = 2
WHERE id = 42;

-- 2. Mark current step job as completed
UPDATE queuety_jobs
SET status = 'completed', completed_at = NOW()
WHERE id = 789;

-- 3. Enqueue next step as a new pending job
INSERT INTO queuety_jobs (queue, handler, payload, workflow_id, step_index, status)
VALUES ('default', 'FormatOutputHandler', '{}', 42, 2, 'pending');

-- 4. Log step completion
INSERT INTO queuety_logs (job_id, workflow_id, step_index, handler, queue, event, attempt, duration_ms, memory_peak_kb)
VALUES (789, 42, 1, 'CallLLMHandler', 'default', 'completed', 2, 1847, 4096);
COMMIT;
```

All four happen atomically. If PHP dies at any point:

- **During step execution**: the job stays in `processing` status. Stale timeout detects it, resets to `pending`, worker retries the step with all prior workflow state intact.
- **After step execution but before commit**: transaction rolls back. Same as above, the step re-runs.
- **After commit**: next step is already enqueued, workflow state is saved, log is written. Nothing lost.

There is no window where state can be lost. The database transaction is the durability guarantee.

### Stale job recovery

A job stuck in `processing` longer than `QUEUETY_STALE_TIMEOUT` (default: 600 seconds) is considered stale. This means the worker that claimed it died without completing it. The recovery process:

1. Find jobs where `status = 'processing'` and `reserved_at < NOW() - STALE_TIMEOUT`
2. If `attempts < max_attempts`: reset to `pending`, increment `attempts`, set `available_at` with backoff
3. If `attempts >= max_attempts`: set to `buried`, mark parent workflow as `failed` if applicable

This runs inside the worker loop (each worker checks for stale jobs periodically) or via `wp queuety recover`.

### Workers

Workers are long-running PHP processes started via WP-CLI:

```bash
# Start a worker (processes jobs until stopped)
wp queuety work

# Start a worker for a specific queue
wp queuety work --queue=emails

# Start with concurrency (multiple workers)
wp queuety work --workers=4

# Process one batch and exit (for cron)
wp queuety work --once

# Process all pending jobs and exit
wp queuety flush
```

Workers boot from a minimal bootstrap that loads:
1. Database credentials from `wp-config.php` (parsed, not executed)
2. The Queuety autoloader
3. Job/step handler classes

They do NOT load WordPress core, plugins, or themes unless a specific handler requests it.

Workers don't distinguish between simple jobs and workflow steps. They claim the next available job from `queuety_jobs`. The workflow orchestration logic (advancing steps, merging state) runs after a step completes, inside the worker process.

### Job claiming (atomic)

```sql
UPDATE queuety_jobs
SET status = 'processing', reserved_at = NOW()
WHERE status = 'pending'
  AND queue = 'default'
  AND available_at <= NOW()
ORDER BY priority DESC, id ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

`FOR UPDATE SKIP LOCKED` ensures multiple workers can run concurrently without conflicts. Each worker claims its own jobs atomically.

### Priority levels

Modeled as a backed enum:

```php
enum Priority: int {
    case Low    = 0;  // default
    case Normal = 1;
    case High   = 2;
    case Urgent = 3;
}
```

Higher priority jobs are processed first within the same queue.

### Delayed jobs

Jobs can be scheduled for future execution:

```php
Queuety::dispatch('send_reminder', ['user_id' => 42])
    ->delay(3600); // Execute in 1 hour
```

This sets `available_at` to a future timestamp. Workers skip jobs where `available_at > NOW()`.

### Recurring jobs

```php
Queuety::schedule('daily_report', ['type' => 'sales'])
    ->every('24 hours');

Queuety::schedule('cleanup', [])
    ->cron('0 3 * * *'); // 3 AM daily
```

Recurring jobs are stored in a separate `queuety_schedules` table. A scheduler process checks for due schedules and enqueues jobs.

### Rate limiting

```php
Queuety::dispatch('send_email', ['to' => $email])
    ->rateLimit(10, 60); // Max 10 per 60 seconds
```

Rate limits are enforced per handler. Workers pause processing for a handler when its rate limit is reached.

## API

### PHP API: simple jobs (static facade)

```php
use Queuety\Queuety;

// Dispatch a job
Queuety::dispatch('handler_name', ['key' => 'value']);

// Dispatch with options
Queuety::dispatch('send_email', ['to' => 'user@example.com'])
    ->onQueue('emails')
    ->withPriority(Priority::High)
    ->delay(300)
    ->maxAttempts(5);

// Dispatch multiple jobs
Queuety::batch([
    ['handler' => 'process_image', 'payload' => ['id' => 1]],
    ['handler' => 'process_image', 'payload' => ['id' => 2]],
    ['handler' => 'process_image', 'payload' => ['id' => 3]],
]);

// Queue status
$stats = Queuety::stats();
// ['pending' => 42, 'processing' => 3, 'completed' => 1200, 'failed' => 5, 'buried' => 1]

// Inspect buried jobs
$buried = Queuety::buried();

// Retry all buried jobs
Queuety::retryBuried();

// Retry a specific job
Queuety::retry(123);

// Purge completed jobs
Queuety::purge();

// Pause/resume a queue
Queuety::pause('emails');
Queuety::resume('emails');
```

### PHP API: workflows

```php
use Queuety\Queuety;

// Define and dispatch a workflow
Queuety::workflow('generate_report')
    ->then(FetchDataHandler::class)
    ->then(CallLLMHandler::class)
    ->then(FormatOutputHandler::class)
    ->dispatch(['user_id' => 42]);

// With options
Queuety::workflow('process_order')
    ->then(ValidateOrderHandler::class)
    ->then(ChargePaymentHandler::class)
    ->then(FulfillOrderHandler::class)
    ->onQueue('orders')
    ->withPriority(Priority::High)
    ->maxAttempts(5)               // per step
    ->dispatch(['order_id' => 123]);

// Check workflow status
$workflow = Queuety::workflowStatus($workflowId);
// WorkflowState {
//     workflowId: 42,
//     name: 'generate_report',
//     status: WorkflowStatus::Running,
//     currentStep: 1,
//     totalSteps: 3,
//     state: ['user_data' => [...], 'llm_response' => '...'],
// }

// Retry a failed workflow (resumes from the failed step)
Queuety::retryWorkflow($workflowId);

// Pause a running workflow (current step finishes, next step is not enqueued)
Queuety::pauseWorkflow($workflowId);

// Resume a paused workflow
Queuety::resumeWorkflow($workflowId);
```

### Job handlers (simple jobs)

```php
class SendEmailHandler implements Queuety\Handler {
    public function handle(array $payload): void {
        wp_mail($payload['to'], $payload['subject'], $payload['body']);
    }

    public function config(): array {
        return [
            'queue' => 'emails',
            'max_attempts' => 5,
            'rate_limit' => [10, 60],
            'needs_wordpress' => true,
        ];
    }
}

Queuety::register('send_email', SendEmailHandler::class);
```

### Step handlers (workflows)

Step handlers receive the accumulated workflow state and return data that gets merged in:

```php
class FetchDataHandler implements Queuety\Step {
    public function handle(array $state): array {
        $user = get_user_by('ID', $state['user_id']);
        $orders = wc_get_orders(['customer_id' => $user->ID, 'limit' => 50]);

        return [
            'user_name' => $user->display_name,
            'order_count' => count($orders),
            'order_data' => array_map(fn($o) => $o->get_data(), $orders),
        ];
    }

    public function config(): array {
        return ['needs_wordpress' => true];
    }
}

class CallLLMHandler implements Queuety\Step {
    public function handle(array $state): array {
        $prompt = "Summarize these {$state['order_count']} orders for {$state['user_name']}...";
        $response = $this->callOpenAI($prompt, $state['order_data']);

        return ['llm_response' => $response];
    }

    public function config(): array {
        return [
            'needs_wordpress' => false,
            'max_attempts' => 5,
        ];
    }
}

class FormatOutputHandler implements Queuety\Step {
    public function handle(array $state): array {
        $pdf_path = $this->generatePDF($state);
        return ['report_url' => $pdf_path];
    }
}
```

### WP-CLI commands

```bash
# Worker management
wp queuety work                          # Start worker
wp queuety work --queue=emails           # Specific queue
wp queuety work --workers=4              # Multiple workers
wp queuety work --once                   # Process one batch, exit
wp queuety flush                         # Process all pending, exit

# Job management
wp queuety dispatch <handler> --payload='{"key":"value"}'
wp queuety retry <id>
wp queuety retry-buried
wp queuety bury <id>
wp queuety delete <id>

# Workflow management
wp queuety workflow status <id>          # Show workflow state and progress
wp queuety workflow retry <id>           # Retry from failed step
wp queuety workflow pause <id>
wp queuety workflow resume <id>
wp queuety workflow list [--status=<status>]

# Queue management
wp queuety status                        # Show stats per queue
wp queuety list [--queue=<queue>] [--status=<status>]
wp queuety pause <queue>
wp queuety resume <queue>
wp queuety purge [--older-than=<days>]
wp queuety recover                       # Recover stale jobs

# Logs
wp queuety log [--job=<id>]              # Show log entries for a job
wp queuety log [--workflow=<id>]         # Show full workflow history
wp queuety log [--handler=<name>]        # Filter by handler
wp queuety log [--event=<event>]         # Filter by event type
wp queuety log [--since=<datetime>]      # Filter by time
wp queuety log purge --older-than=<days> # Prune old log entries

# Scheduler
wp queuety schedule list
wp queuety schedule add <handler> --every=<interval>
wp queuety schedule remove <handler>
```

### WordPress hooks (for plugin integration)

```php
// Dispatch from WordPress hooks
add_action('woocommerce_order_status_completed', function ($order_id) {
    Queuety::dispatch('process_order', ['order_id' => $order_id])
        ->onQueue('orders')
        ->withPriority(Priority::High);
});

// Kick off a workflow from a hook
add_action('init_weekly_report', function ($user_id) {
    Queuety::workflow('weekly_report')
        ->then(FetchDataHandler::class)
        ->then(CallLLMHandler::class)
        ->then(SendReportEmailHandler::class)
        ->dispatch(['user_id' => $user_id]);
});

// Action Scheduler compatibility layer (drop-in replacement)
// as_enqueue_async_action() -> Queuety::dispatch()
// as_schedule_single_action() -> Queuety::dispatch()->delay()
// as_schedule_recurring_action() -> Queuety::schedule()->every()
```

## Logging

All logging goes to the `queuety_logs` database table. No log files.

### Why database, not files

- **Queryable**: filter by workflow, handler, event type, time range
- **Permanent**: workflow history survives forever by default (log retention is independent of job/workflow purging)
- **Inspectable**: `wp queuety log --workflow=42` gives you the full story
- **No file permissions**: no writable directories needed outside the database
- **Atomic**: log writes are part of the same transaction as state changes, so they're always consistent

### What gets logged

Every state change produces a log entry:

```
$ wp queuety log --workflow=42

ID     Event                Handler              Attempt  Duration  Time
1201   workflow_started     -                    -        -         14:23:00
1202   started              FetchDataHandler     1        -         14:23:00
1203   completed            FetchDataHandler     1        230ms     14:23:01
1204   started              CallLLMHandler       1        -         14:23:01
1205   failed               CallLLMHandler       1        30012ms   14:23:05
1206   retried              CallLLMHandler       2        -         14:23:09
1207   completed            CallLLMHandler       2        1847ms    14:23:11
1208   started              FormatOutputHandler  1        -         14:23:11
1209   completed            FormatOutputHandler  1        120ms     14:23:11
1210   workflow_completed   -                    -        -         14:23:11
```

Failed entries include `error_message`, `error_class`, and `error_trace`. The `context` JSON column stores additional metadata (memory usage, custom data from handlers).

### WordPress hooks (for live integrations)

Queuety also fires WordPress action hooks at key lifecycle points. Plugins can react to these for dashboards, Slack alerts, metrics, or external logging:

```php
// Job lifecycle
do_action('queuety_job_started', int $job_id, string $handler);
do_action('queuety_job_completed', int $job_id, string $handler, int $duration_ms);
do_action('queuety_job_failed', int $job_id, string $handler, Throwable $exception);
do_action('queuety_job_buried', int $job_id, string $handler, Throwable $exception);

// Workflow lifecycle
do_action('queuety_workflow_started', int $workflow_id, string $name, int $total_steps);
do_action('queuety_workflow_step_completed', int $workflow_id, int $step_index, string $handler, int $duration_ms);
do_action('queuety_workflow_completed', int $workflow_id, string $name, array $final_state);
do_action('queuety_workflow_failed', int $workflow_id, string $name, int $failed_step, Throwable $exception);

// Worker lifecycle
do_action('queuety_worker_started', int $pid);
do_action('queuety_worker_stopped', int $pid, string $reason);
```

These hooks only fire when WordPress is loaded. Workers running in minimal bootstrap mode write to the database log only.

## Bootstrap: why it's fast

The key innovation: running PHP before WordPress boots.

A typical WordPress page load:
1. `wp-config.php` (database credentials, constants)
2. `wp-settings.php` (loads core, plugins, themes, hooks)
3. Template routing and rendering

A Queuety worker load:
1. Parse `wp-config.php` for database credentials (regex, not require)
2. Connect to MySQL directly via PDO
3. Claim and execute jobs/steps

Step 2 in WordPress takes 50-200ms. Step 2 in Queuety takes 1-5ms. For a queue that processes thousands of jobs per minute, this difference is everything.

For jobs/steps that need WordPress (like `wp_mail`), Queuety can optionally boot WordPress. But this is opt-in per handler, not the default.

## Configuration

```php
// wp-config.php or queuety-config.php
define('QUEUETY_TABLE_JOBS', 'queuety_jobs');              // Jobs table name
define('QUEUETY_TABLE_WORKFLOWS', 'queuety_workflows');    // Workflows table name
define('QUEUETY_TABLE_LOGS', 'queuety_logs');              // Logs table name
define('QUEUETY_RETENTION_DAYS', 7);                        // Auto-purge completed jobs/workflows after N days
define('QUEUETY_LOG_RETENTION_DAYS', 0);                    // Auto-purge logs after N days (0 = keep forever)
define('QUEUETY_MAX_EXECUTION_TIME', 300);                  // Max seconds per job/step before timeout
define('QUEUETY_WORKER_SLEEP', 1);                          // Seconds to sleep when queue is empty
define('QUEUETY_WORKER_MAX_JOBS', 1000);                    // Max jobs before worker restarts (memory safety)
define('QUEUETY_WORKER_MAX_MEMORY', 128);                   // Max MB before worker restarts
define('QUEUETY_RETRY_BACKOFF', 'exponential');             // linear, exponential, or fixed
define('QUEUETY_STALE_TIMEOUT', 600);                       // Seconds before a processing job is considered stale
```

## Monitoring

### Admin dashboard widget

A lightweight dashboard widget showing:
- Jobs processed in the last hour/day
- Current queue depth per queue
- Active workflows and their progress
- Failed/buried job count
- Average execution time per handler
- Worker status (running/stopped)

### WP-CLI status

```
$ wp queuety status

Queue     Pending  Processing  Completed (24h)  Failed  Buried
default       12           2            1,847       3       0
emails         0           0              342       0       0
orders         4           1              156       1       1

Workflows: 3 running, 1 failed, 47 completed (24h)
Workers: 2 running (PIDs: 12345, 12346)
Throughput: 42 jobs/min (avg 23ms/job)
```

## Action Scheduler compatibility

A drop-in compatibility layer that maps Action Scheduler's API to Queuety:

```php
// These Action Scheduler functions are intercepted and routed to Queuety:
as_enqueue_async_action($hook, $args, $group)
as_schedule_single_action($timestamp, $hook, $args, $group)
as_schedule_recurring_action($timestamp, $interval, $hook, $args, $group)
as_schedule_cron_action($timestamp, $cron, $hook, $args, $group)
as_unschedule_action($hook, $args, $group)
as_has_scheduled_action($hook, $args, $group)
```

This means WooCommerce and any plugin using Action Scheduler can switch to Queuety without changing their code. The compatibility layer translates AS calls to Queuety dispatches.

## Compared to Action Scheduler

| | Action Scheduler | Queuety |
|---|---|---|
| **Execution** | Full WordPress boot per batch | Minimal bootstrap, direct DB |
| **Speed** | ~200ms overhead per batch | ~5ms overhead per batch |
| **Concurrency** | Sequential batches | Multiple workers, atomic claiming |
| **Storage** | 3 tables, keeps everything forever | 3 tables, configurable retention |
| **Workflows** | No | Durable multi-step with state |
| **Priority** | No | 4 levels (enum) |
| **Rate limiting** | No | Per-handler |
| **Dead letter queue** | No (retries forever or gives up silently) | Buried state for inspection |
| **Logging** | None | Permanent DB log, queryable per workflow/job |
| **Monitoring** | Basic admin page | Dashboard widget + CLI + hooks |
| **Compatibility** | N/A | Drop-in AS replacement layer |
| **PHP** | 7.0+ | 8.2+ (enums, readonly, match) |

## Technical requirements

- PHP 8.2+
- WordPress 6.4+
- MySQL 5.7+ or MariaDB 10.3+ (for `SKIP LOCKED`)
- WP-CLI (for workers)

## Scope

### v0.1.0 (MVP)

- Three-table schema (jobs + workflows + logs)
- Job dispatch, claiming, execution
- Workflow definition, step execution, state accumulation
- Workflow resume from failed step
- Transactional step boundaries (atomic state advancement + logging)
- Stale job detection and recovery
- WP-CLI worker (`work`, `work --once`, `flush`)
- PHP API: jobs (dispatch, stats, retry, purge)
- PHP API: workflows (dispatch, status, retry, pause, resume)
- Priority queues (Priority enum)
- Delayed jobs
- Auto-retry with exponential backoff (BackoffStrategy enum)
- Buried (dead letter) state
- Auto-purge of completed jobs/workflows
- Permanent database log with queryable history
- `wp queuety status`, `wp queuety list`, `wp queuety log`
- `wp queuety workflow status/retry/pause/resume`
- WordPress lifecycle hooks
- PHPCS + PHPUnit tests

### v0.2.0

- Rate limiting per handler
- Recurring jobs / scheduler (`queuety_schedules` table)
- Worker concurrency (`--workers=N`)

### v0.3.0

- Action Scheduler compatibility layer
- Batch dispatch
- Queue pause/resume
- Worker memory and job count limits
- Packagist package

## Distribution

- Composer: `composer require queuety/queuety`
- GitHub releases (zip)
- WordPress plugin directory (if appropriate)
- Packagist

## Project conventions (follows Rudel)

Queuety follows the same project conventions as [Rudel](https://github.com/inline0/rudel), our WordPress sandbox plugin. This ensures consistency across projects.

### Namespaces and autoloading (PSR-4)

```json
{
    "autoload": {
        "psr-4": {
            "Queuety\\": "src/",
            "Queuety\\CLI\\": "cli/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Queuety\\Tests\\": "tests/"
        }
    }
}
```

CamelCase file names matching class names (PSR-4 standard, not WordPress lowercase-with-hyphens).

### Coding standards

100% WordPress Coding Standards enforced via PHPCS. Same ruleset as Rudel:
- WordPress base standard
- PSR-4 filename exceptions (CamelCase allowed)
- Dynamic hook names allowed
- Unused function parameters allowed (interface signatures)
- RuntimeException messages not escaped
- PHP 8.2+ testVersion
- Text domain: `queuety`
- Global prefix: `queuety` or `Queuety`

### Comment policy

- Internal code: no JSDoc. Comments only for why, not what.
- Public APIs: JSDoc required (description + params/returns/examples).
- Tests: no redundant comments that restate test names.
- No banner comments (no `// ==========`, `// -----`, etc.).
- No em dashes in code, docs, or copy.

### Dev dependencies

```json
{
    "require": { "php": ">=8.2" },
    "require-dev": {
        "phpunit/phpunit": "^13.0",
        "squizlabs/php_codesniffer": "^3.7",
        "wp-coding-standards/wpcs": "^3.0",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
    }
}
```

### Composer scripts

```bash
composer test              # Run all PHPUnit tests
composer test:unit         # Unit tests only
composer test:integration  # Integration tests only
composer cs                # Check coding standards
composer cs:fix            # Auto-fix with phpcbf
```

### Testing (PHPUnit 13)

Three test suites matching Rudel's structure:
- `tests/Unit/` for isolated unit tests
- `tests/Integration/` for tests requiring a database
- `tests/e2e/` for shell-based end-to-end tests

Test bootstrap defines WordPress stubs and constants so tests run without WordPress loaded. Shared `QueuetyTestCase` base class with temp directory management and helper methods.

### CI (GitHub Actions)

Three workflows, same pattern as Rudel:

**ci.yml** (push/PR to main):
- `lint` job: PHP 8.4, runs `composer cs`
- `test` job: PHP 8.4 with mysqli extension, runs `composer test`

**dist.yml** (push to main):
- Builds production zip (excludes tests, docs, .github, .claude, CLAUDE.md, phpunit.xml*, phpcs.xml*)
- Verifies required files present, no dev files leaked
- PHP syntax validation on all files
- Autoloader resolution check
- WordPress activation test (installs into WP, activates plugin, verifies CLI commands)
- Packagist install test
- Composer VCS install test

**release.yml** (GitHub release published):
- Builds zip, uploads as release asset

### Plugin entry point pattern

`queuety.php` follows the same structure as `rudel.php`:
- Plugin header with metadata
- Autoloader loading (supports both plugin mode and Composer package mode)
- Activation/deactivation hooks for schema creation/cleanup
- WP-CLI command registration
- Version constant: `QUEUETY_VERSION`

### CLAUDE.md

Project includes a `CLAUDE.md` with:
- Quick reference (composer commands)
- Project structure overview
- Comment policy
- Configuration constants table
- Key rules
- WP-CLI commands reference

## Project structure

```
queuety/
├── queuety.php              # Plugin entry point
├── bootstrap.php            # Minimal worker bootstrap (no WP)
├── composer.json
├── phpunit.xml.dist
├── phpcs.xml
├── CLAUDE.md
├── .github/
│   └── workflows/
│       ├── ci.yml           # Lint + test on push/PR
│       ├── dist.yml         # Zip integrity + WP activation + Packagist
│       └── release.yml      # Build and upload release zip
├── src/
│   ├── Queuety.php          # Public API facade
│   ├── Job.php              # Job model (readonly)
│   ├── Workflow.php         # Workflow model and orchestration
│   ├── WorkflowState.php   # Workflow state value object (readonly)
│   ├── Step.php             # Step handler interface
│   ├── Handler.php          # Handler interface (simple jobs)
│   ├── Queue.php            # Queue operations (claim, release, bury)
│   ├── Worker.php           # Worker process loop
│   ├── Logger.php           # Database logger
│   ├── Scheduler.php        # Recurring job scheduler
│   ├── RateLimiter.php      # Per-handler rate limiting
│   ├── Schema.php           # Table creation/migration
│   ├── Config.php           # Configuration reader
│   └── Enums/
│       ├── JobStatus.php
│       ├── WorkflowStatus.php
│       ├── Priority.php
│       └── BackoffStrategy.php
├── cli/
│   ├── QueuetyCommand.php   # WP-CLI job/queue commands
│   ├── WorkflowCommand.php  # WP-CLI workflow commands
│   └── LogCommand.php       # WP-CLI log commands
├── compat/
│   └── ActionScheduler.php  # AS compatibility layer
├── templates/
│   └── dashboard-widget.php # Admin widget template
└── tests/
    ├── bootstrap.php        # Test harness (WP stubs, constants)
    ├── QueuetyTestCase.php  # Shared base test class
    ├── Stubs/               # WordPress function stubs
    ├── Unit/
    ├── Integration/
    └── e2e/
```
