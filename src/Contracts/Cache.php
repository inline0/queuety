<?php
/**
 * Cache contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Interface for cache backends used to reduce database queries on hot paths.
 */
interface Cache {

	/**
	 * Retrieve a value from the cache.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or null if not found/expired.
	 */
	public function get( string $key ): mixed;

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 means no expiry.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): void;

	/**
	 * Remove a value from the cache.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void;

	/**
	 * Check if a key exists and is not expired.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( string $key ): bool;

	/**
	 * Set a value only if the key does not already exist (atomic).
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 means no expiry.
	 * @return bool True if the value was set, false if the key already exists.
	 */
	public function add( string $key, mixed $value, int $ttl = 0 ): bool;

	/**
	 * Remove all items from the cache.
	 */
	public function flush(): void;
}
