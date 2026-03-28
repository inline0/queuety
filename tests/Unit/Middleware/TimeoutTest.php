<?php
/**
 * Unit tests for Timeout middleware.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Queuety\Middleware\Timeout;

/**
 * Tests for timeout middleware behavior.
 *
 * Note: pcntl_alarm-based tests only run when pcntl is available.
 */
class TimeoutTest extends TestCase {

	public function test_allows_fast_execution(): void {
		$middleware = new Timeout( seconds: 10 );
		$job       = new \stdClass();
		$executed  = false;

		$middleware->handle(
			$job,
			function ( object $job ) use ( &$executed ): void {
				$executed = true;
			}
		);

		$this->assertTrue( $executed );
	}

	public function test_calls_next_with_job(): void {
		$middleware    = new Timeout( seconds: 10 );
		$job          = new \stdClass();
		$received_job = null;

		$middleware->handle(
			$job,
			function ( object $j ) use ( &$received_job ): void {
				$received_job = $j;
			}
		);

		$this->assertSame( $job, $received_job );
	}

	public function test_resets_alarm_after_execution(): void {
		if ( ! function_exists( 'pcntl_alarm' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$middleware = new Timeout( seconds: 30 );
		$job       = new \stdClass();

		$middleware->handle(
			$job,
			function ( object $job ): void {
				// Quick execution.
			}
		);

		// Alarm should be reset to 0 (no pending alarm).
		$remaining = pcntl_alarm( 0 );
		$this->assertSame( 0, $remaining );
	}

	public function test_resets_alarm_on_exception(): void {
		if ( ! function_exists( 'pcntl_alarm' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$middleware = new Timeout( seconds: 30 );
		$job       = new \stdClass();

		try {
			$middleware->handle(
				$job,
				function ( object $job ): void {
					throw new \RuntimeException( 'test error' );
				}
			);
		} catch ( \RuntimeException ) {
			// Expected.
		}

		// Alarm should still be reset despite exception.
		$remaining = pcntl_alarm( 0 );
		$this->assertSame( 0, $remaining );
	}

	public function test_works_without_pcntl(): void {
		// This test verifies the middleware still runs $next even if
		// pcntl is not available. Since pcntl may or may not be available
		// in the test environment, we just verify the callback runs.
		$middleware = new Timeout( seconds: 5 );
		$job       = new \stdClass();
		$executed  = false;

		$middleware->handle(
			$job,
			function ( object $job ) use ( &$executed ): void {
				$executed = true;
			}
		);

		$this->assertTrue( $executed );
	}
}
