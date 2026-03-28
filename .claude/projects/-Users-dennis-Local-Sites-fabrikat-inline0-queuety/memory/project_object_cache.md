---
name: WP Object Cache integration
description: Planned optimization using WP_Object_Cache for hot reads, locks, rate limiting, and heartbeats. Deferred to post-v0.9.0.
type: project
---

WP Object Cache integration considered for Queuety as performance optimization layer.

**Why:** Reduce MySQL queries for hot-path checks (queue paused state, rate limit counters, workflow state reads, heartbeat data). Sites with Redis Object Cache get Redis-speed operations through standard wp_cache_* API.

**How to apply:** Plan this after v0.9.0 (LLM streaming). Three areas: cache layer for hot reads, wp_cache_add() for locks (replace locks table), and heartbeat progress storage.
