<?php
/**
 * Workflow forking integration tests.
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

class WorkflowForkTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
	}

	private function create_workflow( array $steps, array $initial_payload = array() ): int {
		$builder = new WorkflowBuilder( 'test_fork', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		return $builder->dispatch( $initial_payload );
	}

	public function test_fork_running_workflow_has_same_state(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB', 'StepC' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ) );

		// Fork the workflow at step 1.
		$forked_id = $this->workflow->fork( $wf_id );
		$this->assertGreaterThan( $wf_id, $forked_id );

		// Verify original status.
		$original_status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $original_status->status );
		$this->assertSame( 1, $original_status->current_step );

		// Verify forked status matches.
		$forked_status = $this->workflow->status( $forked_id );
		$this->assertSame( WorkflowStatus::Running, $forked_status->status );
		$this->assertSame( 1, $forked_status->current_step );
		$this->assertSame( $original_status->total_steps, $forked_status->total_steps );

		// Both should have the same public state.
		$this->assertSame( $original_status->state, $forked_status->state );

		// Forked name should contain _fork_.
		$this->assertStringContainsString( '_fork_', $forked_status->name );
	}

	public function test_fork_produces_independent_workflows(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB', 'StepC' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ) );

		// Fork the workflow.
		$forked_id = $this->workflow->fork( $wf_id );

		// Now we have 2 pending jobs in the queue: one for each workflow's step 1.
		// Advance the original.
		$job_orig = $this->queue->claim();
		$this->assertNotNull( $job_orig );
		$this->workflow->advance_step( $wf_id, $job_orig->id, array( 'step1_result' => 'original_b' ) );

		$original_status = $this->workflow->status( $wf_id );
		$this->assertSame( 2, $original_status->current_step );

		// Forked should still be at step 1.
		$forked_status = $this->workflow->status( $forked_id );
		$this->assertSame( 1, $forked_status->current_step );
		$this->assertArrayNotHasKey( 'step1_result', $forked_status->state );
	}

	public function test_forked_workflow_completes_independently(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB' ),
			array( 'input' => 'data' ),
		);

		// Advance through step 0.
		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ) );

		// Fork at step 1.
		$forked_id = $this->workflow->fork( $wf_id );

		// Advance the original to completion.
		$job_orig = $this->queue->claim();
		$this->assertNotNull( $job_orig );
		$this->workflow->advance_step( $wf_id, $job_orig->id, array( 'step1_result' => 'orig_b' ) );

		$original_status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $original_status->status );

		// Advance the forked to completion.
		$job_fork = $this->queue->claim();
		$this->assertNotNull( $job_fork );
		$this->workflow->advance_step( $forked_id, $job_fork->id, array( 'step1_result' => 'fork_b' ) );

		$forked_status = $this->workflow->status( $forked_id );
		$this->assertSame( WorkflowStatus::Completed, $forked_status->status );
		$this->assertSame( 'fork_b', $forked_status->state['step1_result'] );
	}
}
