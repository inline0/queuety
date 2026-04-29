<?php
/**
 * Workflow rewind (time travel) integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowEventLog;
use Queuety\Tests\IntegrationTestCase;

class WorkflowRewindTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private WorkflowEventLog $event_log;

	protected function setUp(): void {
		parent::setUp();
		$this->queue     = new Queue( $this->conn );
		$this->logger    = new Logger( $this->conn );
		$this->event_log = new WorkflowEventLog( $this->conn );
		$this->workflow  = new Workflow( $this->conn, $this->queue, $this->logger, null, $this->event_log );
	}

	private function create_workflow( array $steps, array $initial_payload = array() ): int {
		$builder = new WorkflowBuilder( 'test_rewind', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		return $builder->dispatch( $initial_payload );
	}

	public function test_rewind_three_step_workflow_from_step_2_to_step_0(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB', 'StepC' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		// Advance through step 1.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->workflow->advance_step( $wf_id, $job1->id, array( 'step1_result' => 'b' ), 20 );

		// Verify step 0 snapshot exists.
		$snapshot_0 = $this->event_log->get_state_at_step( $wf_id, 0 );
		$this->assertNotNull( $snapshot_0 );
		$this->assertArrayHasKey( 'step0_result', $snapshot_0 );

		// Now rewind to step 0.
		$this->workflow->rewind( $wf_id, 0 );

		// Verify workflow state is restored.
		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step ); // step AFTER rewind point
		$this->assertArrayHasKey( 'step0_result', $status->state );
		$this->assertArrayNotHasKey( 'step1_result', $status->state ); // step 1 output should be gone

		// Verify a new job is enqueued for step 1.
		$new_job = $this->queue->claim();
		$this->assertNotNull( $new_job );
		$this->assertSame( 1, $new_job->step_index );
	}

	public function test_rewind_a_failed_workflow_to_running(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB', 'StepC' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		// Advance through step 1.
		$job1 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job1->id, array( 'step1_result' => 'b' ), 20 );

		// Fail the workflow at step 2.
		$job2 = $this->queue->claim();
		$this->workflow->fail( $wf_id, $job2->id, 'Something went wrong' );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );

		// Rewind to step 0.
		$this->workflow->rewind( $wf_id, 0 );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );
	}

	public function test_rewind_to_invalid_step_throws(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		// Try to rewind to step 5 (doesn't exist).
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No recorded state found' );
		$this->workflow->rewind( $wf_id, 5 );
	}

	public function test_rewind_requires_event_log_data(): void {
		// Create a workflow without event log support.
		$workflow_no_log = new Workflow( $this->conn, $this->queue, $this->logger );

		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB' ),
			array( 'input' => 'data' ),
		);

		// Advance step 0 (no event log entries recorded since workflow_no_log was created
		// without event_log).
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Workflow event log is required' );
		$workflow_no_log->rewind( $wf_id, 0 );
	}
}
