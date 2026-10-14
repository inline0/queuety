---
title: "Heartbeats"
description: "Activity heartbeats for long-running steps and streaming handlers."
path: "features/heartbeats"
order: 410
section: "Features"
meta_title: "Heartbeats"
meta_description: "Activity heartbeats for long-running steps and streaming handlers."
---

# Heartbeats

Long-running jobs and streaming steps can send heartbeats to prove they are still alive. Without heartbeats, the stale job detector may reclaim a job that is still actively processing because its `reserved_at` timestamp has not been updated.

## How it works

When a worker starts processing a job, it calls `Heartbeat::init()` to set the current job context. Inside your handler, calling `Heartbeat::beat()` updates the job's `reserved_at` column to `NOW()`. The stale detector uses `reserved_at` to determine whether a job is stuck, so refreshing it keeps the job safe.

When the job finishes, the worker calls `Heartbeat::clear()` to reset the context.

## Sending heartbeats

Call `Heartbeat::beat()` from inside any job handler or streaming step:

```php
use Queuety\Heartbeat;

class LongRunningStep implements \Queuety\Step {

    public function handle( array $state ): array {
        $items = $state['items'];

        foreach ( $items as $i => $item ) {
            $this->process( $item );
            Heartbeat::beat( [ 'processed' => $i + 1, 'total' => count( $items ) ] );
        }

        return [ 'done' => true ];
    }

    public function config(): array {
        return [];
    }

    private function process( $item ): void {
        // Expensive work here.
    }
}
```

## Progress data

The `$progress` parameter is an optional associative array. When provided, it is JSON-encoded and stored in the `heartbeat_data` column on the job row. This lets you track progress from outside the worker:

```php
Heartbeat::beat( [
    'processed' => 450,
    'total'     => 1000,
    'percent'   => 45,
] );
```

When called without arguments, only `reserved_at` is updated:

```php
Heartbeat::beat();
```

## Heartbeat API

The `Queuety\Heartbeat` class is entirely static. You do not instantiate it.

| Method | Description |
|---|---|
| `Heartbeat::init( int $job_id, Connection $conn )` | Set the job context. Called automatically by the worker before processing. |
| `Heartbeat::beat( array $progress = [] )` | Send a heartbeat. Updates `reserved_at` and optionally stores progress data. |
| `Heartbeat::clear()` | Clear the job context. Called automatically by the worker after processing. |
| `Heartbeat::current_job_id()` | Get the current job ID (useful for testing). |

## Usage in streaming steps

The worker already sends a heartbeat after every chunk it persists, with `heartbeat_data` set to `{"streaming_chunks": n}`, so a stream that yields regularly stays fresh on its own. Call `Heartbeat::beat()` yourself when there can be long gaps between yields, for example while waiting for the first token. Data you pass is overwritten by the worker's next per-chunk beat:

```php
use Queuety\Contracts\StreamingStep;
use Queuety\Heartbeat;

class StreamAPIResponse implements StreamingStep {

    public function stream( array $state, array $existing_chunks = [] ): \Generator {
        $response = $this->client->stream( $state['prompt'] );

        foreach ( $response as $i => $chunk ) {
            Heartbeat::beat( [ 'chunks' => $i + 1 ] );
            yield $chunk;
        }
    }

    public function on_complete( array $chunks, array $state ): array {
        return [ 'response' => implode( '', $chunks ) ];
    }

    public function config(): array {
        return [];
    }
}
```

## When to use heartbeats

Use heartbeats when a single job or step runs for longer than `QUEUETY_STALE_TIMEOUT` seconds (default: 600). Common cases:

- LLM API calls with streaming responses
- Large file downloads or uploads
- Batch processing repetitions inside a single step
- Any external API call that may take minutes to respond

If your step completes in under a minute, heartbeats are unnecessary. The stale detector only kicks in after the configured timeout has elapsed.
