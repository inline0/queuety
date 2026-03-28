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
│   ├── Worker.php         # Worker process loop
│   ├── Logger.php         # Database logger
│   ├── Handler.php        # Handler interface (simple jobs)
│   ├── Step.php           # Step handler interface (workflows)
│   ├── PendingJob.php     # Fluent job dispatch builder
│   ├── HandlerRegistry.php # Handler name to class mapping
│   ├── HookDispatcher.php # WordPress action hook dispatcher
│   ├── Schema.php         # Table creation/migration
│   ├── Connection.php     # Direct PDO database connection
│   ├── ConfigParser.php   # wp-config.php credential parser
│   ├── Config.php         # Configuration reader
│   └── Enums/
│       ├── JobStatus.php
│       ├── WorkflowStatus.php
│       ├── Priority.php
│       ├── BackoffStrategy.php
│       └── LogEvent.php
├── cli/
│   ├── QueuetyCommand.php   # WP-CLI job/queue commands
│   ├── WorkflowCommand.php  # WP-CLI workflow commands
│   └── LogCommand.php       # WP-CLI log commands
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
| `QUEUETY_RETENTION_DAYS` | `7` | Auto-purge completed jobs/workflows after N days |
| `QUEUETY_LOG_RETENTION_DAYS` | `0` | Auto-purge logs after N days (0 = keep forever) |
| `QUEUETY_MAX_EXECUTION_TIME` | `300` | Max seconds per job/step |
| `QUEUETY_WORKER_SLEEP` | `1` | Seconds to sleep when queue is empty |
| `QUEUETY_WORKER_MAX_JOBS` | `1000` | Max jobs before worker restarts |
| `QUEUETY_WORKER_MAX_MEMORY` | `128` | Max MB before worker restarts |
| `QUEUETY_RETRY_BACKOFF` | `exponential` | Retry backoff strategy |
| `QUEUETY_STALE_TIMEOUT` | `600` | Seconds before a processing job is considered stale |

## Key Rules

1. **100% WordPress Coding Standards**: no exceptions. Run `composer cs` before committing.
2. **Run tests after changes**: `composer test` for PHPUnit.
3. **bootstrap.php is self-contained**: no autoloader, no WP functions, plain PHP only. Parses wp-config.php via regex.
4. **PHP 8.2+**: use enums, readonly classes, constructor promotion, match expressions, named arguments.
5. **Three-table schema**: jobs, workflows, logs. All in WordPress's MySQL database.
6. **Logging is in the database**: no log files. All log entries go to `queuety_logs` table.
7. **Workflows store step definitions in state**: the `_steps` key in the workflow's state JSON holds the ordered list of handler class names.

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp queuety work [--queue=<queue>] [--once]` | Start a worker |
| `wp queuety flush` | Process all pending jobs and exit |
| `wp queuety dispatch <handler> --payload='{}'` | Dispatch a job |
| `wp queuety status` | Show queue stats |
| `wp queuety list [--queue=<queue>] [--status=<status>]` | List jobs |
| `wp queuety retry <id>` | Retry a job |
| `wp queuety retry-buried` | Retry all buried jobs |
| `wp queuety bury <id>` | Bury a job |
| `wp queuety delete <id>` | Delete a job |
| `wp queuety recover` | Recover stale jobs |
| `wp queuety purge [--older-than=<days>]` | Purge completed jobs |
| `wp queuety workflow status <id>` | Show workflow progress |
| `wp queuety workflow retry <id>` | Retry from failed step |
| `wp queuety workflow pause <id>` | Pause a workflow |
| `wp queuety workflow resume <id>` | Resume a workflow |
| `wp queuety workflow list [--status=<status>]` | List workflows |
| `wp queuety log [--workflow=<id>] [--job=<id>]` | Query log entries |
| `wp queuety log purge --older-than=<days>` | Prune old logs |
