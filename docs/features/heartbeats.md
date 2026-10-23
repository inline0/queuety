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

Heartbeats renew the current claim's database-time lease and can store progress.
Each claim has a fresh opaque token, `lease_expires_at` and a fixed
`action_deadline_at`. The token is independent of retry attempts.

## How it works

The worker supplies the original Job token to `Heartbeat::init()`. Each beat
requires that token, processing status, an unexpired lease and an unexpired
action deadline. An expired owner cannot revive its lease, even before recovery
runs. A replacement claim or administrative cancellation invalidates the old
owner. Rejected beats throw `LostClaimException`; stop producing effects.

Heartbeat renewal never extends the action deadline. Split longer work into
finite actions or choose a larger action profile before claiming it. A heartbeat
is not a way to make arbitrary provider calls run forever.

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

When called without arguments, the lease and `reserved_at` are refreshed:

```php
Heartbeat::beat();
```

## Heartbeat API

The `Queuety\Heartbeat` class is entirely static. You do not instantiate it.

| Method | Description |
|---|---|
| `Heartbeat::init( int $job_id, Connection $conn, ?string $claim_token = null, int $lease_seconds = 600 )` | Set the job context. Called automatically by the worker before processing. |
| `Heartbeat::beat( array $progress = [] )` | Renew a live lease and optionally store progress. Refuses stale ownership. |
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

Beat between bounded operations or streamed chunks when the action may approach
its lease expiry. Apply the action's remaining budget to external transports;
heartbeats cannot run while synchronous PHP is blocked in a remote call.
`ExecutionContext::assert_active()` checks deadline and ownership without renewing.
See [Standalone Use](/docs/features/standalone-use) for HTTP/CLI profiles and
transport limits.
