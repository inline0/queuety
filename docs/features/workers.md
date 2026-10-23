---
title: "Workers"
description: "Worker lifecycle, concurrency, and production deployment."
path: "features/workers"
order: 340
section: "Features"
meta_title: "Workers"
meta_description: "Worker lifecycle, concurrency, and production deployment."
---

# Workers

Workers are long-running processes that poll the database for pending jobs, execute them, and advance workflows. The `wp queuety work` command runs inside WP-CLI while the queue itself is claimed directly from MySQL through the best available Queuety DB driver.

The plugin also installs a default once-per-minute WordPress cron fallback for environments without shell access. This page covers dedicated WP-CLI workers, which are the recommended higher-throughput deployment mode.

## One-action execution

`Queuety::tick($queues, action_seconds: 20, lease_seconds: 30)` processes at most
one admitted action without maintenance. It works without WordPress or `pcntl`.
Use the separate bounded `Queuety::maintenance_batch()` API to recover expired
claims, tick schedules and prune retention. See [Standalone Use](/docs/features/standalone-use)
for deployment, cursors, transport limits and upgrades. `run(..., once: true)`
continues to run a bounded maintenance pass before its one job; `flush()` checks
its optional budget between jobs.

## Ownership and outcomes

A claim carries a fresh token, database-time lease and fixed action deadline.
The worker passes that original token to every mutation. A paused workflow is
not claimable, and pausing or cancelling revokes active results. Resume uses a
transaction and reuses or uniquely schedules the next action. A worker that
loses ownership stops publishing; maintenance recovers the expired claim with a
conditional update. Recovery cannot reopen a job that a different worker already
completed or reclaimed.

Handlers choose outcomes explicitly:

| Outcome | Handler behavior | Durable result |
|---|---|---|
| Complete | Return normally | Commit completion and workflow output |
| Wait | Throw `WaitException($availableAt)` | Release until that time, refund the attempt |
| Retry at | Throw `RetryAtException($availableAt)` | Release until that time, consume the attempt |
| Terminal failure | Throw `TerminalFailureException($reason)` | Bury immediately and fail the owning workflow |
| Transient failure | Throw another exception | Retry with configured backoff until attempts are exhausted |

The two scheduling exceptions take a `DateTimeImmutable` and optional message.
Retry-at still obeys `max_attempts`. Requested times in the past are clamped to at least one database second
in the future to avoid immediately reclaiming the same row. Use workflow signal/approval steps for human waits.
These outcomes preserve the existing handler interfaces; they do not make
external effects transactional. If the database action deadline has already
expired, even failure/completion writes are refused and recovery decides the
next attempt.

```php
use Queuety\Exceptions\RetryAtException;
use Queuety\Exceptions\TerminalFailureException;
use Queuety\Exceptions\WaitException;

// Inside a handler, after validating its current authority:
throw new WaitException( new DateTimeImmutable( '+5 minutes' ) );
// Or: throw new RetryAtException( $providerRetryAt );
// Or: throw new TerminalFailureException( 'Input is no longer authorized.' );
```

Manual queue integrations must retain the returned Job and pass
`claim_token: $job->claim_token` to `complete`, `fail`, `retry`, `unclaim`, `bury`,
`release` and `reschedule`. Tokenless calls cannot mutate processing jobs.
Administrative facade controls obtain the current revision and compare it when
changing the job; they invalidate previous claims.

## Atomic application checkpoints

Stage application database writes with `ExecutionContext::on_completion()`:

```php
use Queuety\Connection;
use Queuety\ExecutionContext;
use Queuety\Job;

ExecutionContext::on_completion( static function ( Connection $conn, Job $job ): void {
    $stmt = $conn->pdo()->prepare(
        'INSERT INTO factory_receipts (operation_id, job_id) VALUES (:operation, :job)'
    );
    $stmt->execute( [ 'operation' => 'report:42:render', 'job' => $job->id ] );
} );
```

The application owns its receipt table and operation uniqueness. This callback
runs inside the actual fenced completion transaction together with workflow
state, job completion and next-job dispatch. Throwing rolls all of them back.
Callbacks must use that connection, perform only database work and never commit,
roll back or make network calls. A handler that leaves its own transaction open
is rolled back and fails permanently; it cannot defer the worker's commit. Calling the same connection earlier in the
handler does not join this transaction. Remote tool effects require their own
idempotency or reconciliation ledger.

## Starting a worker

```bash
wp queuety work
```

This starts a single worker on the `default` queue. It runs in a loop: claim a job, execute it, repeat. When the queue is empty, it sleeps for `QUEUETY_WORKER_SLEEP` seconds (default: 1) before polling again.

### Options

| Option | Description |
|---|---|
| `--queue=<queue>` | Queue(s) to process, comma-separated for priority ordering (default: `default`) |
| `--once` | Process at most one job and exit |
| `--workers=<n>` | Fork N worker processes (requires the `pcntl` and `posix` extensions) |
| `--min-workers=<n>` | Minimum worker count for an adaptive pool. Requires `--max-workers` |
| `--max-workers=<n>` | Maximum worker count for an adaptive pool |

```bash
# Process a specific queue
wp queuety work --queue=emails

# Process at most one job and exit
wp queuety work --once

# Run 4 concurrent workers
wp queuety work --workers=4

# Scale between 2 and 6 workers as backlog changes
wp queuety work --queue=providers --min-workers=2 --max-workers=6
```

## Multi-queue priorities

A single worker can process multiple queues in priority order. Pass a comma-separated list of queue names to `--queue`:

```bash
wp queuety work --queue=critical,default,low
```

The worker tries to claim a job from each queue in the listed order. It checks `critical` first. If `critical` has a job, it processes that job. If `critical` is empty, it moves on to `default`, and then to `low`. This means higher-priority queues always drain before lower-priority ones.

### How priority ordering works

On each iteration, the worker tries to claim from the first queue in the list. If that queue is empty or paused, it moves on to the next queue, and it keeps going until it either finds a job or exhausts the list. If no job is found anywhere, the worker sleeps and retries. This is a strict priority model, which means a steady stream of `critical` jobs will starve `default` and `low`. If lower-priority queues need guaranteed throughput, use dedicated workers instead.

### Using multi-queue in PHP

The `Worker::run()` and `Worker::flush()` methods accept either a string or an array:

```php
// Comma-separated string
Queuety::worker()->run( 'critical,default,low' );

// Array
Queuety::worker()->run( [ 'critical', 'default', 'low' ] );

// flush() also supports multi-queue
$count = Queuety::worker()->flush( 'critical,default,low' );
```

### Deployment strategies

**Dedicated workers per queue** give you full control over concurrency and isolation. Each queue gets its own worker process(es) and cannot be starved by other queues:

```ini
[program:queuety-critical]
command=wp queuety work --queue=critical
numprocs=4

[program:queuety-default]
command=wp queuety work --queue=default
numprocs=2

[program:queuety-low]
command=wp queuety work --queue=low
numprocs=1
```

**Priority ordering with a single worker** is simpler to manage and works well when lower-priority queues can tolerate delays:

```ini
[program:queuety-worker]
command=wp queuety work --queue=critical,default,low
numprocs=2
```

You can also combine both approaches: dedicated workers for `critical` to guarantee throughput, and a priority-ordered worker for everything else:

```ini
[program:queuety-critical]
command=wp queuety work --queue=critical
numprocs=2

[program:queuety-fallback]
command=wp queuety work --queue=default,low
numprocs=2
```

## Resource-aware admission

Workers do more than just claim the next pending row. Before a claimed job starts, Queuety can apply coarse admission checks based on the job's concurrency group and the recent execution profile for that handler.

For the broader model, including how this fits together with job metadata and workflow budgets, see [Resource Management](/docs/features/resource-management).

A concurrency group gives several handlers or job classes a shared ceiling. If you mark several expensive providers with the same group and limit, a worker will release newly claimed work instead of starting it once the group is saturated. That keeps independent queues from stampeding one downstream system just because there are multiple idle workers.

Recent execution logs also feed a simple admission profile. When resource admission is enabled, Queuety looks at recent average duration and peak memory for the handler it is about to run. If the current worker is already close to its configured memory ceiling, a queue or provider group is already at its weighted budget, the current container does not appear to have enough remaining memory for that handler's observed profile, or a `--once` style run is close to its time envelope, Queuety defers the job rather than starting work that is unlikely to finish cleanly.

You opt into the policy from the work definition rather than from the worker command:

```php
class CallProviderStep implements \Queuety\Step {
    public function handle( array $state ): array {
        // ...
    }

    public function config(): array {
        return [
            'concurrency_group' => 'providers',
            'concurrency_limit' => 3,
            'cost_units' => 5,
        ];
    }
}
```

The same metadata can be attached to dispatchable job classes with public properties or to an individual dispatch through `PendingJob::concurrency_group()` and `PendingJob::cost_units()`.

The worker-side admission profile is controlled with these constants:

| Constant | Description |
|---|---|
| `QUEUETY_RESOURCE_ADMISSION` | Enable or disable profile-based admission checks |
| `QUEUETY_RESOURCE_PROFILE_WINDOW_MINUTES` | How far back to look when building recent handler profiles |
| `QUEUETY_RESOURCE_PROFILE_TTL` | Cache TTL for recent handler profiles |
| `QUEUETY_RESOURCE_MEMORY_HEADROOM_MB` | Reserved memory headroom before admitting another job |
| `QUEUETY_RESOURCE_SYSTEM_MEMORY_AWARENESS` | Enable or disable best-effort container or host memory checks |
| `QUEUETY_RESOURCE_SYSTEM_MEMORY_HEADROOM_MB` | Reserved system-memory headroom before admitting another job |
| `QUEUETY_RESOURCE_QUEUE_COST_BUDGETS` | Weighted cost budgets keyed by queue name |
| `QUEUETY_RESOURCE_GROUP_COST_BUDGETS` | Weighted cost budgets keyed by `concurrency_group` |
| `QUEUETY_RESOURCE_TIME_HEADROOM_MS` | Reserved time headroom for one-shot worker runs |

## Worker lifecycle

A worker stops gracefully, after finishing its current job, when any of these conditions are met:

| Condition | Configuration |
|---|---|
| Max jobs processed | `QUEUETY_WORKER_MAX_JOBS` (default: 1000) |
| Max memory exceeded | `QUEUETY_WORKER_MAX_MEMORY` (default: 128 MB) |

After stopping, the worker exits cleanly. In production, a process manager restarts it automatically.

Workers handle SIGTERM and SIGINT by finishing the active job and stopping before the next claim. Pools forward shutdown to their children and wait for them to finish. Signal handlers and asynchronous dispatch settings are restored when a single worker returns.

## Multi-worker concurrency

The `--workers=N` flag forks N child processes using `pcntl_fork()`. Each child creates its own database connection and processes jobs independently. The parent process monitors children and restarts any that crash.

```bash
wp queuety work --queue=default --workers=4
```

Requirements:
The `pcntl` and `posix` PHP extensions must be installed (without `pcntl` the command fails with "pcntl extension is required for --workers=N."), and each child uses its own database connection, so your MySQL `max_connections` setting needs to accommodate the pool. Adaptive pools (`--min-workers`/`--max-workers`) have the same requirements.

## Adaptive worker pools

Adaptive pools keep a minimum number of workers warm, then grow and shrink inside a configured range:

```bash
wp queuety work --queue=providers --min-workers=2 --max-workers=6
```

The parent process watches claimable backlog in the selected queue set and adjusts the child count toward that backlog, capped by the configured maximum. When the queue quiets down, the pool waits for the idle grace window before scaling back down so short bursts do not immediately churn the process list.

Scale-up is still conservative. When Queuety can read container or host memory telemetry, it refuses to add more children if there is not enough remaining memory for another full worker envelope.

The parent-side scaling loop is controlled with:

| Constant | Description |
|---|---|
| `QUEUETY_WORKER_POOL_SCALE_INTERVAL` | How often the parent reconciles the pool size |
| `QUEUETY_WORKER_POOL_IDLE_GRACE` | How long backlog must stay quiet before scaling back down |

## Stale job recovery

If a worker crashes while processing a job, the job is left in `processing` status. A long-running worker checks about once a minute for jobs that have been in `processing` longer than `QUEUETY_STALE_TIMEOUT` seconds. A stale job with attempts left is retried after its backoff delay; one that has used all its attempts is buried, and its workflow is marked failed. `--once` workers and the WP-Cron fallback do not run this check.

Recover stale jobs manually:

```bash
wp queuety recover
```

## Flush mode

Process all pending jobs and exit. Useful for testing or one-off batch processing:

```bash
wp queuety flush
wp queuety flush --queue=emails
```

## Production deployment

For production, use a process manager to keep workers running. Here are two common approaches.

### Supervisord

```ini
[program:queuety-default]
command=wp queuety work --queue=default
directory=/var/www/html
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/var/log/queuety-default.log
stderr_logfile=/var/log/queuety-default-error.log

[program:queuety-emails]
command=wp queuety work --queue=emails
directory=/var/www/html
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/var/log/queuety-emails.log
stderr_logfile=/var/log/queuety-emails-error.log
```

### Systemd

```ini
[Unit]
Description=Queuety Worker (default queue)
After=mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/local/bin/wp queuety work --queue=default
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable queuety-worker
sudo systemctl start queuety-worker
```

### Deployment tips

Run separate workers for separate queues when you need stronger isolation. Use `--workers=N` when the workload is CPU-bound, and prefer individually supervised workers when the workload is mostly I/O. Set `QUEUETY_WORKER_MAX_JOBS` if you want periodic restarts to free memory, and keep an eye on the `buried` count through [webhooks](/docs/features/webhooks) or [metrics](/docs/features/metrics).
