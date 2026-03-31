<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\StateMachineStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\StateMachine;
use Queuety\StateMachineEventLog;
use Queuety\Tests\Integration\Fixtures\StateMachineApproveGuard;
use Queuety\Tests\Integration\Fixtures\StateMachineFailingAction;
use Queuety\Tests\Integration\Fixtures\StateMachinePlanningAction;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class StateMachineTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue  = new Queue( $this->conn );
		$this->logger = new Logger( $this->conn );

		$workflow       = new Workflow( $this->conn, $this->queue, $this->logger );
		$state_machines = new StateMachine( $this->conn, $this->queue, new StateMachineEventLog( $this->conn ) );

		$this->worker = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$workflow,
			new HandlerRegistry(),
			new Config(),
			state_machines: $state_machines,
		);

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	private function process_one(): void {
		$job = $this->queue->claim();
		if ( null !== $job ) {
			$this->worker->process_job( $job );
		}
	}

	public function test_machine_dispatch_creates_waiting_machine_with_available_events(): void {
		$machine_id = Queuety::machine( 'chat_session' )
			->state( 'awaiting_user' )
			->on( 'user_message', 'completed' )
			->state( 'completed', StateMachineStatus::Completed )
			->dispatch( array( 'thread_id' => 7 ) );

		$status = Queuety::machine_status( $machine_id );

		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::WaitingEvent, $status->status );
		$this->assertSame( 'awaiting_user', $status->current_state );
		$this->assertSame( array( 'user_message' ), $status->available_events );
		$this->assertSame( 7, $status->state['thread_id'] );

		$list = Queuety::list_machines();
		$this->assertCount( 1, $list );
		$this->assertSame( 'waiting_event', $list[0]['status'] );
	}

	public function test_machine_event_transitions_waiting_machine_to_completed_state(): void {
		$machine_id = Queuety::machine( 'chat_session' )
			->state( 'awaiting_user' )
			->on( 'user_message', 'completed' )
			->state( 'completed', StateMachineStatus::Completed )
			->dispatch( array( 'thread_id' => 7 ) );

		Queuety::machine_event(
			$machine_id,
			'user_message',
			array(
				'message' => 'hello',
				'author'  => 'dennis',
			)
		);

		$status = Queuety::machine_status( $machine_id );

		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::Completed, $status->status );
		$this->assertSame( 'completed', $status->current_state );
		$this->assertSame( 'hello', $status->state['message'] );
		$this->assertSame( 'dennis', $status->state['author'] );

		$events = array_column( Queuety::machine_timeline( $machine_id ), 'event' );
		$this->assertSame(
			array(
				'machine_started',
				'machine_waiting',
				'event_received',
				'transitioned',
				'machine_completed',
			),
			$events
			);
	}

	public function test_machine_timeline_supports_limit_and_offset(): void {
		$machine_id = Queuety::machine( 'chat_session' )
			->state( 'awaiting_user' )
			->on( 'user_message', 'completed' )
			->state( 'completed', StateMachineStatus::Completed )
			->dispatch( array( 'thread_id' => 7 ) );

		Queuety::machine_event(
			$machine_id,
			'user_message',
			array(
				'message' => 'hello',
				'author'  => 'dennis',
			)
		);

		$events = array_column( Queuety::machine_timeline( $machine_id, 2, 1 ), 'event' );
		$this->assertSame( array( 'machine_waiting', 'event_received' ), $events );
	}

	public function test_machine_entry_action_transitions_to_waiting_state_and_guarded_event_completes_it(): void {
		$machine_id = Queuety::machine( 'editorial_session' )
			->version( 'editorial-session.v1' )
			->state( 'planning' )
			->action( StateMachinePlanningAction::class )
			->on( 'planned', 'awaiting_review' )
			->state( 'awaiting_review' )
			->on( 'approve', 'completed', StateMachineApproveGuard::class )
			->state( 'completed', StateMachineStatus::Completed )
			->dispatch( array( 'brief_id' => 42 ) );

		$this->process_one();

		$status = Queuety::machine_status( $machine_id );
		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::WaitingEvent, $status->status );
		$this->assertSame( 'awaiting_review', $status->current_state );
		$this->assertSame( 'editorial-session.v1', $status->definition_version );
		$this->assertSame( 42, $status->state['brief_id'] );
		$this->assertSame( 1, $status->state['plan_attempts'] );
		$this->assertSame( 'outline', $status->state['draft'] );

		try {
			Queuety::machine_event( $machine_id, 'approve', array( 'approved' => false ) );
			$this->fail( 'Expected a rejected machine event to throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( "does not allow event 'approve'", $e->getMessage() );
		}

		$status = Queuety::machine_status( $machine_id );
		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::WaitingEvent, $status->status );
		$this->assertArrayNotHasKey( 'approved', $status->state );

		Queuety::machine_event(
			$machine_id,
			'approve',
			array(
				'approved' => true,
				'reviewer' => 'editor@example.com',
			)
		);

		$status = Queuety::machine_status( $machine_id );
		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::Completed, $status->status );
		$this->assertSame( 'completed', $status->current_state );
		$this->assertTrue( $status->state['approved'] );
		$this->assertSame( 'editor@example.com', $status->state['reviewer'] );

		$events = array_column( Queuety::machine_timeline( $machine_id ), 'event' );
		$this->assertSame(
			array(
				'machine_started',
				'action_started',
				'action_completed',
				'transitioned',
				'machine_waiting',
				'event_received',
				'transitioned',
				'machine_completed',
			),
			$events
		);
	}

	public function test_machine_dispatch_is_idempotent_for_matching_dispatch_key(): void {
		$builder = Queuety::machine( 'chat_session' )
			->state( 'awaiting_user' )
			->on( 'user_message', 'completed' )
			->state( 'completed', StateMachineStatus::Completed )
			->idempotency_key( 'chat:42' );

		$first_id  = $builder->dispatch( array( 'thread_id' => 42 ) );
		$second_id = $builder->dispatch( array( 'thread_id' => 99 ) );

		$this->assertSame( $first_id, $second_id );

		$status = Queuety::machine_status( $first_id );
		$this->assertNotNull( $status );
		$this->assertSame( 42, $status->state['thread_id'] );
		$this->assertSame( 'chat:42', $status->idempotency_key );
		$this->assertCount( 1, Queuety::list_machines() );
	}

	public function test_state_machine_action_failure_marks_machine_failed_after_bury(): void {
		$machine_id = Queuety::machine( 'broken_session' )
			->state( 'planning' )
			->action( StateMachineFailingAction::class )
			->max_attempts( 1 )
			->dispatch();

		$this->process_one();

		$status = Queuety::machine_status( $machine_id );
		$this->assertNotNull( $status );
		$this->assertSame( StateMachineStatus::Failed, $status->status );
		$this->assertSame( 'planning', $status->current_state );
		$this->assertSame( 'State action failed.', $status->error_message );

		$buried = Queuety::buried();
		$this->assertCount( 1, $buried );
		$this->assertSame( JobStatus::Buried, $buried[0]->status );
		$this->assertSame( '__queuety_state_machine_action', $buried[0]->handler );

		$events = array_column( Queuety::machine_timeline( $machine_id ), 'event' );
		$this->assertSame(
			array(
				'machine_started',
				'action_failed',
				'machine_failed',
			),
			$events
		);
	}
}
