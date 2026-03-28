<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\Priority;
use Queuety\PendingJob;
use Queuety\Queue;

class PendingJobTest extends TestCase {

	private function make_stub_queue(): Queue {
		$queue = $this->createStub( Queue::class );
		$queue->method( 'dispatch' )->willReturn( 99 );
		return $queue;
	}

	public function test_on_queue_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->on_queue( 'custom' );
		$this->assertSame( $pending, $result );

		// Force dispatch to prevent auto-dispatch in destructor.
		$pending->id();
	}

	public function test_with_priority_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->with_priority( Priority::High );
		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_delay_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->delay( 300 );
		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_max_attempts_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->max_attempts( 5 );
		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_unique_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->unique();
		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_after_returns_self(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$result = $pending->after( 42 );
		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_full_fluent_chain(): void {
		$queue   = $this->make_stub_queue();
		$pending = new PendingJob( 'TestHandler', array( 'key' => 'value' ), $queue );

		$result = $pending
			->on_queue( 'emails' )
			->with_priority( Priority::Urgent )
			->delay( 600 )
			->max_attempts( 10 )
			->unique()
			->after( 5 );

		$this->assertSame( $pending, $result );

		$pending->id();
	}

	public function test_id_returns_dispatched_job_id(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->willReturn( 42 );

		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$this->assertSame( 42, $pending->id() );
	}

	public function test_id_forces_dispatch(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->willReturn( 7 );

		$pending = new PendingJob( 'TestHandler', array(), $queue );
		$id      = $pending->id();

		$this->assertSame( 7, $id );
	}

	public function test_id_called_twice_dispatches_only_once(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->willReturn( 55 );

		$pending = new PendingJob( 'TestHandler', array(), $queue );

		$this->assertSame( 55, $pending->id() );
		$this->assertSame( 55, $pending->id() );
	}

	public function test_dispatch_called_with_correct_handler(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->identicalTo( 'MyCustomHandler' ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'MyCustomHandler', array(), $queue );
		$pending->id();
	}

	public function test_dispatch_called_with_correct_payload(): void {
		$payload = array( 'email' => 'test@example.com', 'name' => 'Test' );

		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->identicalTo( $payload ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', $payload, $queue );
		$pending->id();
	}

	public function test_dispatch_called_with_custom_queue_name(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 'emails' ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->on_queue( 'emails' )->id();
	}

	public function test_dispatch_called_with_custom_priority(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( Priority::High ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->with_priority( Priority::High )->id();
	}

	public function test_dispatch_called_with_custom_delay(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 300 ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->delay( 300 )->id();
	}

	public function test_dispatch_called_with_custom_max_attempts(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 10 ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->max_attempts( 10 )->id();
	}

	public function test_default_queue_is_default(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 'default' ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}

	public function test_default_priority_is_low(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( Priority::Low ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}

	public function test_default_delay_is_zero(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 0 ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}

	public function test_default_max_attempts_is_three(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( 3 ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}

	public function test_destructor_triggers_dispatch_if_not_already_dispatched(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		// Let it go out of scope via unset => destructor fires.
		unset( $pending );

		// If we got here without error, the mock verified dispatch was called.
		$this->assertTrue( true );
	}

	public function test_destructor_does_not_dispatch_twice_after_id(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
		unset( $pending );

		$this->assertTrue( true );
	}

	public function test_dispatch_with_all_custom_options(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->identicalTo( 'SendEmail' ),
				$this->identicalTo( array( 'to' => 'a@b.com' ) ),
				$this->identicalTo( 'notifications' ),
				$this->identicalTo( Priority::Urgent ),
				$this->identicalTo( 600 ),
				$this->identicalTo( 7 ),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( true ),
				$this->identicalTo( 42 ),
			)
			->willReturn( 123 );

		$pending = new PendingJob( 'SendEmail', array( 'to' => 'a@b.com' ), $queue );
		$id = $pending
			->on_queue( 'notifications' )
			->with_priority( Priority::Urgent )
			->delay( 600 )
			->max_attempts( 7 )
			->unique()
			->after( 42 )
			->id();

		$this->assertSame( 123, $id );
	}

	public function test_default_unique_is_false(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( false ),
				$this->anything(),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}

	public function test_default_depends_on_is_null(): void {
		$queue = $this->createMock( Queue::class );
		$queue->expects( $this->once() )
			->method( 'dispatch' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo( null ),
			)
			->willReturn( 1 );

		$pending = new PendingJob( 'Handler', array(), $queue );
		$pending->id();
	}
}
