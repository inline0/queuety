<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\WorkflowStatus;
use Queuety\WorkflowState;

class WorkflowStateTest extends TestCase {

	public function test_construction(): void {
		$state = new WorkflowState(
			workflow_id: 42,
			name: 'test_workflow',
			status: WorkflowStatus::Running,
			current_step: 1,
			total_steps: 3,
			state: array( 'user_id' => 123 ),
		);

		$this->assertSame( 42, $state->workflow_id );
		$this->assertSame( 'test_workflow', $state->name );
		$this->assertSame( WorkflowStatus::Running, $state->status );
		$this->assertSame( 1, $state->current_step );
		$this->assertSame( 3, $state->total_steps );
		$this->assertSame( array( 'user_id' => 123 ), $state->state );
	}

	public function test_readonly(): void {
		$state = new WorkflowState(
			workflow_id: 1,
			name: 'test',
			status: WorkflowStatus::Completed,
			current_step: 2,
			total_steps: 2,
			state: array(),
		);

		$reflection = new \ReflectionClass( $state );
		$this->assertTrue( $reflection->isReadonly() );
	}

	public function test_exposes_wait_context_when_present(): void {
		$state = new WorkflowState(
			workflow_id: 7,
			name: 'awaiting_review',
			status: WorkflowStatus::WaitingWorkflow,
			current_step: 2,
			total_steps: 4,
			state: array(),
			wait_type: 'workflow',
			waiting_for: array( '12', '18' ),
			current_step_name: 'await_review',
			wait_mode: 'all',
			wait_details: array(
				'matched' => array(),
				'remaining' => array( '12', '18' ),
			),
		);

		$this->assertSame( 'workflow', $state->wait_type );
		$this->assertSame( array( '12', '18' ), $state->waiting_for );
		$this->assertSame( 'await_review', $state->current_step_name );
		$this->assertSame( 'all', $state->wait_mode );
		$this->assertSame( array( '12', '18' ), $state->wait_details['remaining'] );
	}

	public function test_exposes_definition_and_budget_metadata_when_present(): void {
		$state = new WorkflowState(
			workflow_id: 12,
			name: 'agent_run',
			status: WorkflowStatus::Running,
			current_step: 1,
			total_steps: 3,
			state: array(),
			definition_version: 'agents.v2',
			definition_hash: str_repeat( 'a', 64 ),
			idempotency_key: 'run:12',
			budget: array(
				'max_transitions'   => 10,
				'max_state_bytes'   => 2048,
				'transitions'       => 1,
				'public_state_bytes' => 128,
			),
		);

		$this->assertSame( 'agents.v2', $state->definition_version );
		$this->assertSame( str_repeat( 'a', 64 ), $state->definition_hash );
		$this->assertSame( 'run:12', $state->idempotency_key );
		$this->assertSame( 10, $state->budget['max_transitions'] );
		$this->assertSame( 128, $state->budget['public_state_bytes'] );
	}
}
