<?php
/**
 * In-memory cache backend.
 *
 * @package Queuety
 */

namespace Queuety\Cache;

use Queuety\Contracts\Cache;

/**
 * Array-backed, per-process cache implementation.
 *
 * Items are stored as associative arrays with 'value' and 'expires_at' keys.
 * Suitable for single-process usage where cache does not need to be shared
 * across workers.
 */
class MemoryCache implements Cache {

	/**
	 * Cached items.
	 *
	 * @var array<string, array{value: mixed, expires_at: int|null}>
	 */
	private array $items = array();

	/**
	 * Retrieve a value from the cache.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or null if not found/expired.
	 */
	public function get( string $key ): mixed {
		if ( ! isset( $this->items[ $key ] ) ) {
			return null;
		}

		$item = $this->items[ $key ];

		if ( null !== $item['expires_at'] && $item['expires_at'] <= time() ) {
			unset( $this->items[ $key ] );
			return null;
		}

		return $item['value'];
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 means no expiry.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): void {
		$this->items[ $key ] = array(
			'value'      => $value,
			'expires_at' => $ttl > 0 ? time() + $ttl : null,
		);
	}

	/**
	 * Remove a value from the cache.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		unset( $this->items[ $key ] );
	}

	/**
	 * Check if a key exists and is not expired.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		if ( ! isset( $this->items[ $key ] ) ) {
			return false;
		}

		$item = $this->items[ $key ];

		if ( null !== $item['expires_at'] && $item['expires_at'] <= time() ) {
			unset( $this->items[ $key ] );
			return false;
		}

		return true;
	}

	/**
	 * Set a value only if the key does not already exist (atomic).
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 means no expiry.
	 * @return bool True if the value was set, false if the key already exists.
	 */
	public function add( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( $this->has( $key ) ) {
			return false;
		}

		$this->set( $key, $value, $ttl );
		return true;
	}

	/**
	 * Remove all items from the cache.
	 */
	public function flush(): void {
		$this->items = array();
	}
}
