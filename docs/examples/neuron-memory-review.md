---
title: "Neuron Memory + Review State Machine"
description: "Keep Neuron chat memory across human review rounds with a Queuety state machine."
path: "examples/neuron-memory-review"
order: 510
section: "Examples"
meta_title: "Neuron Memory + Review State Machine"
meta_description: "Keep Neuron chat memory across human review rounds with a Queuety state machine."
---

# Neuron Memory + Review State Machine

Keep conversation context as versioned JSON alongside the Queuety run. Every review round restores those messages and sends one bounded provider request. No Neuron `Agent` workflow or SQL history subsystem is needed. See the [integration contract](/docs/examples) and [provider factory](/docs/examples/neuron-planner-executor).

## A JSON conversation checkpoint

<!-- executable: conversation-turn -->
```php
namespace App\Neuron;

use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Queuety\ExecutionContext;

final class ConversationTurn
{
    public static function run(
        AIProviderInterface $provider,
        array $checkpoint,
        string $prompt,
    ): array {
        if (($checkpoint['version'] ?? null) !== 1
            || !is_string($checkpoint['thread_id'] ?? null)
            || $checkpoint['thread_id'] === '') {
            throw new \InvalidArgumentException('Unsupported conversation checkpoint.');
        }
        $decoder = new MessageDeserializer();
        $messages = array_map(
            $decoder->deserialize(...),
            $checkpoint['messages'],
        );
        $messages[] = new UserMessage($prompt);
        ExecutionContext::assert_active();
        $message = $provider->chat(...$messages)->message();
        ExecutionContext::assert_active();
        $messages[] = $message;
        return [
            'version' => 1,
            'thread_id' => $checkpoint['thread_id'],
            'messages' => json_decode(
                json_encode($messages, JSON_THROW_ON_ERROR),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'status' => $message instanceof ToolCallMessage
                ? 'waiting_for_tool'
                : 'completed',
            'reply' => $message->getContent(),
        ];
    }
}
```

The `thread_id` is your application's stable conversation identity. It is independent of a queue claim token, which changes whenever a worker claims the action. Message serialization retains provider tool-call IDs so an authorized tool result can be attached to its original call in a later action.

## Draft once per workflow action

```php
namespace App\Workflow\Steps;

use App\Ai\Credentials;
use App\Neuron\ConversationTurn;
use App\Neuron\ProviderFactory;
use Queuety\Step;

final class DraftBriefStep implements Step
{
    public function handle(array $state): array
    {
        $credential = Credentials::forRun($state['credential_reference']);
        $provider = ProviderFactory::make(
            $credential->provider,
            $credential->key,
            $credential->model,
        );
        $checkpoint = ConversationTurn::run(
            $provider,
            $state['checkpoint'],
            $state['prompt'],
        );
        if ($checkpoint['status'] !== 'completed') {
            throw new \RuntimeException('This drafting workflow expects text only.');
        }
        return [
            'checkpoint' => $checkpoint,
            'draft_markdown' => $checkpoint['reply'],
        ];
    }

    public function config(): array
    {
        return ['max_attempts' => 1];
    }
}
```

For the first round, initialize `checkpoint` to `['version' => 1, 'thread_id' => 'brief:42:editor', 'messages' => []]`. Later rounds pass the previous committed checkpoint and a prompt containing the reviewer notes. Returning that checkpoint lets Queuety commit it together with the workflow transition. For large conversations, stage the same JSON in an application checkpoint table through `ExecutionContext::on_completion()` using the supplied connection; keep a reference in workflow state.

## Review lifecycle

Use the short dispatch action and completion handoff from [Session State Machine](/docs/examples/neuron-session-state-machine). Its inference workflow can run `DraftBriefStep` and then the result notification step. The state machine waits in `awaiting_review`, accepts `approve` to complete, or `reject` to dispatch another drafting workflow with the last checkpoint and new notes.

An inference timeout or lost connection leaves an ambiguous provider outcome. Preserve the previous committed checkpoint and ask your retry policy whether a new paid attempt is allowed. Partial or uncommitted output must never replace the last completed draft.

Before appending a tool result, validate the tool name, arguments, authorization, and stable invocation identity. Queuety schedules that work separately. Do not call Neuron's tool executor from the inference step or store PHP callables in the checkpoint.

See [workflow signals](/docs/workflows/signals) and [state machines](/docs/state-machines).
