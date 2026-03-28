<?php
/**
 * Unit tests for ChainBuilder.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\ChainBuilder;
use Queuety\Queue;

class ChainBuilderTest extends TestCase {

	public function test_on_queue_returns_self(): void {
		$queue   = $this->createStub( Queue::class );
		$builder = new ChainBuilder( array(), $queue );

		$result = $builder->on_queue( 'emails' );
		$this->assertSame( $builder, $result );
	}

	public function test_catch_returns_self(): void {
		$queue   = $this->createStub( Queue::class );
		$builder = new ChainBuilder( array(), $queue );

		$result = $builder->catch( 'SomeHandler' );
		$this->assertSame( $builder, $result );
	}

	public function test_dispatch_returns_zero_for_empty_chain(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->never() )->method( 'dispatch' );

		$builder = new ChainBuilder( array(), $queue );
		$this->assertSame( 0, $builder->dispatch() );
	}

	public function test_fluent_interface(): void {
		$queue   = $this->createStub( Queue::class );
		$builder = new ChainBuilder( array(), $queue );

		$result = $builder
			->on_queue( 'emails' )
			->catch( 'ErrorHandler' );

		$this->assertSame( $builder, $result );
	}
}
