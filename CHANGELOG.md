# Changelog

All notable changes to Queuety are documented in this file.

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
