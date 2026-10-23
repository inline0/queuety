---
title: "Caching"
description: "In-process and shared cache layer for reducing database queries on hot paths."
path: "features/caching"
order: 430
section: "Features"
meta_title: "Caching"
meta_description: "In-process and shared cache layer for reducing database queries on hot paths."
---

# Caching

Queuety includes a cache layer that reduces database queries on hot paths like queue pause checks, rate limit counters, workflow state lookups, and lock operations. The cache is initialized automatically when you call `Queuety::init()`.

## Cache interface

All cache backends implement `Queuety\Contracts\Cache`:

| Method | Returns | Description |
|---|---|---|
| `get( string $key )` | `mixed` | Retrieve a value. Returns `null` if not found or expired. |
| `set( string $key, mixed $value, int $ttl = 0 )` | `void` | Store a value. TTL of 0 means no expiry. |
| `delete( string $key )` | `void` | Remove a value. |
| `has( string $key )` | `bool` | Check if a key exists and is not expired. |
| `add( string $key, mixed $value, int $ttl = 0 )` | `bool` | Set a value only if the key does not already exist (atomic). Returns `true` if the value was set. |
| `flush()` | `void` | Remove entries in this cache namespace. APCu flush preserves other installations and unrelated entries. |

## Built-in backends

### MemoryCache (default)

`Queuety\Cache\MemoryCache` is an array-backed, per-process cache. Items are stored in a PHP array and lost when the process exits. This is always the default backend, including when APCu is installed.

It requires no extensions, works well for single-worker deployments, and keeps each worker process isolated from the others.

### ApcuCache

`Queuety\Cache\ApcuCache` uses the APCu extension for shared memory across all PHP processes on the same server. Its constructor requires an installation namespace, normally `$connection->namespace()`. Keys are scoped by that identity. APCu memory is shared only by processes that actually share the same APCu segment; separately launched CLI processes need not share one.

This backend requires the `php-apcu` extension, shares cache entries across workers on the same server, and makes `add()` truly atomic via `apcu_add()`.

## Choosing a shared cache

`CacheFactory::create()` returns `MemoryCache`. Opt in to APCu before initialization:

```php
Queuety::set_cache( new \Queuety\Cache\ApcuCache( $connection->namespace() ) );
Queuety::init( $connection );
```

Use a different namespace for each independent installation. Claim ownership,
queue pause decisions and workflow transitions are checked against current
SQL state, regardless of cached presentation data.

## What gets cached

The cache layer is used internally by several subsystems:

| Data | Purpose |
|---|---|
| Queue pause state | May be exposed for inspection; claim admission uses current database state |
| Rate limit counters | Tracks execution counts in memory instead of the database |
| Workflow state | Reduces reads during multi-step workflow advancement |
| Lock state | Speeds up lock existence checks for unique jobs and mutex middleware |

## TTL configuration

The default cache TTL is 5 seconds. It applies to the queue pause state and rate-limit counters; workflow status and state reads are cached for a fixed 2 seconds that the constant does not change. Override the default with the `QUEUETY_CACHE_TTL` constant in your `wp-config.php`:

```php
define( 'QUEUETY_CACHE_TTL', 10 ); // 10 seconds
```

A lower TTL means more frequent database queries but fresher data. A higher TTL reduces database load but introduces more staleness. For most deployments, the default of 5 seconds is a good balance.

## Custom cache backend

To use a custom cache backend (Redis, Memcached, or anything else), implement the `Contracts\Cache` interface and call `Queuety::set_cache()` before `Queuety::init()`:

```php
use Queuety\Contracts\Cache;
use Queuety\Queuety;

class RedisCache implements Cache {
    public function get( string $key ): mixed { /* ... */ }
    public function set( string $key, mixed $value, int $ttl = 0 ): void { /* ... */ }
    public function delete( string $key ): void { /* ... */ }
    public function has( string $key ): bool { /* ... */ }
    public function add( string $key, mixed $value, int $ttl = 0 ): bool { /* ... */ }
    public function flush(): void { /* ... */ }
}

// Set the cache before init() so all subsystems use it.
Queuety::set_cache( new RedisCache() );
Queuety::init( $connection );
```

If `set_cache()` is called before `init()`, the default memory cache is replaced and your custom backend is used.

## Accessing the cache

Retrieve the active cache instance at any time:

```php
$cache = Queuety::cache();

$cache->set( 'my_key', 'my_value', 60 );
$value = $cache->get( 'my_key' );
```

This returns whatever backend is currently active (MemoryCache, ApcuCache, or your custom implementation).
