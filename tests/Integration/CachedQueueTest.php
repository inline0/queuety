<?php
/**
 * Integration tests for cache-backed Queue operations.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Cache\MemoryCache;
use Queuety\Config;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class CachedQueueTest extends IntegrationTestCase {

	private Queue $queue;
	private MemoryCache $cache;
	private Worker $worker;
	private HandlerRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->cache    = new MemoryCache();
		$this->queue    = new Queue( $this->conn, $this->cache );
		$logger         = new Logger( $this->conn );
		$workflow       = new Workflow( $this->conn, $this->queue, $logger, $this->cache );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$logger,
			$workflow,
			$this->registry,
			new Config(),
		);

		SuccessHandler::reset();
	}

	// -- is_queue_paused uses cache on second call ---------------------------

	public function test_is_queue_paused_uses_cache_on_second_call(): void {
		// First call: no cache, hits DB, returns false, caches the result.
		$this->assertFalse( $this->queue->is_queue_paused( 'default' ) );

		// The value should now be in cache.
		$this->assertTrue( $this->cache->has( 'queuety:paused:default' ) );

		// Second call: should read from cache (value is false/not paused).
		$this->assertFalse( $this->queue->is_queue_paused( 'default' ) );
	}

	// -- pause_queue sets cache ----------------------------------------------

	public function test_pause_queue_sets_cache(): void {
		$this->queue->pause_queue( 'default' );

		// Cache should reflect the paused state.
		$this->assertTrue( $this->cache->has( 'queuety:paused:default' ) );
		$this->assertTrue( (bool) $this->cache->get( 'queuety:paused:default' ) );

		// is_queue_paused should use the cache.
		$this->assertTrue( $this->queue->is_queue_paused( 'default' ) );
	}

	// -- resume_queue invalidates cache --------------------------------------

	public function test_resume_queue_invalidates_cache(): void {
		$this->queue->pause_queue( 'default' );
		$this->assertTrue( $this->queue->is_queue_paused( 'default' ) );

		$this->queue->resume_queue( 'default' );

		// Cache entry should be gone after resume.
		$this->assertFalse( $this->cache->has( 'queuety:paused:default' ) );

		// DB confirms not paused.
		$this->assertFalse( $this->queue->is_queue_paused( 'default' ) );
	}

	// -- cache miss falls through to DB --------------------------------------

	public function test_cache_miss_falls_through_to_db(): void {
		// Pause via DB directly (bypassing the Queue class cache logic).
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (queue, paused, paused_at)
			VALUES (:queue, 1, NOW())
			ON DUPLICATE KEY UPDATE paused = 1, paused_at = NOW()"
		);
		$stmt->execute( array( 'queue' => 'emails' ) );

		// Cache is empty, so is_queue_paused should hit DB and find it paused.
		$this->assertTrue( $this->queue->is_queue_paused( 'emails' ) );

		// Now cached.
		$this->assertTrue( $this->cache->has( 'queuety:paused:emails' ) );
	}

	// -- worker still works correctly with cache enabled ---------------------

	public function test_worker_flush_works_with_cache(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'data' => 1 ) );

		$count = $this->worker->flush();

		$this->assertSame( 1, $count );
		$this->assertCount( 1, SuccessHandler::$processed );
	}

	public function test_worker_skips_paused_queue_with_cache(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'data' => 1 ) );

		$this->queue->pause_queue( 'default' );

		$count = $this->worker->flush();

		$this->assertSame( 0, $count );
		$this->assertCount( 0, SuccessHandler::$processed );
	}

	public function test_worker_resumes_after_unpause_with_cache(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'data' => 1 ) );

		$this->queue->pause_queue( 'default' );
		$this->assertSame( 0, $this->worker->flush() );

		$this->queue->resume_queue( 'default' );
		$count = $this->worker->flush();

		$this->assertSame( 1, $count );
		$this->assertCount( 1, SuccessHandler::$processed );
	}

	// -- queue works without cache (null) ------------------------------------

	public function test_queue_works_without_cache(): void {
		$queue_no_cache = new Queue( $this->conn );

		$queue_no_cache->pause_queue( 'test_queue' );
		$this->assertTrue( $queue_no_cache->is_queue_paused( 'test_queue' ) );

		$queue_no_cache->resume_queue( 'test_queue' );
		$this->assertFalse( $queue_no_cache->is_queue_paused( 'test_queue' ) );
	}
}
