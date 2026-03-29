<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\Enums\JoinMode;
use Queuety\Enums\Priority;
use Queuety\Enums\WaitMode;
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

	public function test_compensate_with_throws_when_no_steps_exist(): void {
		$builder = $this->make_builder();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot attach compensation before adding a step.' );

		$builder->compensate_with( 'CleanupStep' );
	}

	public function test_fan_out_builds_serializable_definition(): void {
		$builder = $this->make_builder();
		$steps   = $builder
			->fan_out(
				items_key: 'tasks',
				handler_class: 'ProcessTaskStep',
				result_key: 'task_results',
				join_mode: JoinMode::Quorum,
				quorum: 2,
				reducer_class: 'ReduceTasks',
				name: 'task_fan_out',
			)
			->compensate_with( 'UndoFanOut' )
			->build_steps();

		$this->assertSame(
			array(
				'type'          => 'fan_out',
				'name'          => 'task_fan_out',
				'items_key'     => 'tasks',
				'class'         => 'ProcessTaskStep',
				'result_key'    => 'task_results',
				'join_mode'     => 'quorum',
				'quorum'        => 2,
				'reducer_class' => 'ReduceTasks',
				'compensation'  => 'UndoFanOut',
			),
			$steps[0]
		);
	}

	public function test_special_steps_preserve_compensation_in_build_steps(): void {
		$sub_builder = $this->make_builder( 'child' )->then( 'ChildStep' );

		$timer_steps = $this->make_builder( 'timer' )
			->sleep( seconds: 30 )
			->compensate_with( 'UndoTimer' )
			->build_steps();
		$signal_steps = $this->make_builder( 'signal' )
			->wait_for_signal( 'approved' )
			->compensate_with( 'UndoSignal' )
			->build_steps();
		$workflow_wait_steps = $this->make_builder( 'workflow_wait' )
			->await_workflow( 42 )
			->compensate_with( 'UndoWorkflowWait' )
			->build_steps();
		$sub_steps = $this->make_builder( 'parent' )
			->sub_workflow( 'child', $sub_builder )
			->compensate_with( 'UndoSubWorkflow' )
			->build_steps();

		$this->assertSame( 'UndoTimer', $timer_steps[0]['compensation'] );
		$this->assertSame( 'UndoSignal', $signal_steps[0]['compensation'] );
		$this->assertSame( 'UndoWorkflowWait', $workflow_wait_steps[0]['compensation'] );
		$this->assertSame( 'UndoSubWorkflow', $sub_steps[0]['compensation'] );
	}

	public function test_wait_for_signals_builds_serializable_definition(): void {
		$steps = $this->make_builder( 'signals' )
			->wait_for_signals(
				array( 'approved', 'reviewed' ),
				WaitMode::Any,
				'gate_result',
				'wait_gate'
			)
			->build_steps();

		$this->assertSame(
			array(
				'type'         => 'signal',
				'name'         => 'wait_gate',
				'signal_name'  => 'approved',
				'signal_names' => array( 'approved', 'reviewed' ),
				'wait_mode'    => 'any',
				'result_key'   => 'gate_result',
				'compensation' => null,
			),
			$steps[0]
		);
	}

	public function test_await_approval_uses_namespaced_result_key(): void {
		$steps = $this->make_builder( 'approval' )
			->await_approval()
			->build_steps();

		$this->assertSame( 'signal', $steps[0]['type'] );
		$this->assertSame( 'approval', $steps[0]['signal_name'] );
		$this->assertSame( 'approval', $steps[0]['result_key'] );
	}

	public function test_await_workflows_builds_serializable_definition(): void {
		$steps = $this->make_builder( 'deps' )
			->await_workflows(
				array( 12, 18 ),
				WaitMode::Any,
				'dependency_results',
				'wait_for_dependency'
			)
			->build_steps();

		$this->assertSame(
			array(
				'type'            => 'workflow_wait',
				'name'            => 'wait_for_dependency',
				'workflow_ids'    => array( 12, 18 ),
				'workflow_id_key' => null,
				'wait_mode'       => 'any',
				'result_key'      => 'dependency_results',
				'compensation'    => null,
			),
			$steps[0]
		);
	}

	public function test_await_workflow_accepts_state_key_source(): void {
		$steps = $this->make_builder( 'deps' )
			->await_workflow( 'child_workflow_id', 'child_state' )
			->build_steps();

		$this->assertSame( 'workflow_wait', $steps[0]['type'] );
		$this->assertSame( 'child_workflow_id', $steps[0]['workflow_id_key'] );
		$this->assertNull( $steps[0]['workflow_ids'] );
		$this->assertSame( 'child_state', $steps[0]['result_key'] );
	}
}
