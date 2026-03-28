<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
    <img alt="Queuety" src="./.github/logo-light.svg" height="50">
  </picture>
</p>

<p align="center">
  The WordPress job queue and workflow engine
</p>

<p align="center">
  <a href="https://github.com/inline0/queuety/actions/workflows/ci.yml"><img src="https://github.com/inline0/queuety/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/queuety/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Queuety?

Queuety is a WordPress plugin that provides a fast job queue and durable workflow engine. Workers process jobs from a minimal PHP bootstrap without booting WordPress, connecting to MySQL directly via PDO for maximum throughput.

**The problem:** WordPress has no real background job system. `wp_cron` only fires on page visits. Action Scheduler boots the entire WordPress stack for every batch. An LLM API call that takes 60 seconds gets killed by PHP's 30-second timeout. There's no way to run multi-step processes that survive crashes and resume where they left off.

**Queuety solves this** with two primitives:

1. **Jobs** for fire-and-forget background work
2. **Workflows** for durable multi-step processes with persistent state

## Quick Start

**Prerequisites:**
- PHP 8.2+
- WordPress 6.4+
- MySQL 5.7+ or MariaDB 10.3+

```bash
composer require queuety/queuety
wp plugin activate queuety
```

Dispatch a job:

```php
use Queuety\Queuety;

Queuety::dispatch('send_email', ['to' => 'user@example.com']);
```

Run a durable workflow:

```php
Queuety::workflow('generate_report')
    ->then(FetchDataHandler::class)
    ->then(CallLLMHandler::class)
    ->then(FormatOutputHandler::class)
    ->dispatch(['user_id' => 42]);
```

Start a worker:

```bash
wp queuety work
```

## Features

- **Fast execution** -- workers skip the WordPress boot, connecting to MySQL directly (~5ms vs ~200ms overhead per batch)
- **Durable workflows** -- multi-step processes with persistent state that survive PHP timeouts, crashes, and retries
- **Parallel steps** -- run steps concurrently and wait for all to complete before advancing
- **Conditional branching** -- skip to named steps based on prior state
- **Sub-workflows** -- spawn child workflows that feed results back to the parent
- **Priority queues** -- 4 levels (Low, Normal, High, Urgent) via type-safe enums
- **Rate limiting** -- per-handler execution limits with sliding window
- **Recurring jobs** -- interval-based (`every('1 hour')`) and cron-based (`cron('0 3 * * *')`) scheduling
- **Job dependencies** -- job B waits for job A to complete before running
- **Unique jobs** -- prevent duplicate dispatches for the same handler and payload
- **Timeout enforcement** -- kill jobs that exceed max execution time
- **Worker concurrency** -- `--workers=N` forks multiple processes with automatic restart on crash
- **Permanent logging** -- queryable database log of every job and workflow execution
- **Metrics API** -- throughput, latency percentiles, and error rates per handler
- **Webhooks** -- HTTP notifications on job/workflow events
- **PHP attributes** -- `#[QueuetyHandler('name')]` for auto-registration
- **Debug mode** -- verbose worker logging for development

## How It Works

Workers boot from a minimal bootstrap that parses `wp-config.php` via regex (not `require`) to extract database credentials, then connects directly via PDO. No plugins, themes, or hooks are loaded. For handlers that need WordPress (like `wp_mail`), Queuety can optionally boot it per-handler.

Workflows break long-running work into steps. Each step persists its output to a shared state bag in the database. If PHP dies mid-step, the worker retries that step with all prior state intact. The step boundary is a single MySQL transaction: state update, job completion, and next step enqueue all happen atomically.

```
Workflow: generate_report (3 steps)

Step 0: fetch_data     -> state: {user_data: {...}}
Step 1: call_llm       -> PHP dies -> retry -> state: {user_data: {...}, llm_response: "..."}
Step 2: format_output  -> state: {user_data: {...}, llm_response: "...", report_url: "/reports/42.pdf"}

Workflow: completed
```

## Job Handlers

```php
class SendEmailHandler implements Queuety\Handler {
    public function handle(array $payload): void {
        wp_mail($payload['to'], $payload['subject'], $payload['body']);
    }

    public function config(): array {
        return [
            'queue' => 'emails',
            'max_attempts' => 5,
            'needs_wordpress' => true,
        ];
    }
}

Queuety::register('send_email', SendEmailHandler::class);
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

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp queuety work [--queue=<q>] [--once] [--workers=<n>]` | Start a worker (or N workers) |
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
| `wp queuety workflow status <id>` | Show workflow progress |
| `wp queuety workflow retry <id>` | Retry from failed step |
| `wp queuety workflow pause <id>` | Pause a workflow |
| `wp queuety workflow resume <id>` | Resume a workflow |
| `wp queuety workflow list [--status=<s>]` | List workflows |
| `wp queuety schedule list` | List recurring schedules |
| `wp queuety schedule add <handler> [--every=<i>] [--cron=<c>]` | Add a recurring schedule |
| `wp queuety schedule remove <handler>` | Remove a schedule |
| `wp queuety log [--workflow=<id>] [--job=<id>]` | Query log entries |
| `wp queuety webhook add <event> <url>` | Register a webhook |
| `wp queuety webhook list` | List webhooks |
| `wp queuety discover <dir> --namespace=<ns>` | Auto-discover handlers |

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
| `QUEUETY_DEBUG` | `false` | Enable verbose worker logging |

## Development

```bash
# Install dependencies
composer install

# Check coding standards
composer cs

# Auto-fix coding standards
composer cs:fix

# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration

# Run E2E tests (requires Docker)
bash tests/e2e/run-all.sh
```

## License

GPL-2.0-or-later
