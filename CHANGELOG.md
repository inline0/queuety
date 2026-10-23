# Changelog

All notable changes to Queuety are documented in this file.

## [0.23.0] - 2026-10-23

### Added

- opaque per-claim ownership tokens, database-time leases and fixed action deadlines; all worker mutations reject expired or superseded owners
- `Queuety::tick()` for one finite standalone action and `maintenance_batch()` for bounded recovery, schedules, deadlines and retention with a phase cursor
- cooperative execution deadlines, explicit wait/retry-at/terminal-failure exceptions, and `ExecutionContext::on_completion()` for transactional application checkpoints
- durable database-enforced dispatch keys with conflict detection and active-job uniqueness for legacy unique dispatches
- explicit versioned schema installation, draining and compatibility checks; ordinary worker initialization performs no migrations
- optional database connect/read/row-lock/metadata-lock controls, capability reporting, connection discard and an explicit unknown-COMMIT exception
- independent-process lease and resume race tests, transaction failure injection, PHP 8.2 standalone proofs without WordPress or process launching, and MySQL 8.0/MariaDB 10.6 CI coverage
- executable Neuron 4.1.13 examples with deterministic fake providers on PHP 8.2 and current PHP

### Fixed

- stale workers can no longer heartbeat, complete, retry, release, enqueue or publish over a replacement claim, including after waiting for a database row lock
- recovery compares the observed claim and cannot reopen completed or reclaimed jobs
- workflow controllers, child dispatch and parent wakeups, parallel/for-each branches, terminal recovery and state-machine completion preserve job and state transitions in the same transaction
- repeated parallel steps count only their current branches; wait enrollment and child completion cannot lose a parent wakeup
- batch counters commit with job completion or terminal failure before best-effort callbacks and webhook delivery; notification fanout respects the remaining action budget
- pause excludes pending actions from claims and revokes active results; concurrent resume schedules one next action
- streaming chunks are isolated by attempt and retained until completion commits; delayed cleanup preserves newer attempts
- handler artifact publication and application checkpoints roll back with workflow state, completion and next enqueue
- embedded caches use installation namespaces, APCu flush preserves unrelated entries, and MemoryCache is always the default
- standalone worker pools retain explicit transport options without WordPress constants; short-lived guarded connections close without waiting for cyclic garbage collection
- legacy migration preserves logs and event data, and MySQL dispatch availability retains fractional timestamps without rounding immediate jobs into the future
- local static-analysis script uses the canonical Composer command; database test probes support explicit host ports and WordPress E2E setup verifies actual installation

### Upgrade notes

- **Drain and stop old workers before upgrading.** Pre-0.23 processing rows must finish or be recovered with the old release. Then run `Schema::install($connection)` explicitly, initialize the matching runtime and restart workers. See [Standalone Use](docs/features/standalone-use.md#upgrading-an-existing-installation).
- Retired pre-terminology unfinished workflow definitions require completion with their matching old release or an explicit definition migration; incompatible active data is preserved and refused, not silently reinterpreted.
- Direct queue integrations must retain the returned Job and supply its original `claim_token` to worker mutations. Administrative facade operations use revision comparisons and invalidate old claims.
- Pausing a workflow now rejects in-flight results; resume reclaims the current step. Handlers must use external idempotency or reconciliation for effects that SQL cannot roll back.
- APCu caching now requires an explicit namespace. Independent embedded runtimes must have separate schema/table/cache identities and compatible production autoloaders.
- Composer now declares the existing PDO base extension requirement, including when using the mysqli adapter; `pdo_mysql` remains optional.
- Database timeouts and action budgets are cooperative. A hard cutoff requires an external watchdog. Failed COMMIT acknowledgements require durable-state reconciliation with a fresh connection before replay.
- Saved stream chunks do not prove a model response completed or make a provider stream resumable. Neuron examples now use provider-level requests with Queuety as the durable orchestration owner.

## [0.22.1] - 2026-10-14

### Fixed

- dispatch-level rate limits persist with jobs and survive fresh worker processes
- single workers and worker pools finish active jobs before shutting down on SIGTERM or SIGINT
- standalone worker pools inherit initialized connection settings, driver overrides, handler mappings, and workflow templates while reopening database connections after fork
- unsupported MySQL and MariaDB versions fail before schema installation or concurrent claims, and missing Composer autoloaders produce the correct installation notice
- bridged WordPress cron schedules execute the original hook callbacks with their arguments
- worker and middleware timeouts enable asynchronous signal dispatch, preserve earlier nested deadlines, and restore the caller's signal state
- `wp queuety log purge --older-than` and `wp queuety workflow list --limit` accept their documented arguments
- `Dispatchable::with_chain()` prepends the calling job and accepts its constructor arguments
- worker startup, one-shot runs, flushes, and pool maintenance process due schedules, stale jobs, workflow deadlines, and configured job and log retention
- completed-job retention preserves dependencies required by unfinished chains
- handler retry delay lists retain their configured order and control actual retry availability
- workflow replay resumes repeat and child-workflow controller steps

### Changed

- WordPress cron fallback drains the default queue within a budget of 20 seconds, checked between jobs
- removed the unused standalone bootstrap; standalone applications initialize Queuety through their Composer autoloader
- Composer test scripts disable the process timeout so the complete database suite can finish

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
