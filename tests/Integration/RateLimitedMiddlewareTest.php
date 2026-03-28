<?php
/**
 * Unit tests for RateLimited middleware.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\Exceptions\RateLimitExceededException;
use Queuety\Middleware\RateLimited;
use Queuety\Queuety;
use Queuety\RateLimiter;

/**
 * Tests for rate limit middleware behavior.
 *
 * These tests mock the Queuety facade by initializing it with a real connection
 * or skip if no database is available.
 */
class RateLimitedMiddlewareTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->skip_if_no_database();

		$conn = new Connection(
			host: QUEUETY_TEST_DB_HOST,
			dbname: QUEUETY_TEST_DB_NAME,
			user: QUEUETY_TEST_DB_USER,
			password: QUEUETY_TEST_DB_PASS,
			prefix: QUEUETY_TEST_DB_PREFIX,
		);

		\Queuety\Schema::install( $conn );
		Queuety::init( $conn );
	}

	protected function tearDown(): void {
		try {
			$conn = Queuety::connection();
			\Queuety\Schema::uninstall( $conn );
		} catch ( \RuntimeException ) {
			// Facade not initialized, nothing to clean up.
		}
		Queuety::reset();
		parent::tearDown();
	}

	public function test_allows_execution_within_limit(): void {
		$middleware = new RateLimited( max: 5, window: 60 );
		$job       = new \stdClass();
		$executed   = false;

		$middleware->handle(
			$job,
			function ( object $job ) use ( &$executed ): void {
				$executed = true;
			}
		);

		$this->assertTrue( $executed );
	}

	public function test_throws_when_rate_limit_exceeded(): void {
		$job_class = 'stdClass';
		$limiter   = Queuety::rate_limiter();

		// Pre-register and exhaust the limit.
		$limiter->register( $job_class, 1, 60 );
		$limiter->record( $job_class );

		$middleware = new RateLimited( max: 1, window: 60 );
		$job       = new \stdClass();

		$this->expectException( RateLimitExceededException::class );

		$middleware->handle(
			$job,
			function ( object $job ): void {}
		);
	}

	public function test_records_execution_after_success(): void {
		$middleware = new RateLimited( max: 2, window: 60 );
		$job       = new RateLimitedTest_SampleJob();
		$handler_key = get_class( $job );

		$middleware->handle(
			$job,
			function ( object $job ): void {}
		);

		// After one execution, one should be recorded.
		$limiter = Queuety::rate_limiter();
		$this->assertTrue( $limiter->is_registered( $handler_key ) );
	}

	private function skip_if_no_database(): void {
		try {
			$dsn = sprintf(
				'mysql:host=%s;dbname=%s;charset=utf8mb4',
				QUEUETY_TEST_DB_HOST,
				QUEUETY_TEST_DB_NAME
			);
			new \PDO( $dsn, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS );
		} catch ( \PDOException $e ) {
			$this->markTestSkipped( 'MySQL is not available: ' . $e->getMessage() );
		}
	}
}

class RateLimitedTest_SampleJob {
	// Simple object for testing.
}
