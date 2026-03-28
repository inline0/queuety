<?php
/**
 * Unit tests for CacheFactory.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Queuety\Cache\ApcuCache;
use Queuety\Cache\CacheFactory;
use Queuety\Cache\MemoryCache;
use Queuety\Contracts\Cache;

class CacheFactoryTest extends TestCase {

	public function test_create_returns_cache_instance(): void {
		$cache = CacheFactory::create();
		$this->assertInstanceOf( Cache::class, $cache );
	}

	public function test_create_returns_memory_cache_when_apcu_not_available(): void {
		if ( function_exists( 'apcu_store' ) && apcu_enabled() ) {
			$this->markTestSkipped( 'APCu is available; cannot test MemoryCache fallback.' );
		}

		$cache = CacheFactory::create();
		$this->assertInstanceOf( MemoryCache::class, $cache );
	}

	public function test_create_returns_apcu_cache_when_apcu_available(): void {
		if ( ! function_exists( 'apcu_store' ) || ! apcu_enabled() ) {
			$this->markTestSkipped( 'APCu is not available.' );
		}

		$cache = CacheFactory::create();
		$this->assertInstanceOf( ApcuCache::class, $cache );
	}

	public function test_created_cache_implements_full_interface(): void {
		$cache = CacheFactory::create();

		// Verify all interface methods exist and are callable.
		$cache->set( 'factory_test', 'value', 60 );
		$this->assertSame( 'value', $cache->get( 'factory_test' ) );
		$this->assertTrue( $cache->has( 'factory_test' ) );

		$cache->delete( 'factory_test' );
		$this->assertNull( $cache->get( 'factory_test' ) );

		$this->assertTrue( $cache->add( 'factory_add', 'data', 60 ) );
		$this->assertFalse( $cache->add( 'factory_add', 'other', 60 ) );

		$cache->flush();
		$this->assertNull( $cache->get( 'factory_add' ) );
	}
}
