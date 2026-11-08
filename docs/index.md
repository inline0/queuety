---
title: "Introduction"
description: "What Queuety is, its core concepts (jobs, queues, workers, workflows, state machines), a minimal example and a map of the documentation."
path: "."
order: 0
meta_title: "Introduction to Queuety"
meta_description: "Queuety is a MySQL-backed job queue, durable workflow engine and state machine runtime for WordPress and standalone PHP. Core concepts, example and docs map."
---

# Introduction

Queuety is a WordPress plugin that provides a fast job queue, durable workflow engine, and durable state machine runtime. Workers claim jobs directly from MySQL and process them inside a long-running WP-CLI process. The PHP runtime that loads WordPress and WP-CLI needs either `mysqli` or `pdo_mysql`.

The same core also runs without WordPress. A standalone PHP application loads Composer's autoloader, creates a `Connection` and calls `Queuety::init()`, then runs workers as CLI processes or as finite actions with `Queuety::tick()`. See [Standalone Use](/docs/features/standalone-use).

## Why Queuety?

WordPress has no real background job system. `wp_cron` only fires on page visits. Action Scheduler's default runner rides on WP-Cron and loopback HTTP requests, each working through batches for at most 30 seconds. An LLM API call that takes 60 seconds gets killed by PHP's 30-second timeout. There's no way to run multi-step processes that survive crashes and resume where they left off.

Queuety solves this with three primitives:

1. **Jobs** for fire-and-forget background work
2. **Workflows** for durable multi-step processes with persistent state
3. **State machines** for long-lived, event-driven lifecycle state

## Core concepts

### Jobs and handlers

A job is one unit of background work: a handler name, a JSON payload, a queue, a priority, and a retry budget, stored as a row in the jobs table. There are two ways to define the work:

- A **handler** implements `Queuety\Handler` with `handle( array $payload ): void` and `config(): array`. You register it under a name with `Queuety::register( 'send_email', SendEmailHandler::class )` and dispatch it with `Queuety::dispatch( 'send_email', $payload )`.
- A **job class** implements `Queuety\Contracts\Job` and uses the `Dispatchable` trait. Its constructor properties become the payload, and `SendEmailJob::dispatch( ... )` queues it without a separate registration.

`Queuety::dispatch()` returns a `PendingJob`, so you can chain `on_queue()`, `with_priority()`, `delay()`, `max_attempts()`, `rate_limit()`, `unique()`, `with_dispatch_key()` and `after()` before the job is written. A job moves from `pending` to `processing` to `completed`. A failed attempt returns to `pending` after a backoff, and a job that exhausts its attempts (3 by default) is `buried` until you retry it. See [Jobs](/docs/jobs).

### Queues and priorities

Every job belongs to a named queue, `default` unless you choose another. Within a queue, workers claim higher priorities first: `Priority::Low`, `Normal`, `High` and `Urgent`. A worker can serve several queues in strict order, for example `--queue=high,default,low`. Queues can be paused and resumed. See [Queue Management](/docs/features/queue-management) and [Workers](/docs/features/workers#multi-queue-priorities).

### Workers

A worker is a long-running process that claims jobs with `SELECT ... FOR UPDATE SKIP LOCKED`, runs them, advances workflows, and sleeps when the queue is empty. Start one with `wp queuety work`, or a pool with `--workers=N` or `--min-workers` and `--max-workers`. Each claim carries an ownership token and a lease, so a stale worker cannot complete or retry a job that was recovered and claimed by another one.

Without a dedicated worker, the plugin schedules a WordPress cron event every minute that processes the `default` queue for a 20-second budget, checked between jobs. See [Workers](/docs/features/workers) and the [CLI reference](/docs/cli).

### Workflows and steps

A workflow is an ordered list of steps built with `Queuety::workflow( $name )`. A step implements `Queuety\Step` with `handle( array $state ): array`: it receives the accumulated state and returns keys to merge into it. Each step boundary is one MySQL transaction that saves the new state, completes the current job and enqueues the next one, so a crash only repeats the step that was running.

Beyond plain sequences, the builder adds parallel steps, `for_each()` fan-out, durable `delay()` steps, waits for signals, approvals, input and other workflows, repeats, child workflows, compensation, guardrails and artifacts. See [Workflows](/docs/workflows).

### State machines

A state machine, built with `Queuety::machine( $name )`, models something that lives for a long time and reacts to events: a chat session, a review, a ticket. It has named states, transitions declared with `on( $event, $target )`, optional guards, and queued `action()` jobs that run when a state is entered. Events arrive through `Queuety::machine_event()`. Use a workflow when the question is what work runs next, and a state machine when the question is which events are valid in the current state. See [State Machines](/docs/state-machines).

### Schedules, batches and chains

`Queuety::schedule( $handler )` creates recurring jobs with `every( '1 hour' )` or a five-field `cron( '0 3 * * *' )` expression and an overlap policy. `Queuety::create_batch()` dispatches a group of jobs with `then`, `catch` and `finally` callbacks and progress tracking. `Queuety::chain()` runs jobs one after another. See [Scheduling](/docs/features/scheduling), [Batching](/docs/features/batching) and [Sequential Chains](/docs/workflows/chains).

### Storage and observability

Everything lives in MySQL tables: jobs, workflows, state machines, signals, schedules, batches, streaming chunks, artifacts, locks and logs. There are no log files. Every job and workflow execution writes to the logs table, `Queuety::metrics()` reports throughput, latency and error rates per handler, workflow event logs record every step transition for inspection and time-travel debugging, and webhooks notify external services. See [Logging](/docs/features/logging), [Metrics](/docs/features/metrics), [Event Log](/docs/workflows/event-log) and [Webhooks](/docs/features/webhooks).

## Minimal example

A handler, a two-step workflow, and a worker that runs both. The Queuety plugin initializes itself on `plugins_loaded`, so call its API from `init` or a later hook. Handler names live in memory, so register them on every request, including the WP-CLI request that runs the worker. Step classes and dispatchable job classes are resolved by class name and need no registration.

```php
use Queuety\Handler;
use Queuety\Queuety;
use Queuety\Step;

class SendEmailHandler implements Handler {
    public function handle( array $payload ): void {
        wp_mail( $payload['to'], $payload['subject'], $payload['body'] );
    }

    public function config(): array {
        return [ 'max_attempts' => 5 ];
    }
}

class LoadUserStep implements Step {
    public function handle( array $state ): array {
        $user = get_user_by( 'ID', $state['user_id'] );
        return [ 'email' => $user->user_email ];
    }

    public function config(): array {
        return [];
    }
}

class SendWelcomeStep implements Step {
    public function handle( array $state ): array {
        wp_mail( $state['email'], 'Welcome', 'Thanks for signing up.' );
        return [ 'welcomed' => true ];
    }

    public function config(): array {
        return [];
    }
}

add_action( 'init', function () {
    Queuety::register( 'send_email', SendEmailHandler::class );
} );

add_action( 'user_register', function ( int $user_id ) {
    Queuety::dispatch( 'send_email', [
        'to'      => get_option( 'admin_email' ),
        'subject' => 'New user',
        'body'    => "User {$user_id} signed up.",
    ] );

    Queuety::workflow( 'welcome_user' )
        ->then( LoadUserStep::class )
        ->then( SendWelcomeStep::class )
        ->dispatch( [ 'user_id' => $user_id ] );
} );
```

When a user registers, the request does not send anything. It queues the email job and the workflow's first step, both on the `default` queue. Run a worker to process them:

```bash
wp queuety work
```

The worker runs the email job and `LoadUserStep`. The `email` key that step returns is merged into the workflow state in the same transaction that enqueues `SendWelcomeStep`, which then reads it. The workflow builder's `dispatch()` returns the workflow ID; pass it to `Queuety::workflow_status()` or `wp queuety workflow status <id>` to check progress. [Getting Started](/docs/getting-started) walks through installation, configuration and these steps in more detail.

The same job written as a dispatchable class:

```php
use Queuety\Contracts\Job;
use Queuety\Dispatchable;

readonly class SendEmailJob implements Job {
    use Dispatchable;

    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {}

    public function handle(): void {
        wp_mail( $this->to, $this->subject, $this->body );
    }
}

add_action( 'user_register', function ( int $user_id ) {
    SendEmailJob::dispatch( get_userdata( $user_id )->user_email, 'Welcome', 'Hello from Queuety.' );
} );
```

A state machine for a long-lived session:

```php
use Queuety\Enums\StateMachineStatus;

function start_agent_session( int $thread_id ): int {
    return Queuety::machine( 'agent_session' )
        ->state( 'awaiting_user' )
        ->on( 'user_message', 'planning' )
        ->state( 'planning' )
        ->action( PlanSessionAction::class )
        ->on( 'planned', 'completed' )
        ->state( 'completed', StateMachineStatus::Completed )
        ->dispatch( [ 'thread_id' => $thread_id ] );
}
```

Call `start_agent_session()` from `init` or a later hook, like the rest of the API.

## How it works

Workers run inside a long-lived WP-CLI process and claim jobs directly from MySQL. Queue rows, workflow state, logs, batches, signals, and streaming chunks all live in MySQL, so worker restarts do not lose orchestration state.

Workflows break long-running work into steps. Each step persists its output to a shared state bag in the database. If PHP dies mid-step, the worker retries that step with all prior state intact. The step boundary is a single MySQL transaction: state update, job completion, and next step enqueue all happen atomically.

```
Workflow: generate_report (3 steps)

Step 0: fetch_data     -> state: {user_data: {...}}
Step 1: call_llm       -> PHP dies -> retry -> state: {user_data: {...}, llm_response: "..."}
Step 2: format_output  -> state: {user_data: {...}, llm_response: "...", report_url: "/reports/42.pdf"}

Workflow: completed
```

## How the docs are organized

- **[Getting Started](/docs/getting-started)** covers requirements, installation from the release zip, Composer or source, configuration constants, and a first job, workflow and worker.
- **[Jobs](/docs/jobs)** is the complete guide to dispatching and handling jobs: handlers, priorities, delays, unique jobs, dependencies, retries and backoff, buried jobs, timeouts, dispatchable job classes, middleware, and durable dispatch keys.
- **[Workflows](/docs/workflows)** starts with state accumulation, resumability and the workflow lifecycle, then has one page per building block: [Parallel Steps](/docs/workflows/parallel), [Dynamic For-Each](/docs/workflows/for-each), [Async Handoffs](/docs/workflows/async-handoffs), [Workflow Dependencies](/docs/workflows/dependencies), [Conditional Branching](/docs/workflows/conditional), [Repeats](/docs/workflows/repeats), [Durable Delays](/docs/workflows/delays), [Workflow Signals](/docs/workflows/signals), [Guardrails](/docs/workflows/guardrails), [Compensation](/docs/workflows/compensation), [Streaming Steps](/docs/workflows/streaming), [Cancellation](/docs/workflows/cancellation), and the inspection tools [Event Log](/docs/workflows/event-log), [Time Travel](/docs/workflows/time-travel), [Forking](/docs/workflows/forking) and [Export and Replay](/docs/workflows/export-replay). [Agent Orchestration](/docs/workflows/agent-orchestration) shows how planner and executor flows, review gates and cross-workflow waits fit together.
- **[State Machines](/docs/state-machines)** explains the builder, the action and guard contracts, the inspection APIs, and when to choose a machine over a workflow.
- **Features** covers the runtime around jobs and workflows: [Workers](/docs/features/workers), [Scheduling](/docs/features/scheduling), [Rate Limiting](/docs/features/rate-limiting), [Middleware](/docs/features/middleware), [Batching](/docs/features/batching), [Artifacts](/docs/features/artifacts), [Resource Management](/docs/features/resource-management), [Heartbeats](/docs/features/heartbeats), [Caching](/docs/features/caching), [Logging](/docs/features/logging), [Metrics](/docs/features/metrics), [Webhooks](/docs/features/webhooks), [Testing](/docs/features/testing) with `QueueFake`, [Action Triggers](/docs/features/action-triggers) for starting workflows from WordPress hooks, the [WP-Cron Replacement](/docs/features/wp-cron), and [Standalone Use](/docs/features/standalone-use) outside WordPress.
- **[Examples](/docs/examples)** are end-to-end integrations with the Neuron AI framework: a planner and executor, a session state machine, a streaming step, a memory-backed review loop, and dependent workflows.
- **Reference** lists every [WP-CLI command](/docs/cli) and every public method of the [PHP API](/docs/api).

## Key differentiators

- **Fast execution.** Workers use direct MySQL queue access and avoid the per-request cron pattern that slows down background work in WordPress.
- **Durable workflows.** Multi-step processes with persistent state that survive PHP timeouts, crashes, and retries. Each step boundary is an atomic MySQL transaction.
- **Explicit state machines.** Model chat sessions, approvals, and other long-lived lifecycles with named states, guarded transitions, queued state-entry actions, and durable event timelines.
- **Modern dispatch API.** Self-contained job classes with the `Dispatchable` trait, typed constructor properties as payload, and `$tries`/`$timeout`/`$backoff` declared on the class. A `readonly` class cannot give these properties defaults, so declare them on a regular class with readonly promoted constructor properties.
- **Middleware pipeline.** Onion-style middleware for rate limiting, exception throttling, unique jobs, and custom logic. Declared per-job via a `middleware()` method.
- **Streaming steps.** The `StreamingStep` interface yields chunks that are persisted to the database immediately. On retry, saved chunks remain available under the new attempt; the source must support resumption. A partial LLM stream is not a completion receipt.
- **Pluggable cache layer.** `CacheFactory` defaults to isolated MemoryCache; installation-scoped APCu is opt-in. Custom backends implement `Contracts\Cache`. Used internally for rate-limiter lookups and queue state.
- **Batching and chaining.** Dispatch groups of jobs with `then`/`catch`/`finally` callbacks and progress tracking, or chain jobs sequentially where each depends on the previous one completing.
- **Durable delays and waits.** `delay()` adds process-restart-safe delays. `wait_for_signal()`, `wait_for_signals()`, `wait_for_approval()`, `wait_for_input()`, and `wait_for_workflow()` let workflows pause on external events or other workflows without keeping PHP alive.
- **Dynamic for-each.** `for_each()` expands runtime-discovered branch work from workflow state and supports all-success, first-success, and quorum completion modes.
- **Async workflow handoffs.** `start_workflows()` turns runtime-discovered items into independent top-level workflows that can be waited on later.
- **WordPress action triggers.** `on_action()` maps core or plugin action hooks into durable workflow dispatches with gating and idempotency.
- **Durable artifacts.** Store named workflow outputs outside the main state bag and carry them through status, CLI inspection, export, and replay.
- **Workflow guardrails.** `version()`, a deterministic definition hash, idempotent dispatch keys, and workflow budgets make agent-style runs easier to inspect and harder to duplicate or let run away silently.
- **Resource management.** Concurrency groups, cost units, workflow budgets, and worker admission help keep expensive agent-style work inside a defined envelope.
- **Step compensation.** `compensate_with()` and `compensate_on_failure()` provide rollback hooks for completed steps when workflows are cancelled or fail.
- **Heartbeats.** Long-running steps send heartbeats to prevent the stale-job recovery system from reclaiming active work.
- **Workflow tracing.** Every step transition is recorded with input, output, before/after state, context, artifacts, chunks, errors, and time-travel debugging via `workflow_state_at()`.
- **Parallel steps.** Run steps concurrently and wait for all to complete before advancing.
- **Conditional branching.** Skip to named steps based on prior state using `_next_step`.
- **Run Workflows.** Start child workflows that feed results back to the parent.
- **Priority queues.** Four levels (Low, Normal, High, Urgent) via type-safe PHP enums.
- **Multi-queue workers.** Process multiple queues with strict priority ordering in a single worker.
- **Rate limiting.** Per-handler execution limits with sliding window.
- **Recurring jobs.** Interval-based and cron-based scheduling with configurable overlap policies (Allow, Skip, Buffer).
- **Workflow cancellation.** Cancel running workflows and trigger registered cleanup handlers.
- **State pruning.** Automatically remove old step outputs to keep large workflow state manageable.
- **Worker concurrency.** `--workers=N` forks multiple processes with automatic restart on crash.
- **Full observability.** Database logging, per-handler metrics, and event webhooks.
- **Testing utilities.** `QueueFake` records dispatched jobs and batches for assertions in unit tests.
- **PHP attributes.** `#[QueuetyHandler('name')]` for zero-config handler registration.

## Requirements

- PHP 8.2 or later
- WordPress 6.4 or later
- MySQL 8.0+ or MariaDB 10.6+, because workers claim jobs with `SELECT ... FOR UPDATE SKIP LOCKED`
- `mysqli` or `pdo_mysql` in the PHP runtime that loads WordPress and WP-CLI
- WP-CLI to run dedicated workers with `wp queuety work`
- The `pcntl` and `posix` PHP extensions for worker pools (`--workers`, `--min-workers`, `--max-workers`)

See [Getting Started](/docs/getting-started) for installation.
