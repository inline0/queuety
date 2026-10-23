---
title: "Neuron Session State Machine"
description: "Govern a long-lived Neuron agent session with a Queuety state machine while workflows do the heavy work."
path: "examples/neuron-session-state-machine"
order: 490
section: "Examples"
meta_title: "Neuron Session State Machine"
meta_description: "Govern a long-lived Neuron agent session with a Queuety state machine while workflows do the heavy work."
---

# Neuron Session State Machine

Keep session lifecycle changes short. A state action dispatches a Queuety workflow; that workflow performs one bounded Neuron provider request outside the state-machine transaction. A completion callback then delivers its result atomically with the workflow step's completion.

The inference step below is `DraftBriefStep` from [Memory + Review](/docs/examples/neuron-memory-review), using the [bounded provider factory](/docs/examples/neuron-planner-executor). No state action calls a provider, waits for HTTP, or runs a Neuron `Agent` loop.

## Session lifecycle

```php
use App\StateMachines\CurrentTurn;
use App\StateMachines\StartTurnAction;
use Queuety\Queuety;

$machine_id = Queuety::machine('support_session')
    ->version('support-session.v2')
    ->state('awaiting_user')
    ->on('user_message', 'dispatching')
    ->state('dispatching')
    ->action(StartTurnAction::class)
    ->on('turn_dispatched', 'waiting_on_response')
    ->state('waiting_on_response')
    ->on('response_ready', 'awaiting_user', CurrentTurn::class)
    ->dispatch([
        'credential_reference' => 'support-service',
        'checkpoint' => [
            'version' => 1,
            'thread_id' => 'support:customer-42',
            'messages' => [],
        ],
    ]);

Queuety::machine_event($machine_id, 'user_message', [
    'machine_id' => $machine_id,
    'request_id' => 'turn-001',
    'prompt' => 'Compare our pricing with the research supplied in this thread.',
]);
```

The application authenticates incoming events and supplies a stable request ID for each user intent. Retries of that intent reuse the same ID. Another user turn gets a new ID.

## Dispatch a workflow without external I/O

```php
namespace App\StateMachines;

use App\Workflow\Steps\DraftBriefStep;
use App\Workflow\Steps\NotifySessionStep;
use Queuety\Contracts\StateAction;
use Queuety\Contracts\StateGuard;
use Queuety\Queuety;

final class StartTurnAction implements StateAction
{
    public function handle(array $state, ?string $event = null, array $event_payload = [], array $payload = []): array|string
    {
        $request_id = $event_payload['request_id'];
        $workflow_id = Queuety::workflow('session_turn')
            ->idempotency_key('session:' . $state['machine_id'] . ':' . $request_id)
            ->then(DraftBriefStep::class)
            ->then(NotifySessionStep::class)
            ->dispatch([
                'machine_id' => $state['machine_id'],
                'request_id' => $request_id,
                'credential_reference' => $state['credential_reference'],
                'checkpoint' => $state['checkpoint'],
                'prompt' => $event_payload['prompt'],
            ]);
        return [
            'pending_request_id' => $request_id,
            'turn_workflow_id' => $workflow_id,
            '_event' => 'turn_dispatched',
        ];
    }
}

final class CurrentTurn implements StateGuard
{
    public function allows(array $state, array $event_payload, string $event, array $payload = []): bool
    {
        return isset($state['pending_request_id'])
            && $state['pending_request_id'] === ($event_payload['response_request_id'] ?? null);
    }
}
```

Workflow dispatch joins the owning transaction. The action commits the workflow ID and transition to `waiting_on_response` together. The guard uses different names for the pending and received identities so merging an event payload cannot accidentally replace the identity being checked.

## Commit the response handoff

```php
namespace App\Workflow\Steps;

use Queuety\Connection;
use Queuety\ExecutionContext;
use Queuety\Job;
use Queuety\Queuety;
use Queuety\Step;

final class NotifySessionStep implements Step
{
    public function handle(array $state): array
    {
        $machine_id = (int) $state['machine_id'];
        $receipt = [
            'response_request_id' => $state['request_id'],
            'assistant_reply' => $state['draft_markdown'],
            'checkpoint' => $state['checkpoint'],
        ];
        ExecutionContext::on_completion(
            static function (Connection $conn, Job $job) use ($machine_id, $receipt): void {
                Queuety::machine_event($machine_id, 'response_ready', $receipt);
            }
        );
        return [];
    }

    public function config(): array
    {
        return ['max_attempts' => 3];
    }
}
```

The callback performs database work only and uses the initialized Queuety installation's connection. It does not commit independently. If the claim is stale or the state machine rejects the receipt, the enclosing completion transaction rolls back.

After `response_ready`, the session is ready for another user message with the new conversation checkpoint. If inference fails or becomes ambiguous, surface the failed turn to the application for reconciliation; do not silently move the session forward using partial output.

See [state machines](/docs/state-machines), [workflow dependencies](/docs/workflows/dependencies), and [async handoffs](/docs/workflows/async-handoffs).
