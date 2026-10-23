---
title: "Neuron Planner / Executor"
description: "Use Neuron agents inside Queuety planner/executor workflows with provider swapping."
path: "examples/neuron-planner-executor"
order: 480
section: "Examples"
meta_title: "Neuron Planner / Executor"
meta_description: "Use Neuron agents inside Queuety planner/executor workflows with provider swapping."
---

# Neuron Planner / Executor

One Queuety workflow performs one specialist inference. A parent workflow plans tasks, starts the children, and waits for their durable results. Neuron supplies a provider adapter without running a second agent loop. This example uses the [pinned integration contract](/docs/examples).

## A provider with a bounded HTTP client

<!-- executable: provider-factory -->
```php
namespace App\Neuron;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use Queuety\ExecutionContext;

final class ProviderFactory
{
    public static function make(
        string $name,
        string $key,
        string $model,
        ?HttpClientInterface $http = null,
    ): AIProviderInterface {
        $remaining = ExecutionContext::remaining_seconds() ?? 20.0;
        $timeout = min(12.0, $remaining - 2.0);
        if ($timeout <= 0.0) {
            throw new \RuntimeException('No provider budget remains.');
        }
        $http ??= new CurlHttpClient(
            timeout: $timeout,
            connectTimeout: min(2.0, $timeout),
        );
        $provider = match ($name) {
            'anthropic' => new Anthropic($key, $model, httpClient: $http),
            'gemini' => new Gemini($key, $model, httpClient: $http),
            'openai' => new OpenAIResponses($key, $model, httpClient: $http),
            default => throw new \InvalidArgumentException('Unknown provider.'),
        };
        return $provider->systemPrompt((string) new SystemPrompt(
            background: ['Return a concise summary of the assigned topic.'],
        ));
    }
}
```

Create a fresh provider for each action. In production, the injected client must enforce the same remaining budget. A response that requests tools must be persisted and handed to Queuety's next action; the provider adapter does not execute the tool.

## One inference step

```php
namespace App\Workflow\Steps;

use App\Ai\Credentials;
use App\Neuron\ProviderFactory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Queuety\ExecutionContext;
use Queuety\Step;

final class ResearchTopicStep implements Step
{
    public function handle(array $state): array
    {
        $credential = Credentials::forRun($state['credential_reference']);
        $provider = ProviderFactory::make(
            $credential->provider,
            $credential->key,
            $credential->model,
        );
        ExecutionContext::assert_active();
        $response = $provider->chat(new UserMessage(
            sprintf('Research %s for brief %d.', $state['topic'], $state['brief_id'])
        ));
        ExecutionContext::assert_active();
        $message = $response->message();
        if ($message instanceof ToolCallMessage) {
            throw new \RuntimeException('This text-only step does not accept tools.');
        }
        return ['summary' => $message->getContent()];
    }

    public function config(): array
    {
        return ['max_attempts' => 1];
    }
}
```

A single attempt avoids silently reissuing an ambiguous paid request. The application can explicitly retry after reconciling its request record. Claim validation before and after the call fences the durable result; it cannot undo a provider request already sent.

## Dispatch independent specialist workflows

```php
use Queuety\Enums\WaitMode;
use Queuety\Queuety;

$specialist = Queuety::workflow('research_agent_run')
    ->then(ResearchTopicStep::class);

Queuety::workflow('brief_research')
    ->version('brief-research.v2')
    ->then(PlanResearchTasksStep::class)
    ->start_agents('agent_tasks', $specialist)
    ->wait_for_agents(mode: WaitMode::All, result_key: 'agent_results')
    ->then(SynthesizeBriefStep::class)
    ->dispatch([
        'brief_id' => 42,
        'credential_reference' => 'research-service',
    ]);
```

`PlanResearchTasksStep` supplies `agent_tasks`, for example `[['topic' => 'pricing'], ['topic' => 'customer reviews']]`. Each task becomes an independently inspectable workflow. The parent resumes with completed child state under `agent_results`. Planning and synthesis classes are application steps; any model call in them follows the same one-request rule.

See [agent orchestration](/docs/workflows/agent-orchestration) and [async handoffs](/docs/workflows/async-handoffs).
