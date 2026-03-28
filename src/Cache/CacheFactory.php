<?php
/**
 * Cache factory.
 *
 * @package Queuety
 */

namespace Queuety\Cache;

use Queuety\Contracts\Cache;

/**
 * Factory that creates the best available cache backend.
 *
 * Prefers APCu when available (shared across processes), falls back
 * to an in-memory array cache (per-process only).
 */
class CacheFactory {

	/**
	 * Create a cache instance using the best available backend.
	 *
	 * @return Cache
	 */
	public static function create(): Cache {
		if ( function_exists( 'apcu_store' ) && apcu_enabled() ) {
			return new ApcuCache();
		}

		return new MemoryCache();
	}
}
