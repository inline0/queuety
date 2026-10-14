---
title: "Debug Mode"
description: "Verbose worker logging for development and troubleshooting."
path: "features/debug"
order: 400
section: "Features"
meta_title: "Debug Mode"
meta_description: "Verbose worker logging for development and troubleshooting."
---

# Debug Mode

Enable debug mode for verbose worker output during development. When enabled, the worker logs additional information about every job claim, execution, and state transition.

## Enabling debug mode

Define the constant in `wp-config.php`:

```php
define( 'QUEUETY_DEBUG', true );
```

## What it does

With debug mode enabled:

- The worker writes `LogEvent::Debug` entries to the log table for claim attempts, empty queues, resource-admission deferrals, scheduler ticks, rate-limit checks and unclaims, job-class deserialization, and handler resolution
- Each debug entry carries the handler `_worker`, the queue, and a `message` in its context; job start, completion, and failure are already logged as regular events

## Checking debug status

```php
use Queuety\Config;

if ( Config::debug() ) {
    // Debug mode is active
}
```

## When to use it

- **During development.** Trace the full lifecycle of jobs and workflows to verify behavior.
- **Troubleshooting failures.** Get detailed context around why a job failed or a workflow stalled.
- **Performance investigation.** Combine the debug trail with the `duration_ms` of regular `completed` events and the [metrics](/docs/features/metrics) API.

Disable debug mode in production to avoid filling the log table with high-volume debug entries. Debug entries stay until you purge logs with `Queuety::purge_logs()`; nothing purges them automatically.
