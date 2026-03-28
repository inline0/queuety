<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\Priority;
use Queuety\Queue;

class BatchDispatchTest extends TestCase {

	public function test_batch_with_empty_array_returns_empty(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'batch' )
			->with( $this->identicalTo( array() ) )
			->willReturn( array() );

		$result = $queue->batch( array() );

		$this->assertSame( array(), $result );
	}

	public function test_batch_returns_array_of_ids(): void {
		$jobs = array(
			array( 'handler' => 'HandlerA', 'payload' => array( 'a' => 1 ) ),
			array( 'handler' => 'HandlerB', 'payload' => array( 'b' => 2 ) ),
			array( 'handler' => 'HandlerC' ),
		);

		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'batch' )
			->with( $this->identicalTo( $jobs ) )
			->willReturn( array( 10, 11, 12 ) );

		$result = $queue->batch( $jobs );

		$this->assertCount( 3, $result );
		$this->assertSame( array( 10, 11, 12 ), $result );
	}

	public function test_batch_job_definitions_support_all_keys(): void {
		$jobs = array(
			array(
				'handler'      => 'MyHandler',
				'payload'      => array( 'key' => 'val' ),
				'queue'        => 'emails',
				'priority'     => Priority::High,
				'delay'        => 60,
				'max_attempts' => 5,
			),
		);

		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'batch' )
			->with( $this->callback( function ( array $arg ) {
				return $arg[0]['handler'] === 'MyHandler'
					&& $arg[0]['queue'] === 'emails'
					&& $arg[0]['priority'] === Priority::High
					&& $arg[0]['delay'] === 60
					&& $arg[0]['max_attempts'] === 5;
			} ) )
			->willReturn( array( 1 ) );

		$result = $queue->batch( $jobs );

		$this->assertSame( array( 1 ), $result );
	}

	public function test_batch_defaults_are_applied_for_missing_keys(): void {
		// This tests the expectation that handler is the only required key.
		// Missing keys get defaults: payload=[], queue='default', priority=Low, delay=0, max_attempts=3.
		$jobs = array(
			array( 'handler' => 'MinimalHandler' ),
		);

		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'batch' )
			->with( $this->callback( function ( array $arg ) {
				return $arg[0]['handler'] === 'MinimalHandler'
					&& ! isset( $arg[0]['payload'] );
			} ) )
			->willReturn( array( 42 ) );

		$result = $queue->batch( $jobs );

		$this->assertSame( array( 42 ), $result );
	}
}
