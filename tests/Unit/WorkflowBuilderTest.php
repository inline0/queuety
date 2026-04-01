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

	public function test_metadata_and_budget_methods_return_self_for_chaining(): void {
		$builder = $this->make_builder();
		$result  = $builder
			->version( 'agents.v2' )
			->idempotency_key( 'run:42' )
			->max_transitions( 8 )
			->max_fan_out_items( 16 )
			->max_state_bytes( 4096 )
			->max_cost_units( 32 )
			->max_spawned_workflows( 12 );

		$this->assertSame( $builder, $result );
	}

	public function test_build_runtime_definition_includes_extended_workflow_budgets(): void {
		$definition = $this->make_builder( 'budgeted' )
			->then( 'FetchDataHandler' )
			->max_transitions( 8 )
			->max_fan_out_items( 16 )
			->max_state_bytes( 4096 )
			->max_cost_units( 32 )
			->max_spawned_workflows( 12 )
			->build_runtime_definition();

		$this->assertSame(
			array(
				'max_transitions'       => 8,
				'max_fan_out_items'     => 16,
				'max_state_bytes'       => 4096,
				'max_cost_units'        => 32,
				'max_spawned_workflows' => 12,
			),
			$definition['workflow_budget']
		);
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

	public function test_repeat_until_builds_serializable_definition(): void {
		$steps = $this->make_builder( 'loop' )
			->then( 'FetchDraftStep', 'draft' )
			->repeat_until( 'draft', 'approved', true, 'await_approval_loop' )
			->compensate_with( 'UndoLoopControl' )
			->build_steps();

		$this->assertSame(
			array(
				'type'         => 'loop',
				'name'         => 'await_approval_loop',
				'loop_mode'    => 'until',
				'target_step'  => 'draft',
				'state_key'    => 'approved',
				'expected'     => true,
				'condition_class' => null,
				'max_iterations'  => null,
				'compensation' => 'UndoLoopControl',
			),
			$steps[1]
		);
	}

	public function test_repeat_while_accepts_default_index_names(): void {
		$steps = $this->make_builder( 'loop' )
			->then( 'PollStatusStep' )
			->repeat_while( '0', 'poll_again' )
			->build_steps();

		$this->assertSame( 'loop', $steps[1]['type'] );
		$this->assertSame( 'while', $steps[1]['loop_mode'] );
		$this->assertSame( '0', $steps[1]['target_step'] );
		$this->assertSame( 'poll_again', $steps[1]['state_key'] );
		$this->assertTrue( $steps[1]['expected'] );
		$this->assertNull( $steps[1]['condition_class'] );
		$this->assertNull( $steps[1]['max_iterations'] );
	}

	public function test_repeat_until_accepts_condition_class_and_max_iterations(): void {
		$steps = $this->make_builder( 'loop' )
			->then( 'FetchDraftStep', 'draft' )
			->repeat_until(
				target_step: 'draft',
				condition_class: 'ReviewApprovedCondition',
				name: 'await_review',
				max_iterations: 5,
			)
			->build_steps();

		$this->assertSame( 'loop', $steps[1]['type'] );
		$this->assertSame( 'until', $steps[1]['loop_mode'] );
		$this->assertSame( 'draft', $steps[1]['target_step'] );
		$this->assertNull( $steps[1]['state_key'] );
		$this->assertSame( 'ReviewApprovedCondition', $steps[1]['condition_class'] );
		$this->assertSame( 5, $steps[1]['max_iterations'] );
	}

	public function test_repeat_until_requires_prior_named_step(): void {
		$builder = $this->make_builder( 'loop' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "Loop target 'draft' must reference an earlier named step." );

		$builder->repeat_until( 'draft', 'approved' );
	}

	public function test_repeat_until_requires_state_key_or_condition_class(): void {
		$builder = $this->make_builder( 'loop' )
			->then( 'FetchDraftStep', 'draft' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Loop steps require either a state key or a condition class.' );

		$builder->repeat_until( 'draft', null );
	}

	public function test_repeat_until_disallows_mixing_state_key_and_condition_class(): void {
		$builder = $this->make_builder( 'loop' )
			->then( 'FetchDraftStep', 'draft' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Loop steps cannot define both a state key and a condition class.' );

		$builder->repeat_until( 'draft', 'approved', true, null, 'ReviewApprovedCondition' );
	}

	public function test_repeat_until_requires_positive_max_iterations(): void {
		$builder = $this->make_builder( 'loop' )
			->then( 'FetchDraftStep', 'draft' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Loop steps require max_iterations to be at least 1.' );

		$builder->repeat_until( 'draft', 'approved', true, null, null, 0 );
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
				'type'            => 'signal',
				'name'            => 'wait_gate',
				'signal_name'     => 'approved',
				'signal_names'    => array( 'approved', 'reviewed' ),
				'wait_mode'       => 'any',
				'result_key'      => 'gate_result',
				'match_payload'   => array(),
				'correlation_key' => null,
				'human_wait'      => null,
				'decision_map'    => null,
				'compensation'    => null,
			),
			$steps[0]
		);
	}

	public function test_wait_for_signal_supports_matching_and_correlation(): void {
		$steps = $this->make_builder( 'signals' )
			->wait_for_signal(
				name: 'approval',
				result_key: 'approval_data',
				step_name: 'await_review',
				match_payload: array( 'source' => 'moderation' ),
				correlation_key: 'review_id',
			)
			->build_steps();

		$this->assertSame( 'signal', $steps[0]['type'] );
		$this->assertSame( array( 'source' => 'moderation' ), $steps[0]['match_payload'] );
		$this->assertSame( 'review_id', $steps[0]['correlation_key'] );
	}

	public function test_await_approval_uses_namespaced_result_key(): void {
		$steps = $this->make_builder( 'approval' )
			->await_approval()
			->build_steps();

		$this->assertSame( 'signal', $steps[0]['type'] );
		$this->assertSame( 'approval', $steps[0]['signal_name'] );
		$this->assertSame( 'approval', $steps[0]['human_wait'] );
		$this->assertSame( 'approval', $steps[0]['result_key'] );
	}

	public function test_await_decision_builds_decision_mapping(): void {
		$steps = $this->make_builder( 'approval' )
			->await_decision(
				approve_signal: 'approved',
				reject_signal: 'rejected',
				result_key: 'review',
				name: 'await_review_decision',
			)
			->build_steps();

		$this->assertSame( 'signal', $steps[0]['type'] );
		$this->assertSame( 'any', $steps[0]['wait_mode'] );
		$this->assertSame( 'decision', $steps[0]['human_wait'] );
		$this->assertSame(
			array(
				'approved' => 'approved',
				'rejected' => 'rejected',
			),
			$steps[0]['decision_map']
		);
		$this->assertSame( 'review', $steps[0]['result_key'] );
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
				'type'               => 'workflow_wait',
				'name'               => 'wait_for_dependency',
				'workflow_ids'       => array( 12, 18 ),
				'workflow_id_key'    => null,
				'workflow_group_key' => null,
				'wait_mode'          => 'any',
				'quorum'             => null,
				'result_key'         => 'dependency_results',
				'compensation'       => null,
			),
			$steps[0]
		);
	}

	public function test_await_workflows_quorum_builds_serializable_definition(): void {
		$steps = $this->make_builder( 'deps' )
			->await_workflows(
				array( 12, 18, 24 ),
				WaitMode::Quorum,
				'dependency_results',
				'wait_for_quorum',
				2
			)
			->build_steps();

		$this->assertSame( 'workflow_wait', $steps[0]['type'] );
		$this->assertSame( 'quorum', $steps[0]['wait_mode'] );
		$this->assertSame( 2, $steps[0]['quorum'] );
	}

	public function test_await_workflow_accepts_state_key_source(): void {
		$steps = $this->make_builder( 'deps' )
			->await_workflow( 'child_workflow_id', 'child_state' )
			->build_steps();

		$this->assertSame( 'workflow_wait', $steps[0]['type'] );
		$this->assertSame( 'child_workflow_id', $steps[0]['workflow_id_key'] );
		$this->assertNull( $steps[0]['workflow_group_key'] );
		$this->assertNull( $steps[0]['workflow_ids'] );
		$this->assertSame( 'child_state', $steps[0]['result_key'] );
	}

	public function test_await_workflow_group_builds_serializable_definition(): void {
		$steps = $this->make_builder( 'deps' )
			->await_workflow_group( 'researchers', WaitMode::Quorum, 2, 'research_results' )
			->build_steps();

		$this->assertSame( 'workflow_wait', $steps[0]['type'] );
		$this->assertSame( 'researchers', $steps[0]['workflow_group_key'] );
		$this->assertSame( 'quorum', $steps[0]['wait_mode'] );
		$this->assertSame( 2, $steps[0]['quorum'] );
		$this->assertSame( 'research_results', $steps[0]['result_key'] );
	}

	public function test_spawn_workflows_builds_serializable_definition(): void {
		$child = $this->make_builder( 'agent_task' )
			->version( 'agent-task.v1' )
			->then( 'ProcessTopicStep' );

		$steps = $this->make_builder( 'planner' )
			->spawn_workflows(
				items_key: 'topics',
				workflow_builder: $child,
				result_key: 'child_workflow_ids',
				payload_key: 'topic',
				inherit_state: true,
				name: 'spawn_agents',
			)
			->build_steps();

		$this->assertSame( 'spawn_workflows', $steps[0]['type'] );
		$this->assertSame( 'spawn_agents', $steps[0]['name'] );
		$this->assertSame( 'topics', $steps[0]['items_key'] );
		$this->assertSame( 'child_workflow_ids', $steps[0]['result_key'] );
		$this->assertSame( 'topic', $steps[0]['payload_key'] );
		$this->assertTrue( $steps[0]['inherit_state'] );
		$this->assertNull( $steps[0]['group_key'] );
		$this->assertSame( 'agent_task', $steps[0]['workflow_definition']['name'] );
		$this->assertSame( 'agent-task.v1', $steps[0]['workflow_definition']['definition_version'] );
		$this->assertSame( 64, strlen( $steps[0]['workflow_definition']['definition_hash'] ) );
	}

	public function test_spawn_workflows_can_store_group_metadata(): void {
		$child = $this->make_builder( 'agent_task' )
			->then( 'ProcessTopicStep' );

		$steps = $this->make_builder( 'planner' )
			->spawn_workflows(
				items_key: 'topics',
				workflow_builder: $child,
				group_key: 'researchers',
			)
			->build_steps();

		$this->assertSame( 'researchers', $steps[0]['group_key'] );
	}

	public function test_spawn_agents_alias_uses_agent_defaults(): void {
		$child = $this->make_builder( 'agent_task' )
			->then( 'ProcessTopicStep' );

		$steps = $this->make_builder( 'planner' )
			->spawn_agents( 'tasks', $child )
			->await_agents()
			->build_steps();

		$this->assertSame( 'spawn_workflows', $steps[0]['type'] );
		$this->assertSame( 'spawn_agents', $steps[0]['name'] );
		$this->assertSame( 'agent_workflow_ids', $steps[0]['result_key'] );
		$this->assertSame( 'agent_task', $steps[0]['payload_key'] );

		$this->assertSame( 'workflow_wait', $steps[1]['type'] );
		$this->assertSame( 'agent_workflow_ids', $steps[1]['workflow_id_key'] );
		$this->assertNull( $steps[1]['workflow_group_key'] );
		$this->assertSame( 'agent_results', $steps[1]['result_key'] );
	}

	public function test_await_agent_group_alias_uses_group_wait_defaults(): void {
		$child = $this->make_builder( 'agent_task' )
			->then( 'ProcessTopicStep' );

		$steps = $this->make_builder( 'planner' )
			->spawn_agents( 'tasks', $child, group_key: 'researchers' )
			->await_agent_group( 'researchers', WaitMode::Quorum, 2 )
			->build_steps();

		$this->assertSame( 'researchers', $steps[0]['group_key'] );
		$this->assertSame( 'workflow_wait', $steps[1]['type'] );
		$this->assertSame( 'researchers', $steps[1]['workflow_group_key'] );
		$this->assertSame( 'quorum', $steps[1]['wait_mode'] );
		$this->assertSame( 2, $steps[1]['quorum'] );
	}
}
