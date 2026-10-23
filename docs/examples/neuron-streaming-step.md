---
title: "Neuron Streaming Step"
description: "Wrap a Neuron streaming agent in a durable Queuety StreamingStep."
path: "examples/neuron-streaming-step"
order: 500
section: "Examples"
meta_title: "Neuron Streaming Step"
meta_description: "Wrap a Neuron streaming agent in a durable Queuety StreamingStep."
---

# Neuron Streaming Step

Stream one bounded provider request and persist its chunks as provisional output for the current claim. An interrupted model generation cannot resume at an arbitrary token offset. A retry starts a new attempt; old partial chunks are not a completed draft. This follows the [pinned integration contract](/docs/examples).

## Stream the provider generator

<!-- executable: provider-stream -->
```php
namespace App\Neuron;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Queuety\ExecutionContext;

final class ProviderStream
{
    public static function text(
        AIProviderInterface $provider,
        string $prompt,
        array $existing_chunks = [],
    ): \Generator {
        if ($existing_chunks !== []) {
            throw new \RuntimeException('Partial output requires an explicit new attempt.');
        }
        ExecutionContext::assert_active();
        $stream = $provider->stream(new UserMessage($prompt));
        foreach ($stream as $chunk) {
            ExecutionContext::assert_active();
            if ($chunk instanceof TextChunk && !$chunk instanceof ReasoningChunk) {
                yield ['type' => 'text', 'text' => $chunk->content];
            }
        }
        ExecutionContext::assert_active();
        $message = $stream->getReturn()->message();
        if ($message instanceof ToolCallMessage) {
            throw new \RuntimeException('This text-only stream does not accept tools.');
        }
        yield ['type' => 'complete'];
    }

    public static function complete(array $chunks): array
    {
        $last = $chunks === [] ? null : $chunks[array_key_last($chunks)];
        if (!is_array($last) || ($last['type'] ?? null) !== 'complete') {
            throw new \RuntimeException('The stream has no completion marker.');
        }
        $text = '';
        foreach ($chunks as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? null) === 'text') {
                $text .= $chunk['text'];
            }
        }
        return ['draft_markdown' => $text];
    }
}
```

The generator's final return value is a `ProviderResponse`. Only a normal end with a final text response emits the completion marker. Reasoning and tool-argument chunks are not published as draft text. Queuety still validates the live claim before committing the final workflow state.

## Wrap it in a Queuety step

```php
namespace App\Workflow\Steps;

use App\Ai\Credentials;
use App\Neuron\ProviderFactory;
use App\Neuron\ProviderStream;
use Queuety\Contracts\StreamingStep;

final class StreamMemoDraftStep implements StreamingStep
{
    public function stream(array $state, array $existing_chunks = []): \Generator
    {
        $credential = Credentials::forRun($state['credential_reference']);
        $provider = ProviderFactory::make(
            $credential->provider,
            $credential->key,
            $credential->model,
        );
        yield from ProviderStream::text($provider, $state['prompt'], $existing_chunks);
    }

    public function on_complete(array $chunks, array $state): array
    {
        return ProviderStream::complete($chunks);
    }

    public function config(): array
    {
        return ['max_attempts' => 1];
    }
}
```

This step uses the same remaining-budget HTTP client as [Planner / Executor](/docs/examples/neuron-planner-executor). A heartbeat or chunk callback cannot interrupt a blocked network read; the client connection and total timeouts bound that wait cooperatively. The request must fit one action budget. Streaming is optional for shared-hosting execution.

Display provisional chunks with their attempt identity and promote them only after the job completes. If a worker dies, the application may explicitly authorize a fresh generation after reconciling its request record; that can incur another provider charge. Never return early merely because some previous chunks exist.

See [streaming steps](/docs/workflows/streaming) and [agent orchestration](/docs/workflows/agent-orchestration).
