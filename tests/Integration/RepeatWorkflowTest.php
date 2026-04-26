<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\AlwaysRepeatCondition;
use Queuety\Tests\Integration\Fixtures\CounterAtLeastCondition;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\RepeatFlagStep;
use Queuety\Tests\Integration\Fixtures\StructuredRepeatCondition;
use Queuety\Tests\Integration\Fixtures\StructuredWorkflowHandlers;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class RepeatWorkflowTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow_mgr = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			new HandlerRegistry(),
			new Config(),
		);

		Queuety::reset();
		Queuety::init( $this->conn );
		StructuredWorkflowHandlers::reset();
	}

	private function process_one(): void {
		$job = $this->queue->claim();
		if ( null !== $job ) {
			$this->worker->process_job( $job );
		}
	}

	private function process_until_workflow_status( int $workflow_id, WorkflowStatus $expected_status ): void {
		for ( $i = 0; $i < 50; ++$i ) {
			$this->process_one();

			$status = $this->workflow_mgr->status( $workflow_id );
			if ( $expected_status === $status->status ) {
				return;
			}

			usleep( 100_000 );
		}
	}

	public function test_repeat_until_repeats_until_state_matches(): void {
		$workflow_id = Queuety::workflow( 'repeat_until_counter' )
			->then( AccumulatingStep::class, 'increment' )
			->repeat_until( 'increment', 'counter', 3, 'keep_counting' )
			->then( DataFetchStep::class, 'done' )
			->dispatch( array( 'user_id' => 7 ) );

		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );

		$this->process_one();
		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 2, $status->state['counter'] );

		$this->process_one();
		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 3, $status->state['counter'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->state['counter'] );
		$this->assertArrayHasKey( 'user_name', $status->state );
	}

	public function test_repeat_while_repeats_while_state_matches(): void {
		$workflow_id = Queuety::workflow( 'repeat_while_flag' )
			->then( RepeatFlagStep::class, 'poll' )
			->repeat_while( 'poll', 'should_repeat', true, 'repeat_poll' )
			->then( DataFetchStep::class, 'done' )
			->dispatch(
				array(
					'user_id' => 3,
					'limit'   => 3,
				)
			);

		$this->process_one();
		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );
		$this->assertTrue( $status->state['should_repeat'] );

		$this->process_one();
		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 2, $status->state['counter'] );
		$this->assertTrue( $status->state['should_repeat'] );

		$this->process_one();
		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 3, $status->state['counter'] );
		$this->assertFalse( $status->state['should_repeat'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertArrayHasKey( 'user_name', $status->state );
	}

	public function test_repeat_until_accepts_condition_class(): void {
		$workflow_id = Queuety::workflow( 'repeat_until_condition' )
			->then( AccumulatingStep::class, 'increment' )
			->repeat_until(
				target_step: 'increment',
				condition_class: CounterAtLeastCondition::class,
				name: 'wait_for_threshold',
				max_iterations: 5,
			)
			->then( DataFetchStep::class, 'done' )
			->dispatch(
				array(
					'user_id'   => 9,
					'threshold' => 3,
				)
			);

		$this->process_until_workflow_status( $workflow_id, WorkflowStatus::Completed );

		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->state['counter'] );
	}

	public function test_repeat_while_honors_repeat_max_iterations(): void {
		$workflow_id = Queuety::workflow( 'repeat_while_max_iterations' )
			->then( AccumulatingStep::class, 'increment' )
			->repeat_while(
				target_step: 'increment',
				condition_class: AlwaysRepeatCondition::class,
				name: 'repeat_forever',
				max_iterations: 2,
			)
			->then( DataFetchStep::class, 'done' )
			->dispatch();

		$this->process_until_workflow_status( $workflow_id, WorkflowStatus::Failed );

		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_serialized_repeat_condition_receives_structured_payload(): void {
		$step_def = array(
			'type'           => 'repeat',
			'name'           => 'repeat_until_payload_threshold',
			'repeat_mode'    => 'until',
			'target_step'    => 'increment',
			'condition'      => array(
				'class'   => StructuredRepeatCondition::class,
				'payload' => array(
					'key'       => 'counter',
					'threshold' => 2,
				),
			),
			'max_iterations' => 3,
		);

		$continue_output = $this->workflow_mgr->handle_repeat_step(
			123,
			$step_def,
			1,
			array(
				'counter' => 1,
			)
		);

		$stop_output = $this->workflow_mgr->handle_repeat_step(
			123,
			$step_def,
			1,
			array(
				'counter' => 2,
			)
		);

		$this->assertSame( 'increment', $continue_output['_next_step'] );
		$this->assertArrayNotHasKey( '_next_step', $stop_output );
		$this->assertSame( 2, StructuredWorkflowHandlers::$calls['condition'][0]['payload']['threshold'] );
		$this->assertSame( 2, StructuredWorkflowHandlers::$calls['condition'][1]['payload']['threshold'] );
	}
}
