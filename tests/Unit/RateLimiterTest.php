<?php
/**
 * Unit tests for RateLimiter.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\RateLimiter;

class RateLimiterTest extends TestCase {

	/**
	 * Create a RateLimiter with a mocked Connection that returns a given count from DB.
	 *
	 * @param int $db_count The count the DB will return on refresh.
	 * @return RateLimiter
	 */
	private function make_limiter( int $db_count = 0 ): RateLimiter {
		$stmt = $this->createStub( \PDOStatement::class );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( array( 'cnt' => $db_count ) );

		$pdo = $this->createStub( \PDO::class );
		$pdo->method( 'prepare' )->willReturn( $stmt );

		$conn = $this->createStub( Connection::class );
		$conn->method( 'pdo' )->willReturn( $pdo );
		$conn->method( 'table' )->willReturn( 'wp_queuety_logs' );

		return new RateLimiter( $conn );
	}

	/**
	 * Force an initial DB refresh for a handler by calling is_limited().
	 *
	 * This must be called after register() and before record() to ensure
	 * the in-memory counter is synchronized with the DB mock.
	 *
	 * @param RateLimiter $limiter The limiter instance.
	 * @param string      $handler Handler name.
	 */
	private function prime( RateLimiter $limiter, string $handler ): void {
		$limiter->is_limited( $handler );
	}

	// -- register and is_limited when under limit ----------------------------

	public function test_is_limited_returns_false_when_under_limit(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 5, 60 );

		$this->assertFalse( $limiter->is_limited( 'handler_a' ) );
	}

	// -- is_limited returns true when at limit --------------------------------

	public function test_is_limited_returns_true_when_at_limit(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 2, 60 );

		// Prime to trigger initial DB refresh.
		$this->prime( $limiter, 'handler_a' );

		$limiter->record( 'handler_a' );
		$limiter->record( 'handler_a' );

		$this->assertTrue( $limiter->is_limited( 'handler_a' ) );
	}

	// -- record increments counter -------------------------------------------

	public function test_record_increments_counter(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 3, 60 );

		// First is_limited call primes the DB refresh.
		$this->assertFalse( $limiter->is_limited( 'handler_a' ) );

		$limiter->record( 'handler_a' );
		$this->assertFalse( $limiter->is_limited( 'handler_a' ) );

		$limiter->record( 'handler_a' );
		$this->assertFalse( $limiter->is_limited( 'handler_a' ) );

		$limiter->record( 'handler_a' );
		$this->assertTrue( $limiter->is_limited( 'handler_a' ) );
	}

	// -- window expiry resets counter ----------------------------------------

	public function test_window_expiry_resets_counter(): void {
		$limiter = $this->make_limiter();
		// Register with a 1-second window so it expires quickly.
		$limiter->register( 'handler_a', 1, 1 );

		$this->prime( $limiter, 'handler_a' );
		$limiter->record( 'handler_a' );
		$this->assertTrue( $limiter->is_limited( 'handler_a' ) );

		// Wait for window to expire.
		sleep( 2 );

		// After expiry, window resets and DB refresh returns 0.
		$this->assertFalse( $limiter->is_limited( 'handler_a' ) );
	}

	// -- time_until_available calculation ------------------------------------

	public function test_time_until_available_returns_remaining_window(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 1, 60 );

		$remaining = $limiter->time_until_available( 'handler_a' );
		$this->assertGreaterThan( 0, $remaining );
		$this->assertLessThanOrEqual( 60, $remaining );
	}

	public function test_time_until_available_returns_zero_for_expired_window(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 1, 1 );

		sleep( 2 );

		$this->assertSame( 0, $limiter->time_until_available( 'handler_a' ) );
	}

	// -- unregistered handler is never limited --------------------------------

	public function test_unregistered_handler_is_never_limited(): void {
		$limiter = $this->make_limiter();

		$this->assertFalse( $limiter->is_limited( 'not_registered' ) );
	}

	public function test_time_until_available_returns_zero_for_unregistered(): void {
		$limiter = $this->make_limiter();

		$this->assertSame( 0, $limiter->time_until_available( 'not_registered' ) );
	}

	public function test_record_is_noop_for_unregistered_handler(): void {
		$limiter = $this->make_limiter();

		// Should not throw.
		$limiter->record( 'not_registered' );
		$this->assertFalse( $limiter->is_limited( 'not_registered' ) );
	}

	// -- multiple handlers tracked independently ------------------------------

	public function test_multiple_handlers_tracked_independently(): void {
		$limiter = $this->make_limiter();
		$limiter->register( 'handler_a', 1, 60 );
		$limiter->register( 'handler_b', 2, 60 );

		// Prime both handlers to trigger initial DB refresh.
		$this->prime( $limiter, 'handler_a' );
		$this->prime( $limiter, 'handler_b' );

		$limiter->record( 'handler_a' );

		$this->assertTrue( $limiter->is_limited( 'handler_a' ) );
		$this->assertFalse( $limiter->is_limited( 'handler_b' ) );

		$limiter->record( 'handler_b' );
		$this->assertFalse( $limiter->is_limited( 'handler_b' ) );

		$limiter->record( 'handler_b' );
		$this->assertTrue( $limiter->is_limited( 'handler_b' ) );
	}
}
