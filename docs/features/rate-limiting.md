---
title: "Rate Limiting"
description: "Per-handler execution rate limits with sliding windows."
path: "features/rate-limiting"
order: 280
section: "Features"
meta_title: "Rate Limiting"
meta_description: "Per-handler execution rate limits with sliding windows."
---

# Rate Limiting

Queuety supports per-handler rate limiting to prevent overloading external APIs or other constrained resources. When a handler has reached its limit, workers leave its jobs pending and pick them up again once the window has room.

## Setting a rate limit

Apply a rate limit when dispatching a job:

```php
use Queuety\Queuety;

Queuety::dispatch( 'call_openai', $payload )
    ->rate_limit( 10, 60 ); // max 10 executions per 60 seconds
```

The two arguments are:
1. `$max` - Maximum number of executions allowed in the window
2. `$window` - Window duration in seconds

A dispatch-time limit is registered only in the PHP process that dispatches the job. A worker running in a separate process, such as `wp queuety work`, does not see it, so set limits that must hold across processes in the handler's `config()` as shown below ([#3](https://github.com/inline0/queuety/issues/3)).

## Handler-level rate limits

Set rate limits in the handler's `config()` method so they apply to every dispatch:

```php
use Queuety\Handler;

class CallOpenAIHandler implements Handler {
    public function handle( array $payload ): void {
        // Call the API...
    }

    public function config(): array {
        return [
            'rate_limit' => [ 60, 60 ], // 60 requests per minute
        ];
    }
}
```

## How it works

Rate limits are tracked per handler name. After claiming a job, the worker registers the handler's limit (from its `config()`) and checks the rate limiter. If the handler has reached its limit, the worker puts the job straight back to `pending` without a delay and without spending an attempt, then sleeps for `QUEUETY_WORKER_SLEEP` seconds before polling again. A worker started with `--once` exits instead.

Each worker keeps an in-memory counter and refreshes it every 5 seconds from the `queuety_logs` table, counting `completed` log entries for the handler within the last `$window` seconds. Because the count comes from the shared logs table (and from the shared cache, when one is configured), handler-level limits are enforced across all worker processes, not just within a single worker. Failed executions do not count towards the limit.

## Programmatic access

Access the rate limiter directly:

```php
$limiter = Queuety::rate_limiter();

// Register a rate limit
$limiter->register( 'call_openai', 60, 60 );

// Check whether a handler has reached its limit
$limited = $limiter->is_limited( 'call_openai' ); // true while the limit is reached

// Seconds until the current window resets
$wait = $limiter->time_until_available( 'call_openai' );
```

`register()` and `is_limited()` act on the limiter in the current PHP process. Registering a limit here does not change what a separately running worker enforces.

## Use cases

- **External API limits.** Match your rate limit to the API's quota (e.g., OpenAI's 60 RPM).
- **Email throttling.** Prevent sending too many emails in a short window.
- **Resource protection.** Limit CPU-intensive handlers to prevent starving other jobs.
