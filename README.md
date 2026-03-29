<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
    <img alt="Queuety" src="./.github/logo-light.svg" height="50">
  </picture>
</p>

<p align="center">
  The WordPress workflow engine
</p>

<p align="center">
  <a href="https://github.com/inline0/queuety/actions/workflows/ci.yml"><img src="https://github.com/inline0/queuety/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/queuety/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Queuety?

Queuety is a WordPress plugin that provides a fast job queue and durable workflow engine. Workers claim jobs directly from MySQL via PDO and process them inside a long-running WP-CLI process.

**The problem:** WordPress has no real background job system. `wp_cron` only fires on page visits. Action Scheduler boots the entire WordPress stack for every batch. An LLM API call that takes 60 seconds gets killed by PHP's 30-second timeout. There's no way to run multi-step processes that survive crashes and resume where they left off.

**Queuety solves this** with two primitives:

1. **Jobs** for fire-and-forget background work
2. **Workflows** for durable multi-step processes with persistent state

## Quick Start

**Prerequisites:**
- PHP 8.2+
- WordPress 6.4+
- MySQL 5.7+ or MariaDB 10.3+
- `pdo_mysql` enabled for the PHP runtime that loads WordPress and WP-CLI

For Composer-managed WordPress installs (for example Bedrock):

```bash
composer require queuety/queuety
wp plugin activate queuety
```

For a packaged plugin zip:

```bash
wp plugin install /path/to/queuety.zip --activate
```

For local development from this repository:

```bash
composer install
wp plugin activate queuety
```

If `pdo_mysql` is missing, the plugin now stays loaded but inert and shows an admin notice instead of fataling during activation or bootstrap.

Out of the box, the plugin also schedules a one-shot worker through WordPress cron every minute, so basic queue processing works without shell access. Dedicated `wp queuety work` processes are still the recommended higher-throughput production mode.

Dispatch a job using the modern dispatch API:

```php
use Queuety\Contracts\Job;
use Queuety\Dispatchable;

readonly class SendEmailJob implements Job {
    use Dispatchable;

    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}

    public function handle(): void {
        wp_mail( $this->to, $this->subject, $this->body );
    }
}

SendEmailJob::dispatch( 'user@example.com', 'Welcome', 'Hello from Queuety!' );
```

Or use the classic handler name API:

```php
use Queuety\Queuety;

Queuety::dispatch( 'send_email', [ 'to' => 'user@example.com' ] );
```

Run a durable workflow with timers and signals:

```php
Queuety::workflow( 'approval_flow' )
    ->then( SubmitRequestHandler::class )
    ->sleep( hours: 24 )
    ->wait_for_signal( 'approved' )
    ->then( ProcessApprovalHandler::class )
    ->on_cancel( CleanupHandler::class )
    ->dispatch( [ 'request_id' => 99 ] );

// Later, from another process:
Queuety::signal( $workflow_id, 'approved', [ 'approved_by' => 'admin@example.com' ] );
```

Build runtime-discovered branch work with compensation:

```php
use Queuety\Enums\JoinMode;

Queuety::workflow( 'agent_run' )
    ->then( PlanTasksStep::class )
    ->fan_out(
        items_key: 'tasks',
        handler_class: ExecuteTaskStep::class,
        result_key: 'task_results',
        join_mode: JoinMode::Quorum,
        quorum: 2,
        reducer_class: SummarizeTaskResults::class,
    )
    ->compensate_with( ReleaseTaskLocks::class )
    ->compensate_on_failure()
    ->then( FinalizeRunStep::class )
    ->dispatch( [ 'run_id' => 99 ] );
```

Dispatch a batch with callbacks:

```php
$batch = Queuety::create_batch( [
    new ImportUsersJob( $chunk_1 ),
    new ImportUsersJob( $chunk_2 ),
    new ImportUsersJob( $chunk_3 ),
] )
    ->name( 'Import users' )
    ->then( ImportCompleteHandler::class )
    ->catch( ImportFailedHandler::class )
    ->finally( ImportCleanupHandler::class )
    ->allow_failures()
    ->on_queue( 'imports' )
    ->dispatch();
```

Add middleware to a job:

```php
use Queuety\Middleware\RateLimited;
use Queuety\Middleware\ThrottlesExceptions;

readonly class CallExternalApiJob implements Job {
    use Dispatchable;

    public function __construct( public int $record_id ) {}

    public function handle(): void {
        // Call external API...
    }

    public function middleware(): array {
        return [
            new RateLimited( max: 60, window: 60 ),
            new ThrottlesExceptions( max_attempts: 3, decay_minutes: 5 ),
        ];
    }
}
```

Start a worker:

```bash
wp queuety work
```

## Features

- **Fast execution** -- workers use direct MySQL queue access and avoid per-job cron/bootstrap overhead
- **Durable workflows** -- multi-step processes with persistent state that survive PHP timeouts, crashes, and retries
- **Dispatchable jobs** -- self-contained readonly job classes with the `Dispatchable` trait and `Contracts\Job` interface
- **Middleware pipeline** -- onion-style middleware for rate limiting, throttling, uniqueness, and custom logic
- **Batching** -- dispatch groups of jobs with `then`, `catch`, and `finally` callbacks plus progress tracking
- **Job chaining** -- sequential job execution where each job depends on the previous one completing
- **Durable timers** -- `sleep()` steps that survive process restarts and resume at the right time
- **Signals** -- `wait_for_signal()` pauses a workflow until an external event arrives
- **Dynamic fan-out** -- `fan_out()` expands runtime-discovered work with `All`, `FirstSuccess`, and `Quorum` join modes
- **Step compensation** -- `compensate_with()` and `compensate_on_failure()` provide saga-style rollback hooks for completed steps
- **Streaming steps** -- `StreamingStep` interface with `ChunkStore` for persisting streamed data chunk by chunk
- **Cache layer** -- pluggable cache with `MemoryCache` and `ApcuCache` backends, auto-detected via `CacheFactory`
- **Heartbeats** -- long-running steps send heartbeats to prevent premature stale-job recovery
- **Workflow cancellation** -- cancel running workflows and trigger registered cleanup handlers
- **Workflow event log** -- full timeline of step transitions with state snapshots and time-travel debugging
- **State pruning** -- automatic removal of old step outputs to keep workflow state lean
- **Schedule overlap policies** -- Allow, Skip, or Buffer for recurring jobs
- **Multi-queue worker priorities** -- process multiple queues with strict priority ordering
- **Parallel steps** -- run steps concurrently and wait for all to complete before advancing
- **Conditional branching** -- skip to named steps based on prior state
- **Sub-workflows** -- spawn child workflows that feed results back to the parent
- **Priority queues** -- 4 levels (Low, Normal, High, Urgent) via type-safe enums
- **Rate limiting** -- per-handler execution limits with sliding window
- **Recurring jobs** -- interval-based (`every('1 hour')`) and cron-based (`cron('0 3 * * *')`) scheduling
- **Job dependencies** -- job B waits for job A to complete before running
- **Unique jobs** -- prevent duplicate dispatches for the same handler and payload
- **Job properties** -- `$tries`, `$timeout`, and `$backoff` declared directly on job classes
- **`failed()` hook** -- called on the job instance when all retries are exhausted
- **Conditional dispatch** -- `dispatch_if()` and `dispatch_unless()` on the `Dispatchable` trait
- **Synchronous dispatch** -- `dispatch_sync()` runs a job immediately without the queue
- **Timeout enforcement** -- kill jobs that exceed max execution time
- **Worker concurrency** -- `--workers=N` forks multiple processes with automatic restart on crash
- **Permanent logging** -- queryable database log of every job and workflow execution
- **Metrics API** -- throughput, latency percentiles, and error rates per handler
- **Webhooks** -- HTTP notifications on job/workflow events
- **Testing utilities** -- `QueueFake` for asserting dispatched jobs and `create_batch()` batches in tests
- **PHP attributes** -- `#[QueuetyHandler('name')]` for auto-registration
- **ThrottlesExceptions** -- back off when external services are down to prevent job storms
- **Debug mode** -- verbose worker logging for development

## How It Works

Workers run inside a long-lived WP-CLI process and claim jobs directly from MySQL via PDO. The queue, workflow state, logs, batches, signals, and streaming chunks all live in MySQL, so worker restarts do not lose orchestration state.

Workflows break long-running work into steps. Each step persists its output to a shared state bag in the database. If PHP dies mid-step, the worker retries that step with all prior state intact. The step boundary is a single MySQL transaction: state update, job completion, and next step enqueue all happen atomically.

```
Workflow: generate_report (3 steps)

Step 0: fetch_data     -> state: {user_data: {...}}
Step 1: call_llm       -> PHP dies -> retry -> state: {user_data: {...}, llm_response: "..."}
Step 2: format_output  -> state: {user_data: {...}, llm_response: "...", report_url: "/reports/42.pdf"}

Workflow: completed
```

## Job Handlers

Classic handler interface:

```php
class SendEmailHandler implements Queuety\Handler {
    public function handle(array $payload): void {
        wp_mail($payload['to'], $payload['subject'], $payload['body']);
    }

    public function config(): array {
        return [
            'queue' => 'emails',
            'max_attempts' => 5,
        ];
    }
}

Queuety::register('send_email', SendEmailHandler::class);
```

Modern dispatchable job class:

```php
use Queuety\Contracts\Job;
use Queuety\Dispatchable;

readonly class SendEmailJob implements Job {
    use Dispatchable;

    public int $tries = 5;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}

    public function handle(): void {
        wp_mail($this->to, $this->subject, $this->body);
    }

    public function failed(\Throwable $e): void {
        error_log("Email to {$this->to} failed: {$e->getMessage()}");
    }
}
```

## Workflow Steps

```php
class CallLLMHandler implements Queuety\Step {
    public function handle(array $state): array {
        $response = $this->callAPI($state['prompt']);
        return ['llm_response' => $response]; // merged into state
    }

    public function config(): array {
        return ['max_attempts' => 5];
    }
}
```

## Streaming Steps

For LLM responses or large downloads, streaming steps persist data chunk by chunk so progress survives crashes:

```php
use Queuety\Contracts\StreamingStep;

class StreamLLMHandler implements StreamingStep {
    public function stream(array $state, array $existing_chunks = []): \Generator {
        $offset = count($existing_chunks);
        foreach ($this->streamApi($state['prompt'], $offset) as $chunk) {
            yield $chunk; // persisted to DB immediately
        }
    }

    public function on_complete(array $chunks, array $state): array {
        return ['response' => implode('', $chunks)];
    }

    public function config(): array {
        return ['max_attempts' => 3];
    }
}
```

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp queuety work [--queue=<q>] [--once] [--workers=<n>]` | Start a worker (or N workers) |
| `wp queuety work --queue=high,default,low` | Process multiple queues with priority ordering |
| `wp queuety flush` | Process all pending jobs and exit |
| `wp queuety dispatch <handler> --payload='{}'` | Dispatch a job |
| `wp queuety status` | Show queue stats |
| `wp queuety list [--queue=<q>] [--status=<s>]` | List jobs |
| `wp queuety inspect <id>` | Show full job details and log history |
| `wp queuety retry <id>` | Retry a job |
| `wp queuety retry-buried` | Retry all buried jobs |
| `wp queuety bury <id>` | Bury a job |
| `wp queuety delete <id>` | Delete a job |
| `wp queuety recover` | Recover stale jobs |
| `wp queuety purge [--older-than=<days>]` | Purge completed jobs |
| `wp queuety pause <queue>` | Pause a queue |
| `wp queuety resume <queue>` | Resume a queue |
| `wp queuety metrics` | Show per-handler metrics |
| `wp queuety discover <dir> --namespace=<ns>` | Auto-discover handlers |
| `wp queuety workflow status <id>` | Show workflow progress |
| `wp queuety workflow retry <id>` | Retry from failed step |
| `wp queuety workflow pause <id>` | Pause a workflow |
| `wp queuety workflow resume <id>` | Resume a workflow |
| `wp queuety workflow list [--status=<s>]` | List workflows |
| `wp queuety workflow cancel <id>` | Cancel a workflow and run cleanup handlers |
| `wp queuety workflow timeline <id>` | Show the full event timeline for a workflow |
| `wp queuety workflow state-at <id> <step>` | Show workflow state snapshot at a specific step |
| `wp queuety schedule list` | List recurring schedules |
| `wp queuety schedule add <handler> [--every=<i>] [--cron=<c>]` | Add a recurring schedule |
| `wp queuety schedule remove <handler>` | Remove a schedule |
| `wp queuety schedule run` | Manually trigger scheduler tick |
| `wp queuety log [--workflow=<id>] [--job=<id>]` | Query log entries |
| `wp queuety log purge --older-than=<days>` | Prune old logs |
| `wp queuety webhook add <event> <url>` | Register a webhook |
| `wp queuety webhook list` | List webhooks |
| `wp queuety webhook remove <id>` | Remove a webhook |

## Configuration

All constants are optional. Define in `wp-config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `QUEUETY_RETENTION_DAYS` | `7` | Auto-purge completed jobs after N days |
| `QUEUETY_LOG_RETENTION_DAYS` | `0` | Auto-purge logs after N days (0 = forever) |
| `QUEUETY_MAX_EXECUTION_TIME` | `300` | Max seconds per job before timeout |
| `QUEUETY_WORKER_SLEEP` | `1` | Seconds to sleep when queue is empty |
| `QUEUETY_WORKER_MAX_JOBS` | `1000` | Max jobs before worker restarts |
| `QUEUETY_WORKER_MAX_MEMORY` | `128` | Max MB before worker restarts |
| `QUEUETY_RETRY_BACKOFF` | `exponential` | Backoff strategy (exponential, linear, fixed) |
| `QUEUETY_STALE_TIMEOUT` | `600` | Seconds before stuck jobs are recovered |
| `QUEUETY_CACHE_TTL` | `5` | Default cache TTL in seconds |
| `QUEUETY_DEBUG` | `false` | Enable verbose worker logging |
| `QUEUETY_TABLE_JOBS` | `queuety_jobs` | Jobs table name |
| `QUEUETY_TABLE_WORKFLOWS` | `queuety_workflows` | Workflows table name |
| `QUEUETY_TABLE_LOGS` | `queuety_logs` | Logs table name |
| `QUEUETY_TABLE_SCHEDULES` | `queuety_schedules` | Schedules table name |
| `QUEUETY_TABLE_SIGNALS` | `queuety_signals` | Signals table name |
| `QUEUETY_TABLE_CHUNKS` | `queuety_chunks` | Streaming chunks table name |
| `QUEUETY_TABLE_QUEUE_STATES` | `queuety_queue_states` | Queue states table name |
| `QUEUETY_TABLE_WEBHOOKS` | `queuety_webhooks` | Webhooks table name |

## Development

```bash
# Install dependencies
composer install
npm install

# Check coding standards
composer cs

# Auto-fix coding standards
composer cs:fix

# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration

# Run E2E tests (requires Docker and Node.js)
npm run test:e2e
npm run test:e2e:wp-env
```

## License

GPL-2.0-or-later
