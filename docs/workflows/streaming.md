---
title: "Streaming Steps"
description: "Durable streaming with automatic chunk persistence and resumability."
path: "workflows/streaming"
order: 190
section: "Workflows"
meta_title: "Streaming Steps"
meta_description: "Durable streaming with automatic chunk persistence and resumability."
---

# Streaming Steps

Streaming steps let a workflow produce output incrementally. Each yielded chunk is written to the database immediately, so if the step fails mid-stream the already-persisted chunks survive and are available on retry.

This is useful for LLM streaming responses, large file processing, and any scenario where partial progress should not be lost.

## The StreamingStep interface

A streaming step implements `Queuety\Contracts\StreamingStep`:

```php
namespace Queuety\Contracts;

interface StreamingStep {
    public function stream( array $state, array $existing_chunks = [] ): \Generator;
    public function on_complete( array $chunks, array $state ): array;
    public function config(): array;
}
```

| Method | Description |
|---|---|
| `stream( $state, $existing_chunks )` | Generator that yields string chunks. Each yield triggers an immediate DB write. |
| `on_complete( $chunks, $state )` | Called after the stream finishes. Receives all chunks (previous + new) and returns data to merge into workflow state. |
| `config()` | Optional configuration. Supports `max_attempts` and `backoff`; use a strategy name or a list of per-attempt delays. |

## How chunks are persisted

Every time your generator yields a value, Queuety calls `ChunkStore::append_chunk()` to write it to the `queuety_chunks` table. There is no batching or buffering. One yield equals one INSERT.

The chunk row stores the `job_id`, `workflow_id`, `step_index`, `claim_token`, `chunk_index` (zero-based), and string `content`. Every write checks live ownership; a superseded attempt cannot overwrite another attempt.

## Resumability

When a streaming step is retried after a failure, the worker copies the latest resumable attempt into the new claim and loads that attempt's chunks and passes them as the `$existing_chunks` parameter. Your implementation decides how to resume:

- **Skip already-processed items** by checking the count of existing chunks
- **Offset into a paginated API** using the chunk count as the page offset
- **Restart non-resumable inference** as a new logical action. Ignoring saved chunks would prepend partial output to a new response and must not be treated as a completed result.

```php
public function stream( array $state, array $existing_chunks = [] ): \Generator {
    $offset = count( $existing_chunks );

    $pages = $this->client->paginate( $state['query'], offset: $offset );

    foreach ( $pages as $page ) {
        yield json_encode( $page );
    }
}
```

## Heartbeats during streaming

Long-running streams should send heartbeats to prevent the stale job detector from reclaiming the job. Call `Heartbeat::beat()` inside your generator loop:

```php
use Queuety\Heartbeat;

public function stream( array $state, array $existing_chunks = [] ): \Generator {
    foreach ( $this->client->stream( $state['prompt'] ) as $i => $chunk ) {
        Heartbeat::beat( [ 'chunks' => $i + 1 ] );
        yield $chunk;
    }
}
```

See [Heartbeats](/docs/features/heartbeats) for details on the Heartbeat API.

## Chunk cleanup

Only after the fenced workflow completion transaction commits does the worker clear that successful attempt's chunks. If next-step dispatch or a checkpoint fails, its chunks remain. Successful cleanup also prunes older attempt data. Its row boundary and token scope preserve a newer replacement attempt. The committed `on_complete()` output is the final result.

If the step fails and is retried, chunks are not deleted. They are passed back via `$existing_chunks` on the next attempt.

## LLM streams are not automatically resumable

A saved prefix of tokens does not establish that a model finished. Do not return
success merely because `$existing_chunks` is non-empty. Most provider streams
cannot resume inference from a token offset. Keep partial output marked as
partial; retry through a new logical request with its own operation identity, or
use a provider protocol that explicitly supports resumption and completion.

The [Neuron streaming example](/docs/examples/neuron-streaming-step) describes
this boundary. Queuety guarantees attempt ownership and transactional publication;
it cannot synthesize a missing provider completion receipt.

## Full example: file processing

```php
use Queuety\Contracts\StreamingStep;
use Queuety\Heartbeat;

class ProcessCSVRows implements StreamingStep {

    public function stream( array $state, array $existing_chunks = [] ): \Generator {
        $file   = fopen( $state['csv_path'], 'r' );
        $offset = count( $existing_chunks );
        $row    = 0;

        // Skip the header.
        fgetcsv( $file );

        while ( $line = fgetcsv( $file ) ) {
            $row++;

            // Skip rows already processed in a previous attempt.
            if ( $row <= $offset ) {
                continue;
            }

            $result = $this->process_row( $line );
            Heartbeat::beat( [ 'row' => $row ] );
            yield json_encode( $result );
        }

        fclose( $file );
    }

    public function on_complete( array $chunks, array $state ): array {
        $results = array_map( 'json_decode', $chunks );
        return [
            'processed_count' => count( $results ),
            'results'         => $results,
        ];
    }

    public function config(): array {
        return [];
    }

    private function process_row( array $line ): array {
        // Your row processing logic here.
        return [ 'id' => $line[0], 'status' => 'ok' ];
    }
}
```

## ChunkStore API

The `ChunkStore` class manages chunk persistence. Access it via `Queuety::chunk_store()`.

```php
$store = Queuety::chunk_store();
```

| Method | Returns | Description |
|---|---|---|
| `get_chunks( int $job_id, ?string $claim_token = null )` | `string[]` | Fetch all chunks for a job, ordered by chunk index |
| `append_chunk( int $job_id, int $chunk_index, string $content, ?int $workflow_id, ?int $step_index )` | `void` | Append a single chunk |
| `clear_chunks( int $job_id, ?string $claim_token = null )` | `void` | Delete the selected attempt; contextless administrative omission selects all attempts |
| `chunk_count( int $job_id )` | `int` | Count the number of chunks for a job |
| `get_accumulated( int $job_id )` | `string` | Concatenate all chunks into one string |

You normally do not need to call `ChunkStore` directly. The worker handles persistence and cleanup automatically when processing a `StreamingStep`. The API is available for advanced use cases such as inspecting in-progress chunks or manual cleanup.
