<?php
/**
 * Unit tests for MiddlewarePipeline.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Contracts\Middleware;
use Queuety\MiddlewarePipeline;

/**
 * Tests that the pipeline executes middleware in correct order and handles exceptions.
 */
class MiddlewarePipelineTest extends TestCase {

	public function test_runs_core_without_middleware(): void {
		$pipeline = new MiddlewarePipeline();
		$executed = false;
		$job      = new \stdClass();

		$pipeline->run(
			$job,
			array(),
			function ( object $job ) use ( &$executed ): void {
				$executed = true;
			}
		);

		$this->assertTrue( $executed );
	}

	public function test_middleware_executes_in_order(): void {
		$pipeline = new MiddlewarePipeline();
		$log      = array();
		$job      = new \stdClass();

		$mw1 = new MiddlewarePipelineTest_LogMiddleware( 'first', $log );
		$mw2 = new MiddlewarePipelineTest_LogMiddleware( 'second', $log );

		$pipeline->run(
			$job,
			array( $mw1, $mw2 ),
			function ( object $job ) use ( &$log ): void {
				$log[] = 'core';
			}
		);

		$this->assertSame( array( 'first:before', 'second:before', 'core', 'second:after', 'first:after' ), $log );
	}

	public function test_middleware_can_short_circuit(): void {
		$pipeline     = new MiddlewarePipeline();
		$core_called  = false;
		$job          = new \stdClass();

		$blocking = new MiddlewarePipelineTest_BlockingMiddleware();

		$pipeline->run(
			$job,
			array( $blocking ),
			function ( object $job ) use ( &$core_called ): void {
				$core_called = true;
			}
		);

		$this->assertFalse( $core_called );
	}

	public function test_exception_propagates_through_pipeline(): void {
		$pipeline = new MiddlewarePipeline();
		$job      = new \stdClass();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );

		$pipeline->run(
			$job,
			array(),
			function ( object $job ): void {
				throw new \RuntimeException( 'boom' );
			}
		);
	}

	public function test_exception_in_middleware_propagates(): void {
		$pipeline = new MiddlewarePipeline();
		$job      = new \stdClass();

		$throwing = new MiddlewarePipelineTest_ThrowingMiddleware();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'middleware error' );

		$pipeline->run(
			$job,
			array( $throwing ),
			function ( object $job ): void {}
		);
	}

	public function test_single_middleware_wraps_core(): void {
		$pipeline = new MiddlewarePipeline();
		$log      = array();
		$job      = new \stdClass();

		$mw = new MiddlewarePipelineTest_LogMiddleware( 'only', $log );

		$pipeline->run(
			$job,
			array( $mw ),
			function ( object $job ) use ( &$log ): void {
				$log[] = 'core';
			}
		);

		$this->assertSame( array( 'only:before', 'core', 'only:after' ), $log );
	}

	public function test_three_middleware_onion_order(): void {
		$pipeline = new MiddlewarePipeline();
		$log      = array();
		$job      = new \stdClass();

		$mw1 = new MiddlewarePipelineTest_LogMiddleware( 'a', $log );
		$mw2 = new MiddlewarePipelineTest_LogMiddleware( 'b', $log );
		$mw3 = new MiddlewarePipelineTest_LogMiddleware( 'c', $log );

		$pipeline->run(
			$job,
			array( $mw1, $mw2, $mw3 ),
			function ( object $job ) use ( &$log ): void {
				$log[] = 'core';
			}
		);

		$this->assertSame(
			array( 'a:before', 'b:before', 'c:before', 'core', 'c:after', 'b:after', 'a:after' ),
			$log
		);
	}
}

// -- Test fixture middleware (inline) ---------------------------------------

class MiddlewarePipelineTest_LogMiddleware implements Middleware {

	public function __construct(
		private readonly string $name,
		private array &$log,
	) {}

	public function handle( object $job, \Closure $next ): void {
		$this->log[] = "{$this->name}:before";
		$next( $job );
		$this->log[] = "{$this->name}:after";
	}
}

class MiddlewarePipelineTest_BlockingMiddleware implements Middleware {

	public function handle( object $job, \Closure $next ): void {
		// Deliberately do not call $next().
	}
}

class MiddlewarePipelineTest_ThrowingMiddleware implements Middleware {

	public function handle( object $job, \Closure $next ): void {
		throw new \RuntimeException( 'middleware error' );
	}
}
