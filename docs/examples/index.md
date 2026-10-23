---
title: "Examples"
description: "End-to-end examples that show how to use Queuety in real applications."
path: "examples"
order: 470
section: "Examples"
meta_title: "Examples"
meta_description: "End-to-end examples that show how to use Queuety in real applications."
---

# Examples

These examples target `neuron-core/neuron-ai` **4.1.13** on PHP 8.2+. Queuety owns durable workflows, claim fencing, retries, checkpoints, tools, and review gates. Neuron supplies provider adapters, message types, and tool schemas. Each inference step sends one provider request and returns its result to Queuety.

## Before you start

Install the tested optional integration in your application:

```bash
composer require neuron-core/neuron-ai:4.1.13 symfony/polyfill-mbstring:1.43.0
```

The provider transport requires cURL. Use mbstring, or iconv with the included mbstring polyfill. Initialize Queuety through its [standalone setup](/docs/features/standalone-use) or WordPress adapter. Keep credentials in your application's trusted credential resolver; queue payloads contain a credential reference, never the secret itself. The `Credentials` class used below is an application adapter you supply.

Use `Queuety::tick(action_seconds: 20, lease_seconds: 30)` for one action. Provider clients need their own connection and total request deadlines inside that budget. A database lease and PHP's request timeout do not interrupt arbitrary network I/O.

## Ownership and current Neuron APIs

Do not call `Agent::chat()` from these steps: in Neuron 4 it drives Neuron's own workflow and possible tool loop. That API requires `setThreadId(...)` and returns `AgentState`. The provider API used here returns `ProviderResponse`, whose `message()` contains the result. Providers return tool calls as data; Queuety schedules each authorized tool action separately.

`SystemPrompt` lives under `NeuronAI\Agent\SystemPrompt`. The old `SQLChatHistory` and `chatHistory()` extension point are absent in this version. Persist versioned JSON messages in Queuety state or an application checkpoint store and restore them with `MessageDeserializer`. Both provider and agent `stream()` return generators; there is no `events()` wrapper.

A killed request may have reached the provider before Queuety stored its result. Retrying that request may charge again. Applications need an explicit retry/reconciliation policy for ambiguous requests and an idempotency policy for external tool effects.

## Neuron examples

- [Planner / Executor](/docs/examples/neuron-planner-executor): bounded providers and independent child workflows.
- [Session State Machine](/docs/examples/neuron-session-state-machine): short lifecycle actions that queue inference workflows.
- [Streaming Step](/docs/examples/neuron-streaming-step): provisional attempt output and an explicit completion marker.
- [Memory + Review](/docs/examples/neuron-memory-review): JSON conversation checkpoints across review rounds.
- [Dependent Workflows](/docs/examples/neuron-dependent-workflows): park a writer until research finishes.

The provider factory, message checkpoint, and streaming snippets are executable documentation. `tests/examples/check-neuron.php` loads those exact code blocks and tests them with injected fake HTTP responses on PHP 8.2 and current PHP. No credentials or paid provider calls are used. See [the test manifest](https://github.com/inline0/queuety/blob/main/tests/examples/composer.json).

The pinned source contracts are [AIProviderInterface](https://github.com/neuron-core/neuron-ai/blob/4.1.13/src/Providers/AIProviderInterface.php), [ProviderResponse](https://github.com/neuron-core/neuron-ai/blob/4.1.13/src/Providers/ProviderResponse.php), and [MessageDeserializer](https://github.com/neuron-core/neuron-ai/blob/4.1.13/src/Chat/Messages/MessageDeserializer.php).
