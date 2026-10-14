---
title: "Getting Started"
description: "Install Queuety, configure it, and run your first job and workflow."
path: "getting-started"
order: 10
meta_title: "Getting Started"
meta_description: "Install Queuety, configure it, and run your first job and workflow."
---

# Getting Started

## Installation

### Requirements

- PHP 8.2 or later and WordPress 6.4 or later
- MySQL 8.0+ or MariaDB 10.6+. Workers claim jobs and due schedules with `SELECT ... FOR UPDATE SKIP LOCKED`, and Queuety validates the server version before installing tables or claiming work.
- `mysqli` or `pdo_mysql` (see below)
- WP-CLI on the server if you run dedicated workers with `wp queuety work`
- The `pcntl` and `posix` PHP extensions if you run worker pools with `--workers` or `--min-workers`/`--max-workers`

Queuety requires either `mysqli` or `pdo_mysql` in the PHP runtime that loads WordPress and WP-CLI. It chooses the driver automatically, preferring `mysqli` for WordPress runtimes and keeping `pdo_mysql` available for standalone/direct runtime use.

If you want the DB-backed runtime without the WordPress plugin layer, see [Standalone Use](/docs/features/standalone-use). That path uses the same connection and runtime tables, but it does not include the WordPress hook bridges or WP-CLI registration.

### From the release zip

Every [GitHub release](https://github.com/inline0/queuety/releases) has a `queuety.zip` asset: the plugin with its Composer dependencies already installed. Install the latest one with WP-CLI:

```bash
wp plugin install https://github.com/inline0/queuety/releases/latest/download/queuety.zip --activate
```

Or download the zip and upload it under Plugins > Add New > Upload Plugin. This is the simplest path for a regular WordPress site.

### Via Composer for Composer-managed WordPress

```bash
composer require queuety/queuety
```

Then activate the plugin:

```bash
wp plugin activate queuety
```

The package type is `wordpress-plugin`. Composer places it in your plugins directory only when the project requires `composer/installers` and maps that type to an installer path, as Bedrock does, and the project must load its own `vendor/autoload.php` (Bedrock does that too). Without `composer/installers`, Composer installs it into `vendor/queuety/queuety`, where WordPress does not find it, so use the release zip on a plain WordPress install.

### From source during development

Place the repository in `wp-content/plugins/queuety`, then install PHP dependencies:

```bash
composer install
wp plugin activate queuety
```

On activation, Queuety creates its database tables (`queuety_jobs`, `queuety_workflows`, `queuety_logs`, `queuety_schedules`, `queuety_queue_states`, `queuety_webhooks`, `queuety_locks`, `queuety_signals`, `queuety_workflow_dependencies`, `queuety_workflow_dispatch_keys`, `queuety_batches`, `queuety_chunks`, `queuety_workflow_events`, `queuety_artifacts`, `queuety_state_machines`, `queuety_state_machine_events`) so workers can claim jobs directly from MySQL.

The plugin runs a worker through WordPress cron every minute for the `default` queue. Each invocation performs maintenance and processes jobs for a 20-second budget, checked between jobs, so the active job finishes. This supports recurring schedules, recovery and deadlines without a shell while WordPress cron is firing. Use dedicated `wp queuety work` processes for custom queues, lower latency or heavier workloads.

## Configuration

All constants are optional. Define them in `wp-config.php` before the plugin loads, or before Queuety boots in an embedded theme-style install:

| Constant | Default | Description |
|---|---|---|
| `QUEUETY_RETENTION_DAYS` | `7` | Default age in days for `wp queuety purge` and `Queuety::purge()`. Nothing purges automatically |
| `QUEUETY_LOG_RETENTION_DAYS` | `0` | Automatic log retention in days (0 = keep forever) |
| `QUEUETY_MAX_EXECUTION_TIME` | `300` | Execution timeout in seconds when pcntl is available |
| `QUEUETY_WORKER_SLEEP` | `1` | Seconds to sleep when queue is empty |
| `QUEUETY_WORKER_MAX_JOBS` | `1000` | Max jobs before worker restarts |
| `QUEUETY_WORKER_MAX_MEMORY` | `128` | Max MB before worker restarts |
| `QUEUETY_RETRY_BACKOFF` | `exponential` | Backoff strategy: `exponential`, `linear`, or `fixed` |
| `QUEUETY_STALE_TIMEOUT` | `600` | Seconds before stuck jobs are recovered |
| `QUEUETY_CACHE_TTL` | `5` | Default cache TTL in seconds |
| `QUEUETY_DEBUG` | `false` | Enable verbose worker logging |
| `QUEUETY_CLI_COMMAND` | `queuety` | Root WP-CLI command name |
| `QUEUETY_TABLE_PREFIX` | `queuety_` | Shared base name for all Queuety tables after the WordPress DB prefix |
| `QUEUETY_TABLE_JOBS` | `queuety_jobs` | Jobs table name |
| `QUEUETY_TABLE_WORKFLOWS` | `queuety_workflows` | Workflows table name |
| `QUEUETY_TABLE_LOGS` | `queuety_logs` | Logs table name |
| `QUEUETY_TABLE_SCHEDULES` | `queuety_schedules` | Schedules table name |
| `QUEUETY_TABLE_SIGNALS` | `queuety_signals` | Signals table name |
| `QUEUETY_TABLE_WORKFLOW_DEPENDENCIES` | `queuety_workflow_dependencies` | Workflow dependency waits table name |
| `QUEUETY_TABLE_WORKFLOW_DISPATCH_KEYS` | `queuety_workflow_dispatch_keys` | Durable workflow idempotency table name |
| `QUEUETY_TABLE_CHUNKS` | `queuety_chunks` | Streaming chunks table name |
| `QUEUETY_TABLE_QUEUE_STATES` | `queuety_queue_states` | Queue states table name |
| `QUEUETY_TABLE_WEBHOOKS` | `queuety_webhooks` | Webhooks table name |
| `QUEUETY_TABLE_LOCKS` | `queuety_locks` | Locks table name |
| `QUEUETY_TABLE_BATCHES` | `queuety_batches` | Batches table name |
| `QUEUETY_TABLE_WORKFLOW_EVENTS` | `queuety_workflow_events` | Workflow event log table name |
| `QUEUETY_TABLE_ARTIFACTS` | `queuety_artifacts` | Workflow artifacts table name |
| `QUEUETY_TABLE_STATE_MACHINES` | `queuety_state_machines` | State machines table name |
| `QUEUETY_TABLE_STATE_MACHINE_EVENTS` | `queuety_state_machine_events` | State machine event log table name |
| `QUEUETY_DB_DRIVER` | `auto` | MySQL driver: `auto`, `mysqli`, or `pdo`. `auto` prefers `mysqli` when WordPress is loaded, otherwise `pdo_mysql`, then `mysqli` |

Resource admission and adaptive pool constants (`QUEUETY_RESOURCE_*`, `QUEUETY_WORKER_POOL_*`) are listed on the [Workers](/docs/features/workers) page.

`QUEUETY_TABLE_PREFIX` changes only the Queuety portion of the table names. The WordPress database prefix still wraps the result, so `$wpdb->prefix = 'wp_'` and `QUEUETY_TABLE_PREFIX = 'themequeue_'` produce tables like `wp_themequeue_jobs`. Explicit `QUEUETY_TABLE_*` constants still win for individual tables.

Standalone connections can also set the Queuety table prefix per connection:

```php
$conn = new Connection(
    host: '127.0.0.1:3306',
    dbname: 'wordpress',
    user: 'root',
    password: 'secret',
    prefix: 'wp_',
    table_prefix: 'themequeue_',
);
```

That keeps the WordPress database prefix intact and changes only the Queuety
table segment.

## Your first job

Create a handler class that implements the `Handler` interface:

```php
use Queuety\Handler;

class SendEmailHandler implements Handler {
    public function handle( array $payload ): void {
        wp_mail( $payload['to'], $payload['subject'], $payload['body'] );
    }

    public function config(): array {
        return [
            'queue'           => 'emails',
            'max_attempts'    => 5,
        ];
    }
}
```

Register it and dispatch:

```php
use Queuety\Queuety;

Queuety::register( 'send_email', SendEmailHandler::class );

Queuety::dispatch( 'send_email', [
    'to'      => 'user@example.com',
    'subject' => 'Welcome',
    'body'    => 'Hello from Queuety!',
] );
```

Or use the modern dispatchable job class:

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

## Your first workflow

Define step handlers that implement the `Step` interface:

```php
use Queuety\Step;

class FetchDataHandler implements Step {
    public function handle( array $state ): array {
        $user = get_user_by( 'ID', $state['user_id'] );
        return [ 'user_name' => $user->display_name ];
    }

    public function config(): array {
        return [];
    }
}

class CallLLMHandler implements Step {
    public function handle( array $state ): array {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'body' => json_encode( [ 'prompt' => "Generate report for {$state['user_name']}" ] ),
        ] );
        return [ 'llm_response' => wp_remote_retrieve_body( $response ) ];
    }

    public function config(): array {
        return [ 'max_attempts' => 5 ];
    }
}

class FormatOutputHandler implements Step {
    public function handle( array $state ): array {
        $url = save_report( $state['user_name'], $state['llm_response'] );
        return [ 'report_url' => $url ];
    }

    public function config(): array {
        return [];
    }
}
```

Dispatch the workflow:

```php
$workflow_id = Queuety::workflow( 'generate_report' )
    ->then( FetchDataHandler::class )
    ->then( CallLLMHandler::class )
    ->then( FormatOutputHandler::class )
    ->dispatch( [ 'user_id' => 42 ] );
```

## Start a worker

```bash
wp queuety work
```

The worker polls for pending jobs, executes them, advances workflows, and sleeps when the queue is empty. Press `Ctrl+C` to stop; this ends the process immediately, even in the middle of a job (see [Workers](/docs/features/workers#worker-lifecycle)).

The `SendEmailHandler` above sends its jobs to the `emails` queue through `config()`, so a worker on `default` does not pick them up. Name every queue you use:

```bash
wp queuety work --queue=emails,default
```

If you do not run a dedicated worker, Queuety still has the default once-per-minute WordPress cron fallback described above, limited to the `default` queue with a budget of 20 seconds per run. The budget is checked between jobs, so an active job finishes before the cron worker exits. The dedicated worker is the higher-throughput, lower-latency option.

For production, run multiple workers:

```bash
wp queuety work --workers=4
```

Process multiple queues with priority ordering:

```bash
wp queuety work --queue=high,default,low
```

## What's next

- [Jobs](/docs/jobs) for the complete dispatch and handler guide
- [Workflows](/docs/workflows) for chains, parallel steps, conditional branching, and run-workflows
- [Middleware](/docs/features/middleware) for rate limiting, throttling, and custom pipeline logic
- [Batching](/docs/features/batching) for dispatching groups of jobs with callbacks
- [Streaming Steps](/docs/workflows/streaming) for persisting streamed data chunk by chunk
- [Scheduling](/docs/features/scheduling) for recurring jobs
- [Caching](/docs/features/caching) for the pluggable cache layer
- [Testing](/docs/features/testing) for QueueFake and test assertions
- [CLI Reference](/docs/cli) for all available commands
- [PHP API](/docs/api) for programmatic access
