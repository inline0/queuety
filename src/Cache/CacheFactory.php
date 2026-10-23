<?php
/**
 * Cache factory.
 *
 * @package Queuety
 */

namespace Queuety\Cache;

use Queuety\Contracts\Cache;

/**
 * Creates isolated local caches or explicitly scoped shared caches.
 */
class CacheFactory {

	/**
	 * Create a local cache, or opt into APCu with an installation namespace.
	 *
	 * @param string|null $namespace Stable installation identity for shared caching.
	 * @return Cache
	 */
	public static function create( ?string $namespace = null ): Cache {
		if ( null !== $namespace && function_exists( 'apcu_store' ) && apcu_enabled() ) {
			return new ApcuCache( $namespace );
		}

		return new MemoryCache();
	}
}
