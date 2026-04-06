<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Queuety;

class QueuetyFacadeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Queuety::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_dispatch_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized. Call Queuety::init() first.' );

		Queuety::dispatch( 'handler' );
	}

	public function test_workflow_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::workflow( 'my_workflow' );
	}

	public function test_register_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::register( 'name', 'class' );
	}

	public function test_stats_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::stats();
	}

	public function test_buried_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::buried();
	}

	public function test_retry_buried_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::retry_buried();
	}

	public function test_retry_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::retry( 1 );
	}

	public function test_purge_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::purge();
	}

	public function test_workflow_status_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::workflow_status( 1 );
	}

	public function test_retry_workflow_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::retry_workflow( 1 );
	}

	public function test_pause_workflow_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::pause_workflow( 1 );
	}

	public function test_resume_workflow_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::resume_workflow( 1 );
	}

	public function test_queue_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::queue();
	}

	public function test_ensure_schema_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::ensure_schema();
	}

	public function test_logger_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::logger();
	}

	public function test_worker_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::worker();
	}

	public function test_workflow_manager_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::workflow_manager();
	}

	public function test_registry_throws_before_init(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Queuety not initialized' );

		Queuety::registry();
	}

	public function test_reset_clears_state(): void {
		// After reset, all methods should throw again.
		Queuety::reset();

		$threw = false;
		try {
			Queuety::dispatch( 'handler' );
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'dispatch() should throw after reset()' );
	}

	public function test_reset_can_be_called_multiple_times(): void {
		Queuety::reset();
		Queuety::reset();
		Queuety::reset();

		$this->expectException( \RuntimeException::class );
		Queuety::queue();
	}

	public function test_reset_followed_by_method_throws(): void {
		// Ensure each accessor method throws after reset.
		$methods = array(
			fn() => Queuety::dispatch( 'h' ),
			fn() => Queuety::workflow( 'w' ),
			fn() => Queuety::register( 'n', 'c' ),
			fn() => Queuety::stats(),
			fn() => Queuety::buried(),
			fn() => Queuety::retry_buried(),
			fn() => Queuety::retry( 1 ),
			fn() => Queuety::purge(),
			fn() => Queuety::workflow_status( 1 ),
			fn() => Queuety::retry_workflow( 1 ),
			fn() => Queuety::pause_workflow( 1 ),
			fn() => Queuety::resume_workflow( 1 ),
			fn() => Queuety::ensure_schema(),
			fn() => Queuety::queue(),
			fn() => Queuety::logger(),
			fn() => Queuety::worker(),
			fn() => Queuety::workflow_manager(),
			fn() => Queuety::registry(),
		);

		$all_threw = true;
		foreach ( $methods as $method ) {
			try {
				$method();
				$all_threw = false;
				break;
			} catch ( \RuntimeException ) {
				// Expected.
			}
		}

		$this->assertTrue( $all_threw, 'All facade methods should throw RuntimeException after reset()' );
	}
}
