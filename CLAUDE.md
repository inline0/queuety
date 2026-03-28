# Queuety

WordPress plugin: job queue and durable workflow engine. Workers run from a minimal PHP bootstrap without booting WordPress. PHP 8.2+.

## Quick Reference

```bash
# Check coding standards
composer cs

# Auto-fix coding standards
composer cs:fix

# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration

# Run E2E tests (requires Docker for wp-env)
bash tests/e2e/run-all.sh

# Run wp-env E2E tests only
bash tests/e2e/test-wp-env.sh
```

## Project Structure

```
queuety/
├── queuety.php          # Entry point
├── bootstrap.php        # Minimal worker bootstrap (no WP)
├── phpunit.xml.dist     # PHPUnit configuration
├── phpcs.xml            # PHPCS configuration
├── .wp-env.json         # Docker-based wp-env config
├── package.json         # npm config (just @wordpress/env)
├── src/
│   ├── Queuety.php        # Public API facade
│   ├── Job.php            # Job model (readonly)
│   ├── Queue.php          # Queue operations (claim, release, bury)
│   ├── Workflow.php       # Workflow model and orchestration
│   ├── WorkflowBuilder.php # Fluent workflow builder
│   ├── WorkflowState.php  # Workflow state value object (readonly)
│   ├── WorkflowEventLog.php # Workflow event timeline and state snapshots
│   ├── WorkflowTemplate.php # Registered workflow template
│   ├── WorkflowRegistry.php # Workflow template registry
│   ├── Worker.php         # Worker process loop
│   ├── WorkerPool.php     # Multi-worker fork management
│   ├── Logger.php         # Database logger
│   ├── Handler.php        # Handler interface (simple jobs)
│   ├── Step.php           # Step handler interface (workflows)
│   ├── PendingJob.php     # Fluent job dispatch builder
│   ├── PendingSchedule.php # Fluent schedule builder
│   ├── HandlerRegistry.php # Handler name to class mapping
│   ├── HandlerDiscovery.php # Auto-discover handlers from directories
│   ├── HookDispatcher.php # WordPress action hook dispatcher
│   ├── Schema.php         # Table creation/migration
│   ├── Connection.php     # Direct PDO database connection
│   ├── ConfigParser.php   # wp-config.php credential parser
│   ├── Config.php         # Configuration reader
│   ├── Dispatchable.php   # Trait for self-dispatching job classes
│   ├── JobSerializer.php  # Serializes Contracts\Job instances to handler/payload
│   ├── Batch.php          # Batch value object
│   ├── BatchBuilder.php   # Fluent batch builder with callbacks
│   ├── BatchManager.php   # Batch lifecycle (cancel, prune, progress)
│   ├── ChainBuilder.php   # Sequential job chain builder
│   ├── ChunkStore.php     # Chunk persistence for streaming steps
│   ├── Heartbeat.php      # Static heartbeat helper for long-running steps
│   ├── MiddlewarePipeline.php # Onion-style middleware executor
│   ├── Metrics.php        # Per-handler throughput, latency, error rates
│   ├── RateLimiter.php    # Sliding-window rate limiter
│   ├── Schedule.php       # Schedule model
│   ├── Scheduler.php      # Recurring job scheduler
│   ├── CronExpression.php # Cron expression parser
│   ├── WebhookNotifier.php # HTTP webhook notifications
│   ├── Contracts/
│   │   ├── Job.php          # Dispatchable job interface
│   │   ├── Cache.php        # Cache backend interface
│   │   ├── Middleware.php   # Middleware interface
│   │   └── StreamingStep.php # Streaming step interface (yields chunks)
│   ├── Cache/
│   │   ├── MemoryCache.php  # In-memory cache (per-request)
│   │   ├── ApcuCache.php    # APCu-backed persistent cache
│   │   └── CacheFactory.php # Auto-detects best available backend
│   ├── Middleware/
│   │   ├── RateLimited.php        # Rate limit middleware
│   │   ├── Timeout.php            # Timeout middleware (pcntl)
│   │   ├── UniqueJob.php          # Unique job middleware (DB lock)
│   │   ├── WithoutOverlapping.php # Prevent overlapping execution
│   │   └── ThrottlesExceptions.php # Back off on external service errors
│   ├── Testing/
│   │   └── QueueFake.php   # In-memory queue fake for tests
│   ├── Exceptions/
│   │   ├── RateLimitExceededException.php
│   │   └── TimeoutException.php
│   ├── Enums/
│   │   ├── JobStatus.php
│   │   ├── WorkflowStatus.php
│   │   ├── Priority.php
│   │   ├── BackoffStrategy.php
│   │   ├── LogEvent.php
│   │   ├── OverlapPolicy.php
│   │   └── ExpressionType.php
│   └── Attributes/
│       └── QueuetyHandler.php  # PHP 8 attribute for auto-registration
├── cli/
│   ├── QueuetyCommand.php   # WP-CLI job/queue commands
│   ├── WorkflowCommand.php  # WP-CLI workflow commands
│   ├── LogCommand.php       # WP-CLI log commands
│   ├── ScheduleCommand.php  # WP-CLI schedule commands
│   └── WebhookCommand.php   # WP-CLI webhook commands
├── compat/
│   └── ActionScheduler.php  # AS compatibility layer (v0.3.0)
├── templates/
│   └── dashboard-widget.php # Admin widget template (v0.2.0)
└── tests/
    ├── bootstrap.php      # PHPUnit bootstrap
    ├── QueuetyTestCase.php # Shared base test class
    ├── Stubs/             # WordPress/WP-CLI stubs
    ├── Unit/
    ├── Integration/
    └── e2e/
```

## Comment Policy

- Internal code: no JSDoc. Comments only for why, not what.
- Public APIs: JSDoc required (description + params/returns/examples).
- Tests: no redundant comments that restate test names. Comment only when setup/assertion is non-obvious.
- **No banner comments**: never use decorative separator lines like `// ==========`, `// -----`, `// ===== SECTION =====`, etc.
- **No em dashes**: never use em dashes in code, docs, or copy. Use periods, commas, colons, or rewrite the sentence.

## Configuration

All constants are optional. Define them in `wp-config.php` or `queuety-config.php`.

| Constant | Default | Description |
|----------|---------|-------------|
| `QUEUETY_TABLE_JOBS` | `queuety_jobs` | Jobs table name |
| `QUEUETY_TABLE_WORKFLOWS` | `queuety_workflows` | Workflows table name |
| `QUEUETY_TABLE_LOGS` | `queuety_logs` | Logs table name |
| `QUEUETY_TABLE_SCHEDULES` | `queuety_schedules` | Schedules table name |
| `QUEUETY_TABLE_SIGNALS` | `queuety_signals` | Signals table name |
| `QUEUETY_TABLE_CHUNKS` | `queuety_chunks` | Streaming chunks table name |
| `QUEUETY_TABLE_QUEUE_STATES` | `queuety_queue_states` | Queue states table name |
| `QUEUETY_TABLE_WEBHOOKS` | `queuety_webhooks` | Webhooks table name |
| `QUEUETY_RETENTION_DAYS` | `7` | Auto-purge completed jobs/workflows after N days |
| `QUEUETY_LOG_RETENTION_DAYS` | `0` | Auto-purge logs after N days (0 = keep forever) |
| `QUEUETY_MAX_EXECUTION_TIME` | `300` | Max seconds per job/step |
| `QUEUETY_WORKER_SLEEP` | `1` | Seconds to sleep when queue is empty |
| `QUEUETY_WORKER_MAX_JOBS` | `1000` | Max jobs before worker restarts |
| `QUEUETY_WORKER_MAX_MEMORY` | `128` | Max MB before worker restarts |
| `QUEUETY_RETRY_BACKOFF` | `exponential` | Retry backoff strategy |
| `QUEUETY_STALE_TIMEOUT` | `600` | Seconds before a processing job is considered stale |
| `QUEUETY_CACHE_TTL` | `5` | Default cache TTL in seconds |
| `QUEUETY_DEBUG` | `false` | Enable verbose worker logging |

## Key Rules

1. **100% WordPress Coding Standards**: no exceptions. Run `composer cs` before committing.
2. **Run tests after changes**: `composer test` for PHPUnit.
3. **bootstrap.php is self-contained**: no autoloader, no WP functions, plain PHP only. Parses wp-config.php via regex.
4. **PHP 8.2+**: use enums, readonly classes, constructor promotion, match expressions, named arguments.
5. **Multi-table schema**: jobs, workflows, logs, schedules, signals, chunks, queue_states, webhooks. All in WordPress's MySQL database.
6. **Logging is in the database**: no log files. All log entries go to `queuety_logs` table.
7. **Workflows store step definitions in state**: the `_steps` key in the workflow's state JSON holds the ordered list of handler class names.
8. **Rate limits are per-handler**: tracked in-memory with periodic DB refresh from logs table.
9. **Worker concurrency uses pcntl_fork()**: each child creates its own DB connection. Parent monitors and restarts crashed children.
10. **Cache layer is pluggable**: `CacheFactory` auto-detects APCu or falls back to `MemoryCache`. Custom backends implement `Contracts\Cache`.
11. **Streaming steps yield chunks**: each yielded value is persisted to the `queuety_chunks` table immediately. On retry, `$existing_chunks` provides previously saved data so the stream can resume.
12. **Middleware wraps job execution**: middleware classes implement `Contracts\Middleware` and are declared in a `middleware()` method on the job class. The pipeline is onion-style (outer to inner).
13. **Workflow event log records step transitions**: every step start, completion, and failure is recorded with state snapshots for time-travel debugging.

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp queuety work [--queue=<queue>] [--once] [--workers=<n>]` | Start a worker (or N workers) |
| `wp queuety work --queue=high,default,low` | Process multiple queues with priority ordering |
| `wp queuety flush` | Process all pending jobs and exit |
| `wp queuety dispatch <handler> --payload='{}'` | Dispatch a job |
| `wp queuety status` | Show queue stats |
| `wp queuety list [--queue=<queue>] [--status=<status>]` | List jobs |
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
| `wp queuety workflow list [--status=<status>]` | List workflows |
| `wp queuety workflow cancel <id>` | Cancel a workflow and run cleanup handlers |
| `wp queuety workflow timeline <id>` | Show the full event timeline for a workflow |
| `wp queuety workflow state-at <id> <step>` | Show workflow state snapshot at a specific step |
| `wp queuety schedule list` | List recurring schedules |
| `wp queuety schedule add <handler> [--every=<interval>] [--cron=<expr>]` | Add a recurring schedule |
| `wp queuety schedule remove <handler>` | Remove a schedule |
| `wp queuety schedule run` | Manually trigger scheduler tick |
| `wp queuety log [--workflow=<id>] [--job=<id>]` | Query log entries |
| `wp queuety log purge --older-than=<days>` | Prune old logs |
| `wp queuety webhook add <event> <url>` | Register a webhook |
| `wp queuety webhook list` | List webhooks |
| `wp queuety webhook remove <id>` | Remove a webhook |
