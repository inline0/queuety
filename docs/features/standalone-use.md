---
title: "Standalone Use"
description: "Use Queuety's DB-backed core outside WordPress."
path: "features/standalone-use"
order: 460
section: "Features"
meta_title: "Standalone Use"
meta_description: "Use Queuety's DB-backed core outside WordPress."
---

# Standalone Use

Queuety's queue, workflows, state machines, signals, artifacts, scheduler and
workers run without WordPress. Load Composer's autoloader and initialize an
explicit connection. PHP 8.2+ and MySQL 8.0+ or MariaDB 10.6+ are required.
Enable PHP's PDO base extension plus either `mysqli` or `pdo_mysql`. The mysqli
adapter also uses PDO's base classes; it does not require `pdo_mysql`.

The WordPress plugin supplies activation, WP-CLI, cron and hook adapters.
Standalone workers do not load `queuety.php`, `wp-load.php`, WordPress functions
or WordPress database constants.

## Installation and startup

Install the schema once during deployment, before starting workers:

```php
require __DIR__ . '/vendor/autoload.php';

use Queuety\Connection;
use Queuety\Queuety;
use Queuety\Schema;

$conn = new Connection(
    host: '127.0.0.1:3306',
    dbname: 'factory',
    user: 'factory',
    password: $databasePassword,
    prefix: '',
    table_prefix: 'factory_',
);

Schema::install( $conn ); // Deployment operation, not a request startup hook.
Queuety::init( $conn );
```

Subsequent worker requests call only `Queuety::init($conn)`. It checks that the
schema version is compatible and ready; it performs no DDL. The explicit
`Queuety::ensure_schema($conn)` facade delegates installation/upgrades to
`Schema::install()`. Initialization rejects missing, older, newer and draining
schemas instead of silently migrating them.

Register the same stable handler names in every worker process, then use the
normal dispatch, workflow, signal and inspection APIs. Loading a handler class
must not depend on WordPress being bootstrapped.

## Finite HTTP actions and maintenance

A shared host can invoke one finite action per authorized HTTP request without
shell access, `pcntl`, Redis or a persistent daemon:

```php
Queuety::init( $conn );
Queuety::register( 'infer', InferStep::class );
$attempted = Queuety::tick( 'agents', action_seconds: 20, lease_seconds: 30 );
```

`tick()` attempts at most one admitted job and returns its original claim, or
`null` when none was admitted. Inspect durable job/workflow state for the outcome.
It does not run scheduling, recovery or retention. Invoke maintenance separately
and persist its returned phase cursor between wakeups:

```php
$result = Queuety::maintenance_batch( limit: 50, cursor: $savedCursor );
$savedCursor = $result['cursor'];
```

The five phases are expired-claim recovery, due schedules, workflow deadlines,
completed-job retention and log retention. Each considers a bounded batch. A
cursor cycles through phases; processed rows are removed or advanced, avoiding
OFFSET scans of a changing backlog. A batch limit bounds records, not elapsed
wall time or the size of a workflow's compensation graph. Do not run arbitrary
external compensation I/O in a short maintenance request.

The 20-second action/30-second lease is an example HTTP profile. A CLI runner can
use `tick('agents', 120, 150)` under its own watchdog. Both execute the same core.
The embedding application owns wakeups, authentication, authorization and the
maximum number of concurrent requests.

## Deadlines and database transport controls

Handlers read `ExecutionContext::remaining_seconds()` and apply the remaining
budget to every provider/tool transport. Call `ExecutionContext::assert_active()`
before another effect or iteration to check both the local monotonic deadline
and current database ownership. This is cooperative: arbitrary blocking PHP,
DNS, database or extension code cannot be preempted portably. Configure an
external process watchdog when a hard cutoff is required.

A conservative HTTP database profile can use mysqlnd-backed mysqli:

```php
$conn = new Connection(
    host: '127.0.0.1:3306',
    dbname: 'factory',
    user: 'factory',
    password: $databasePassword,
    prefix: '',
    table_prefix: 'factory_',
    driver: 'mysqli',
    options: [
        'connect_timeout' => 3,
        'read_timeout' => 2,
        'lock_wait_timeout' => 1,
        'metadata_lock_timeout' => 1,
    ],
);
$capabilities = $conn->timeout_capabilities();
```

These optional settings propagate to forked workers. A connect timeout alone
does not bound DNS, authentication or a server that accepts TCP but never sends
a MySQL greeting; the HTTP profile also needs a read timeout. Row and metadata lock
limits control their respective server waits. A read timeout is a transport
limit, not proof that the server cancelled a statement. PDO's connection timeout
does not provide a query-read deadline on PHP 8.2. PDO remains available for
normal applications; an agent HTTP profile that requires bounded database reads
should use mysqlnd/mysqli or a verified external process watchdog. Capability
reporting never claims a hard deadline.

After a transport failure, Queuety discards the connection. A failed COMMIT raises
`CommitOutcomeUnknownException`: the server may have committed even though the
client did not receive confirmation. The worker stops without retrying or
releasing that job. Use a fresh `Connection`, inspect the durable dispatch key,
job, workflow and application receipt, and reconcile before replay. Never turn
an unknown acknowledgement directly into another external effect.

## Upgrading an existing installation

1. Stop enqueueing new work and stop old workers from starting more actions.
2. Let running actions finish. For a pre-0.23 installation, recover abandoned
   processing jobs with the old release while its old schema is still installed.
   Do not leave processing rows behind.
3. Back up the database, deploy the matching code and run `Schema::install($conn)`
   explicitly. It takes a database advisory lock, marks the schema draining,
   refuses active processing jobs, preserves existing logs/events/workflows and
   artifacts, and applies additive migrations.
4. If installation reports active work, finish draining and retry installation.
   A failed migration stays draining; ordinary initialization and claims refuse
   it. Do not force a version flag to bypass the migration.
5. Restart every worker with the new code and stable handler registrations.

The supported additive upgrade starts from the current 0.22 schema layout.
Older unfinished workflows with retired step names or persisted control keys
are refused rather than silently reinterpreted; finish them with their matching
old release or explicitly migrate their definitions before upgrading. Historical
logs and completed records remain preserved.

New workers also check the schema gate while claiming. Old unversioned binaries
cannot understand that gate and must be stopped by the deployer. Downgrading the
code against a newer schema is rejected. WordPress activation installs/upgrades
explicitly; routine `plugins_loaded` initialization does not migrate. After a
plugin update, complete this drain procedure and reactivate during the deployment
window, or invoke `Schema::install()` from the deployment bootstrap.

## Installation and cache isolation

Use a distinct database/table prefix for each independent runtime. A separately
installed WordPress Queuety plugin and an embedded product should not share tables
unless they intentionally run the same version, handler registry and schema.
Changing a table prefix does not isolate PHP class names: use separate processes
or a properly scoped production dependency bundle when two versions coexist.

The facade represents one installation per process. Call `Queuety::reset()` before
initializing another installation; do not multiplex different installations
through its static state concurrently. Fixed and adaptive pools inherit explicit
connection options and create fresh connections in children without WP constants.

MemoryCache is the default. Shared APCu caching is opt-in and installation-scoped:

```php
Queuety::set_cache( new \Queuety\Cache\ApcuCache( $conn->namespace() ) );
Queuety::init( $conn );
```

Cache flush affects only that namespace. Ownership and workflow transition
checks read the database rather than cached status. See [Caching](/docs/features/caching)
and [Workers](/docs/features/workers) for the runtime contracts.
