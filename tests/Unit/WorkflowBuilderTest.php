<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\Enums\Priority;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\WorkflowBuilder;

class WorkflowBuilderTest extends TestCase {

	private function make_builder( string $name = 'test_workflow' ): WorkflowBuilder {
		$conn   = $this->createStub( Connection::class );
		$queue  = $this->createStub( Queue::class );
		$logger = $this->createStub( Logger::class );

		return new WorkflowBuilder( $name, $conn, $queue, $logger );
	}

	public function test_then_returns_self_for_chaining(): void {
		$builder = $this->make_builder();
		$result  = $builder->then( 'StepOneHandler' );

		$this->assertSame( $builder, $result );
	}

	public function test_then_adds_multiple_steps(): void {
		$builder = $this->make_builder();
		$result  = $builder
			->then( 'StepOneHandler' )
			->then( 'StepTwoHandler' )
			->then( 'StepThreeHandler' );

		$this->assertSame( $builder, $result );
	}

	public function test_on_queue_returns_self_for_chaining(): void {
		$builder = $this->make_builder();
		$result  = $builder->on_queue( 'custom' );

		$this->assertSame( $builder, $result );
	}

	public function test_with_priority_returns_self_for_chaining(): void {
		$builder = $this->make_builder();
		$result  = $builder->with_priority( Priority::High );

		$this->assertSame( $builder, $result );
	}

	public function test_max_attempts_returns_self_for_chaining(): void {
		$builder = $this->make_builder();
		$result  = $builder->max_attempts( 5 );

		$this->assertSame( $builder, $result );
	}

	public function test_full_fluent_chain(): void {
		$builder = $this->make_builder();
		$result  = $builder
			->then( 'FetchDataHandler' )
			->then( 'ProcessHandler' )
			->on_queue( 'heavy' )
			->with_priority( Priority::Urgent )
			->max_attempts( 10 );

		$this->assertSame( $builder, $result );
	}

	public function test_dispatch_throws_when_no_steps_defined(): void {
		$builder = $this->make_builder();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Workflow must have at least one step.' );

		$builder->dispatch( array( 'user_id' => 42 ) );
	}

	public function test_dispatch_throws_when_no_steps_even_with_options_set(): void {
		$builder = $this->make_builder();
		$builder->on_queue( 'custom' )->with_priority( Priority::High )->max_attempts( 5 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Workflow must have at least one step.' );

		$builder->dispatch();
	}

	public function test_dispatch_with_empty_payload_throws_when_no_steps(): void {
		$builder = $this->make_builder();

		$this->expectException( \RuntimeException::class );

		$builder->dispatch( array() );
	}
}
