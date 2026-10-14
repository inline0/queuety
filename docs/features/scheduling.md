---
title: "Scheduling"
description: "Recurring jobs with interval and cron expressions."
path: "features/scheduling"
order: 270
section: "Features"
meta_title: "Scheduling"
meta_description: "Recurring jobs with interval and cron expressions."
---

# Scheduling

Queuety has built-in support for recurring jobs. Instead of relying on `wp_cron`, schedules are stored in the database and evaluated by a long-running `wp queuety work` process (or the parent of a worker pool) about once a minute. `wp queuety work --once`, `wp queuety flush`, and the plugin's built-in WP-Cron fallback do not evaluate schedules ([#14](https://github.com/inline0/queuety/issues/14)); without a long-running worker, run `wp queuety schedule run` yourself.

## Interval scheduling

Use `every()` for simple interval-based schedules. The expression is any string compatible with PHP's `DateTimeImmutable::modify()`:

```php
use Queuety\Queuety;

Queuety::schedule( 'cleanup_handler' )
    ->every( '1 hour' )
    ->on_queue( 'maintenance' );

Queuety::schedule( 'sync_inventory' )
    ->every( '30 minutes' )
    ->on_queue( 'sync' );

Queuety::schedule( 'daily_report', [ 'type' => 'summary' ] )
    ->every( '1 day' );
```

## Cron scheduling

Use `cron()` for standard 5-field cron expressions:

```php
// Every day at 3am
Queuety::schedule( 'nightly_cleanup' )
    ->cron( '0 3 * * *' );

// Every Monday at 9am
Queuety::schedule( 'weekly_digest' )
    ->cron( '0 9 * * 1' )
    ->on_queue( 'emails' );

// Every 15 minutes
Queuety::schedule( 'health_check' )
    ->cron( '*/15 * * * *' );
```

## Schedule options

The `PendingSchedule` builder supports these methods:

| Method | Description |
|---|---|
| `every( string $interval )` | Set an interval expression |
| `cron( string $expression )` | Set a cron expression |
| `on_queue( string $queue )` | Set the target queue (default: `'default'`) |
| `overlap( OverlapPolicy $policy )` | Set the overlap policy (default: `Allow`) |

You must call either `every()` or `cron()` before the schedule dispatches. If neither is set, a `RuntimeException` is thrown.

## Overlap policies

When a scheduled job is still running (pending or processing) at the time the next tick fires, the overlap policy determines what happens. Set it with the `overlap()` method:

```php
use Queuety\Queuety;
use Queuety\Enums\OverlapPolicy;

Queuety::schedule( 'sync_inventory' )
    ->every( '5 minutes' )
    ->overlap( OverlapPolicy::Skip )
    ->on_queue( 'sync' );
```

The `OverlapPolicy` enum has three cases:

| Policy | Value | Behavior |
|---|---|---|
| `OverlapPolicy::Allow` | `allow` | Always dispatch a new job, even if the previous run is still active. This is the default. |
| `OverlapPolicy::Skip` | `skip` | Skip the current tick if the handler has any pending or processing jobs. The `next_run` is updated normally so the schedule stays on cadence. |
| `OverlapPolicy::Buffer` | `buffer` | Like Skip, the tick is not dispatched and `next_run` advances to the next regular occurrence. Unlike Skip, `last_run` is not updated. |

### Allow (default)

Multiple instances of the same handler can run concurrently. Use this for idempotent handlers where overlap is harmless.

```php
Queuety::schedule( 'send_heartbeat_ping' )
    ->every( '1 minute' )
    ->overlap( OverlapPolicy::Allow );
```

### Skip

The tick is silently skipped if the handler is still running. Use this for handlers where running a second instance would be wasteful or cause conflicts.

```php
Queuety::schedule( 'rebuild_search_index' )
    ->every( '10 minutes' )
    ->overlap( OverlapPolicy::Skip );
```

### Buffer

Behaves like Skip today: when the handler still has a pending or processing job, the tick is not dispatched and `next_run` moves to the next regular occurrence. The only difference is that Buffer leaves `last_run` unchanged, while Skip records the skipped tick as the last run.

```php
Queuety::schedule( 'sync_inventory' )
    ->every( '5 minutes' )
    ->overlap( OverlapPolicy::Buffer );
```

## How it works

When a schedule is created, Queuety stores it in the `queuety_schedules` table with the expression, handler, payload, and the next calculated run time.

On each scheduler tick (about once a minute in a long-running worker, or when you run `wp queuety schedule run`), the scheduler checks for schedules whose `next_run` has passed. For each due schedule, it dispatches a new job and updates `next_run` to the next occurrence.

## Managing schedules via CLI

```bash
# List all schedules
wp queuety schedule list

# Add a recurring schedule
wp queuety schedule add cleanup_handler --every="1 hour"
wp queuety schedule add nightly_report --cron="0 3 * * *" --queue=reports

# Remove a schedule
wp queuety schedule remove cleanup_handler

# Manually trigger the scheduler
wp queuety schedule run
```

## Managing schedules via PHP

```php
// Get the scheduler instance
$scheduler = Queuety::scheduler();

// List all schedules
$schedules = $scheduler->list();

// Remove a schedule by handler name
$scheduler->remove( 'cleanup_handler' );

// Manually trigger a tick
$count = $scheduler->tick();
```

## Payload

Schedules can include a static payload that gets passed to each dispatched job:

```php
Queuety::schedule( 'generate_report', [ 'type' => 'daily', 'format' => 'pdf' ] )
    ->every( '1 day' )
    ->on_queue( 'reports' );
```

Every time the schedule fires, a new job is dispatched with this payload.
