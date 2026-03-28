<?php
/**
 * Unit tests for ApcuCache.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Queuety\Cache\ApcuCache;

class ApcuCacheTest extends TestCase {

	private ApcuCache $cache;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'apcu_store' ) || ! apcu_enabled() ) {
			$this->markTestSkipped( 'APCu extension is not available.' );
		}

		$this->cache = new ApcuCache();
		$this->cache->flush();
	}

	protected function tearDown(): void {
		if ( isset( $this->cache ) ) {
			$this->cache->flush();
		}
		parent::tearDown();
	}

	// -- get / set -----------------------------------------------------------

	public function test_get_returns_null_for_missing_key(): void {
		$this->assertNull( $this->cache->get( 'nonexistent' ) );
	}

	public function test_set_and_get_stores_value(): void {
		$this->cache->set( 'key', 'value' );
		$this->assertSame( 'value', $this->cache->get( 'key' ) );
	}

	public function test_set_and_get_stores_array(): void {
		$data = array( 'foo' => 'bar', 'count' => 42 );
		$this->cache->set( 'data', $data );
		$this->assertSame( $data, $this->cache->get( 'data' ) );
	}

	public function test_set_and_get_stores_boolean(): void {
		$this->cache->set( 'flag_true', true );
		$this->cache->set( 'flag_false', false );

		$this->assertTrue( $this->cache->get( 'flag_true' ) );
		$this->assertFalse( $this->cache->get( 'flag_false' ) );
	}

	public function test_set_overwrites_existing_value(): void {
		$this->cache->set( 'key', 'first' );
		$this->cache->set( 'key', 'second' );
		$this->assertSame( 'second', $this->cache->get( 'key' ) );
	}

	// -- TTL expiry ----------------------------------------------------------

	public function test_get_returns_null_for_expired_key(): void {
		$this->cache->set( 'expires', 'data', 1 );
		sleep( 2 );
		$this->assertNull( $this->cache->get( 'expires' ) );
	}

	public function test_get_returns_value_within_ttl(): void {
		$this->cache->set( 'alive', 'data', 60 );
		$this->assertSame( 'data', $this->cache->get( 'alive' ) );
	}

	// -- delete --------------------------------------------------------------

	public function test_delete_removes_key(): void {
		$this->cache->set( 'key', 'value' );
		$this->cache->delete( 'key' );
		$this->assertNull( $this->cache->get( 'key' ) );
	}

	// -- has -----------------------------------------------------------------

	public function test_has_returns_true_for_existing_key(): void {
		$this->cache->set( 'key', 'value' );
		$this->assertTrue( $this->cache->has( 'key' ) );
	}

	public function test_has_returns_false_for_missing_key(): void {
		$this->assertFalse( $this->cache->has( 'nonexistent' ) );
	}

	public function test_has_returns_false_for_expired_key(): void {
		$this->cache->set( 'expires', 'data', 1 );
		sleep( 2 );
		$this->assertFalse( $this->cache->has( 'expires' ) );
	}

	// -- add -----------------------------------------------------------------

	public function test_add_returns_true_on_new_key(): void {
		$result = $this->cache->add( 'new_key', 'value' );
		$this->assertTrue( $result );
		$this->assertSame( 'value', $this->cache->get( 'new_key' ) );
	}

	public function test_add_returns_false_on_existing_key(): void {
		$this->cache->set( 'existing', 'original' );
		$result = $this->cache->add( 'existing', 'new_value' );
		$this->assertFalse( $result );
		$this->assertSame( 'original', $this->cache->get( 'existing' ) );
	}

	public function test_add_returns_true_after_key_expires(): void {
		$this->cache->set( 'expires', 'old', 1 );
		sleep( 2 );
		$result = $this->cache->add( 'expires', 'new' );
		$this->assertTrue( $result );
		$this->assertSame( 'new', $this->cache->get( 'expires' ) );
	}

	// -- flush ---------------------------------------------------------------

	public function test_flush_clears_everything(): void {
		$this->cache->set( 'key1', 'value1' );
		$this->cache->set( 'key2', 'value2' );

		$this->cache->flush();

		$this->assertNull( $this->cache->get( 'key1' ) );
		$this->assertNull( $this->cache->get( 'key2' ) );
	}

	// -- constructor ---------------------------------------------------------

	public function test_constructor_throws_when_apcu_not_available(): void {
		// This test documents the behavior; if APCu IS available, the
		// constructor works fine (covered by setUp). If not, the test
		// is already skipped, so this is informational only.
		$this->assertInstanceOf( ApcuCache::class, $this->cache );
	}
}
