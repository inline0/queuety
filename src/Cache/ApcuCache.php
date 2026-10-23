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
 * Each instance requires the installation namespace it may read or clear.
 */
class ApcuCache implements Cache {

	/**
	 * Key prefix to namespace all cache entries.
	 *
	 * @var string
	 */
	private readonly string $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $namespace Stable installation identity.
	 * @throws \RuntimeException If the APCu extension is not available.
	 * @throws \InvalidArgumentException If the namespace is empty.
	 */
	public function __construct( string $namespace ) {
		if ( '' === trim( $namespace ) ) {
			throw new \InvalidArgumentException( 'An installation namespace is required for APCu.' );
		}
		if ( ! function_exists( 'apcu_store' ) || ! apcu_enabled() ) {
			throw new \RuntimeException( 'APCu extension is not available. Install php-apcu to use ApcuCache.' );
		}
		$this->prefix = 'queuety:' . hash( 'sha256', $namespace ) . ':';
	}

	/**
	 * Retrieve a value from the cache.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or null if not found/expired.
	 */
	public function get( string $key ): mixed {
		$success = false;
		$value   = apcu_fetch( $this->prefix . $key, $success );

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
		apcu_store( $this->prefix . $key, $value, $ttl );
	}

	/**
	 * Remove a value from the cache.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		apcu_delete( $this->prefix . $key );
	}

	/**
	 * Check if a key exists and is not expired.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return apcu_exists( $this->prefix . $key );
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
		return apcu_add( $this->prefix . $key, $value, $ttl );
	}

	/**
	 * Remove this installation's items without clearing unrelated APCu entries.
	 */
	public function flush(): void {
		apcu_delete( new \APCUIterator( '/^' . preg_quote( $this->prefix, '/' ) . '/' ) );
	}
}
