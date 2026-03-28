<?php
/**
 * APCu cache backend.
 *
 * @package Queuety
 */

namespace Queuety\Cache;

use Queuety\Contracts\Cache;

/**
 * APCu-backed cache implementation, shared across processes on the same server.
 *
 * All keys are prefixed with 'queuety:' to avoid collisions with other
 * applications using APCu on the same server.
 */
class ApcuCache implements Cache {

	/**
	 * Key prefix to namespace all cache entries.
	 *
	 * @var string
	 */
	private const PREFIX = 'queuety:';

	/**
	 * Constructor.
	 *
	 * @throws \RuntimeException If the APCu extension is not available.
	 */
	public function __construct() {
		if ( ! function_exists( 'apcu_store' ) ) {
			throw new \RuntimeException( 'APCu extension is not available. Install php-apcu to use ApcuCache.' );
		}
	}

	/**
	 * Retrieve a value from the cache.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or null if not found/expired.
	 */
	public function get( string $key ): mixed {
		$success = false;
		$value   = apcu_fetch( self::PREFIX . $key, $success );

		if ( ! $success ) {
			return null;
		}

		return $value;
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 means no expiry.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): void {
		apcu_store( self::PREFIX . $key, $value, $ttl );
	}

	/**
	 * Remove a value from the cache.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		apcu_delete( self::PREFIX . $key );
	}

	/**
	 * Check if a key exists and is not expired.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return apcu_exists( self::PREFIX . $key );
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
		return apcu_add( self::PREFIX . $key, $value, $ttl );
	}

	/**
	 * Remove all items from the cache.
	 */
	public function flush(): void {
		apcu_clear_cache();
	}
}
