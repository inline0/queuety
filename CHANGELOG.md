# Changelog

All notable changes to Queuety are documented in this file.

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
