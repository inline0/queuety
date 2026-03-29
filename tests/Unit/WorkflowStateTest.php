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
		);

		$this->assertSame( 'workflow', $state->wait_type );
		$this->assertSame( array( '12', '18' ), $state->waiting_for );
	}
}
