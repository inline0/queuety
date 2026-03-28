<?php
/**
 * Unit tests for MemoryCache.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Queuety\Cache\MemoryCache;

class MemoryCacheTest extends TestCase {

	private MemoryCache $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new MemoryCache();
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

	public function test_set_and_get_stores_integer(): void {
		$this->cache->set( 'count', 0 );
		$this->assertSame( 0, $this->cache->get( 'count' ) );
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

	public function test_set_without_ttl_never_expires(): void {
		$this->cache->set( 'permanent', 'data', 0 );
		$this->assertSame( 'data', $this->cache->get( 'permanent' ) );
	}

	// -- delete --------------------------------------------------------------

	public function test_delete_removes_key(): void {
		$this->cache->set( 'key', 'value' );
		$this->cache->delete( 'key' );
		$this->assertNull( $this->cache->get( 'key' ) );
	}

	public function test_delete_nonexistent_key_does_not_throw(): void {
		$this->cache->delete( 'nonexistent' );
		$this->assertNull( $this->cache->get( 'nonexistent' ) );
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

	public function test_has_returns_true_for_falsy_value(): void {
		$this->cache->set( 'zero', 0 );
		$this->assertTrue( $this->cache->has( 'zero' ) );

		$this->cache->set( 'false', false );
		$this->assertTrue( $this->cache->has( 'false' ) );

		$this->cache->set( 'empty_string', '' );
		$this->assertTrue( $this->cache->has( 'empty_string' ) );
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

	public function test_add_respects_ttl(): void {
		$this->cache->add( 'ttl_key', 'data', 1 );
		$this->assertSame( 'data', $this->cache->get( 'ttl_key' ) );
		sleep( 2 );
		$this->assertNull( $this->cache->get( 'ttl_key' ) );
	}

	// -- flush ---------------------------------------------------------------

	public function test_flush_clears_everything(): void {
		$this->cache->set( 'key1', 'value1' );
		$this->cache->set( 'key2', 'value2' );
		$this->cache->set( 'key3', 'value3' );

		$this->cache->flush();

		$this->assertNull( $this->cache->get( 'key1' ) );
		$this->assertNull( $this->cache->get( 'key2' ) );
		$this->assertNull( $this->cache->get( 'key3' ) );
		$this->assertFalse( $this->cache->has( 'key1' ) );
	}

	// -- edge cases ----------------------------------------------------------

	public function test_multiple_keys_are_independent(): void {
		$this->cache->set( 'a', 1 );
		$this->cache->set( 'b', 2 );

		$this->cache->delete( 'a' );

		$this->assertNull( $this->cache->get( 'a' ) );
		$this->assertSame( 2, $this->cache->get( 'b' ) );
	}
}
