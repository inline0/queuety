# Changelog

All notable changes to Queuety are documented in this file.

## [0.22.0] - 2026-05-19

### Changed

- entire codebase now type-checks at PHPStan level max (level 10) with no `@phpstan-ignore`, `assert()`, or inline `@var` escape hatches
- typed private accessors on `Queuety` facade so its singleton dependencies dereference safely after `ensure_initialized()`
- DB row hydration goes through small typed helpers (`Job::row_*`, `Logger::normalize_log_rows`, `WorkflowExporter::row_*`, `BatchManager::normalize_row`, `StateMachineEventLog::decode_event_row`) so mixed PDO values get narrowed once at the boundary
- `Job::is_workflow_step()` now narrows `workflow_id` and `step_index` to non-null inside the true branch via `@phpstan-assert-if-true`
- `HandlerRegistry::resolve()` validates the reflected instance implements the declared interface before returning
- `Queue::stats()` and `ResourceManager::handler_profile()` assemble their declared array shapes explicitly so the return contract is provable
- `ActionWorkflowBridge::callable_arity()` now uses `Closure::fromCallable()` for a uniform reflection path across all callable forms
- `ConfigParser::from_wp_config()` drops dead null-coalescing defaults since the loop either populates all keys or throws

### Fixed

- `Schema::install()` and other `PDO::query()` call sites now guard against `false` return when the statement cannot be prepared
- `MysqliPdoStatement::$row_count` correctly stores `int` from `mysqli_num_rows()` regardless of the underlying driver return type
- `CronExpression::next_run()` handles the `false` return from `preg_split()` instead of crashing on `count()`
- `WorkflowEventLog::record_step_completed()` artifact and chunk parameter types now match what `ExecutionContext::consume_trace()` actually returns

## [0.21.1] - 2026-04-29

### Changed

- state-machine list rows include definition version, definition hash, and idempotency key metadata

## [0.21.0] - 2026-04-29

### Added

- first-class state-machine tracing with action and guard input, output, state before, state after, context, artifacts, chunks, and structured errors
- normalized `Queuety::machine_trace()` API for debugger UIs
- `Queuety::machine_events()` and `Queuety::machine_state_at()` inspection helpers for state-machine traces
- state-machine traces now include correlated action jobs and logs

## [0.20.0] - 2026-04-29

### Added

- first-class workflow tracing with step input, output, state before, state after, context, artifacts, chunks, and structured errors
- normalized `Queuety::workflow_trace()` API for debugger UIs
- runtime trace helpers for handlers through `Queuety::trace_input()`, `Queuety::trace_output()`, and `Queuety::trace_context()`

### Changed

- workflow event rows use explicit trace fields instead of the old `state_snapshot` and `step_output` payload shape
- workflow export and replay preserve the new trace format

## [0.19.0] - 2026-04-26

### Added

- mysqli-backed Queuety database driver for WordPress runtimes without `pdo_mysql`
- automatic DB driver selection with optional `QUEUETY_DB_DRIVER` override

### Changed

- WordPress plugin bootstrap no longer requires `pdo_mysql` when `mysqli` is available

## [0.18.0] - 2026-04-26

### Added

- structured state machine action and guard definitions with serialized payloads

## [0.17.5] - 2026-04-26

### Fixed

- allowed structured repeat condition definitions to satisfy repeat-step validation

## [0.17.4] - 2026-04-26

### Changed

- made structured repeat condition integration coverage deterministic by testing repeat evaluation directly

## [0.17.3] - 2026-04-26

### Changed

- aligned structured repeat condition integration coverage with terminal repeat workflow shape

## [0.17.2] - 2026-04-26

### Changed

- expanded structured repeat condition integration coverage wait time for zero-delay worker claims

## [0.17.1] - 2026-04-26

### Changed

- stabilized structured repeat condition integration coverage for zero-delay worker claims

## [0.17.0] - 2026-04-26

### Added

- structured serialized handler definitions for workflow lifecycle handlers, step compensations, repeat conditions, and for-each reducers
- optional handler payloads for generic workflow adapters

## [0.16.0] - 2026-04-26

### Added

- serialized workflow step runtime metadata for queue, priority, retry, backoff, rate limit, concurrency, cost, delay, and timeout settings
- structured serialized `parallel.branches` definitions with branch payloads and branch-level runtime metadata
- current job payload access through `ExecutionContext::payload()` for adapter steps

### Changed

- direct workflow dispatch, builder dispatch, workflow replay, and for-each branch dispatch now share the same step metadata resolver

## [0.15.0] - 2026-04-26

### Changed

- renamed workflow primitives to the Onumia-aligned canonical vocabulary: `for_each`, `run_workflow`, `start_workflows`, `wait_for_workflows`, `delay`, and `repeat`
- replaced legacy for-each join naming with completion-mode terminology across PHP APIs, internals, tests, and docs
- renamed workflow docs, contracts, enums, fixtures, and integration tests to match the new canonical API names

### Fixed

- corrected the WP-CLI test stub namespace declarations so the full PHP syntax sweep can parse every test file

## [0.14.1] - 2026-04-26

### Added

- per-connection Queuety table prefixes so multiple WordPress plugins can share Queuety code while using isolated table sets

### Fixed

- isolated the default configuration test from constants defined by integration tests

## [0.14.0] - 2026-04-06

### Added

- standalone runtime documentation under `Features -> Standalone Use`
- `Queuety::ensure_schema()` as a public facade helper for standalone initialization
- a local full-suite runner at `bash tests/run-all.sh`
- a dedicated `Docs` job in CI

### Changed

- moved `Standalone Use` to the end of the Features nav
- simplified the local validation entrypoint so the shell runner is the canonical local command
- tracked `docs/package-lock.json` so the docs build uses the same lockfile in CI and local builds

## [0.13.1] - 2026-04-04

### Fixed

- corrected the sliding rate-limit refresh window so facade-driven checks see recent executions correctly

## [0.13.0] - 2026-04-04

### Added

- dynamic workflow for-each with compensation
- richer workflow waits, approvals, input handling, and agent workflow aliases
- durable workflow artifacts and grouped workflow waits
- WordPress action-to-workflow triggers
- repeat workflow primitives
- durable state machines
- resource-aware admission, workflow budgets, and adaptive worker controls
- PHPStan static analysis and stronger E2E coverage

### Changed

- tightened queue claim, workflow inspection, and timeline query paths
- aligned docs toward explanatory prose and expanded Neuron/agent examples
- improved local and CI validation coverage across workflows and plugin runtime behavior

## [0.12.0] - 2026-03-29

### Added

- initial public release
- durable jobs, workflows, and WordPress worker runtime
- dispatchable jobs with middleware, delays, and signals
- batching, chaining, cancellation, heartbeats, and streaming steps
- workflow event log, time travel, forking, and export/replay
- WP-Cron fallback processing and WP-CLI worker control
- documentation site and release packaging
