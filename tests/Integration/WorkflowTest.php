<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowState;
use Queuety\Tests\IntegrationTestCase;

class WorkflowTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
	}

	// -- helpers ------------------------------------------------------------

	private function create_workflow( array $steps, array $initial_payload = array(), string $queue = 'default' ): int {
		$builder = new WorkflowBuilder( 'test_workflow', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		$builder->on_queue( $queue );
		return $builder->dispatch( $initial_payload );
	}

	// -- full lifecycle -----------------------------------------------------

	public function test_full_three_step_workflow_lifecycle(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB', 'StepC' ),
			array( 'input' => 'data' ),
		);

		// Step 0: claim and advance.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->assertSame( $wf_id, $job0->workflow_id );
		$this->assertSame( 0, $job0->step_index );

		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );

		// Step 1: claim and advance.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->assertSame( 1, $job1->step_index );

		$this->workflow->advance_step( $wf_id, $job1->id, array( 'step1_result' => 'b' ) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( 2, $status->current_step );

		// Step 2 (last): claim and advance.
		$job2 = $this->queue->claim();
		$this->assertNotNull( $job2 );
		$this->assertSame( 2, $job2->step_index );

		$this->workflow->advance_step( $wf_id, $job2->id, array( 'step2_result' => 'c' ) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->current_step );
	}

	// -- state accumulation -------------------------------------------------

	public function test_state_accumulation_across_steps(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB' ),
			array( 'initial' => 'value' ),
		);

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'from_step0' => 'alpha' ) );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 'value', $state['initial'] );
		$this->assertSame( 'alpha', $state['from_step0'] );

		$job1 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job1->id, array( 'from_step1' => 'beta' ) );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 'value', $state['initial'] );
		$this->assertSame( 'alpha', $state['from_step0'] );
		$this->assertSame( 'beta', $state['from_step1'] );
	}

	public function test_step_output_with_underscore_prefix_is_not_merged(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array(
			'public_key'  => 'visible',
			'_private_key' => 'should_not_merge',
		) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( 'visible', $status->state['public_key'] );
		$this->assertArrayNotHasKey( '_private_key', $status->state );
	}

	// -- advance_step -------------------------------------------------------

	public function test_advance_step_creates_next_step_job(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array() );

		// Next job should be step 1.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->assertSame( 'StepB', $job1->handler );
		$this->assertSame( 1, $job1->step_index );
		$this->assertSame( $wf_id, $job1->workflow_id );
	}

	public function test_advance_step_on_last_step_marks_workflow_completed(): void {
		$wf_id = $this->create_workflow( array( 'StepA' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'result' => 'done' ) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->current_step );
	}

	public function test_advance_step_marks_completed_job(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array() );

		$completed_job = $this->queue->find( $job0->id );
		$this->assertSame( JobStatus::Completed, $completed_job->status );
	}

	// -- fail ---------------------------------------------------------------

	public function test_fail_marks_workflow_as_failed(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );

		$job0 = $this->queue->claim();
		$this->workflow->fail( $wf_id, $job0->id, 'Step handler threw exception' );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	// -- retry --------------------------------------------------------------

	public function test_retry_re_enqueues_the_failed_step(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );

		$job0 = $this->queue->claim();
		$this->workflow->fail( $wf_id, $job0->id, 'error' );

		$this->workflow->retry( $wf_id );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// A new job for step 0 should be enqueued.
		$retry_job = $this->queue->claim();
		$this->assertNotNull( $retry_job );
		$this->assertSame( 'StepA', $retry_job->handler );
		$this->assertSame( 0, $retry_job->step_index );
		$this->assertSame( $wf_id, $retry_job->workflow_id );
	}

	public function test_retry_throws_if_workflow_not_failed(): void {
		$wf_id = $this->create_workflow( array( 'StepA' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not in failed state' );
		$this->workflow->retry( $wf_id );
	}

	public function test_retry_throws_if_workflow_not_found(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );
		$this->workflow->retry( 999999 );
	}

	// -- pause / resume -----------------------------------------------------

	public function test_pause_prevents_next_step_from_being_enqueued(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );

		// Pause before step 0 completes.
		$this->workflow->pause( $wf_id );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'r' => 'v' ) );

		// No next job should be enqueued.
		$next = $this->queue->claim();
		$this->assertNull( $next );

		$status = $this->workflow->status( $wf_id );
		// After advance_step with paused status, step advances but no job created.
		$this->assertSame( 1, $status->current_step );
	}

	public function test_resume_after_pause_enqueues_next_step(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );

		$this->workflow->pause( $wf_id );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array() );

		// Resume the paused workflow.
		$this->workflow->resume( $wf_id );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// The next step job should now be available.
		$next = $this->queue->claim();
		$this->assertNotNull( $next );
		$this->assertSame( 'StepB', $next->handler );
		$this->assertSame( 1, $next->step_index );
	}

	public function test_resume_throws_if_not_paused(): void {
		$wf_id = $this->create_workflow( array( 'StepA' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not paused' );
		$this->workflow->resume( $wf_id );
	}

	// -- status -------------------------------------------------------------

	public function test_status_returns_workflow_state_with_reserved_keys_stripped(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA' ),
			array( 'user_id' => 42 ),
		);

		$status = $this->workflow->status( $wf_id );

		$this->assertInstanceOf( WorkflowState::class, $status );
		$this->assertSame( $wf_id, $status->workflow_id );
		$this->assertSame( 'test_workflow', $status->name );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 1, $status->total_steps );
		$this->assertSame( 42, $status->state['user_id'] );
		$this->assertArrayNotHasKey( '_steps', $status->state );
		$this->assertArrayNotHasKey( '_queue', $status->state );
		$this->assertArrayNotHasKey( '_priority', $status->state );
		$this->assertArrayNotHasKey( '_max_attempts', $status->state );
	}

	public function test_status_returns_null_for_nonexistent_workflow(): void {
		$this->assertNull( $this->workflow->status( 999999 ) );
	}

	// -- list ---------------------------------------------------------------

	public function test_list_returns_all_workflows(): void {
		$this->create_workflow( array( 'StepA' ) );
		$this->create_workflow( array( 'StepB' ) );

		$all = $this->workflow->list();

		$this->assertCount( 2, $all );
		$this->assertContainsOnlyInstancesOf( WorkflowState::class, $all );
	}

	public function test_list_filters_by_status(): void {
		$wf1 = $this->create_workflow( array( 'StepA' ) );
		$wf2 = $this->create_workflow( array( 'StepA' ) );

		// Complete wf1.
		$job = $this->queue->claim();
		$this->workflow->advance_step( $wf1, $job->id, array() );

		$running   = $this->workflow->list( WorkflowStatus::Running );
		$completed = $this->workflow->list( WorkflowStatus::Completed );

		$this->assertCount( 1, $running );
		$this->assertCount( 1, $completed );
		$this->assertSame( $wf2, $running[0]->workflow_id );
		$this->assertSame( $wf1, $completed[0]->workflow_id );
	}

	// -- purge_completed ----------------------------------------------------

	public function test_purge_completed_deletes_old_completed_workflows(): void {
		$wf1 = $this->create_workflow( array( 'StepA' ) );
		$wf2 = $this->create_workflow( array( 'StepA' ) );

		// Complete both.
		$job1 = $this->queue->claim();
		$this->workflow->advance_step( $wf1, $job1->id, array() );

		$job2 = $this->queue->claim();
		$this->workflow->advance_step( $wf2, $job2->id, array() );

		// Backdate wf1.
		$this->raw_update(
			'queuety_workflows',
			array( 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 15 ) ),
			array( 'id' => $wf1 ),
		);

		$deleted = $this->workflow->purge_completed( 7 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->workflow->status( $wf1 ) );
		$this->assertNotNull( $this->workflow->status( $wf2 ) );
	}

	// -- get_state ----------------------------------------------------------

	public function test_get_state_returns_full_state_including_reserved_keys(): void {
		$wf_id = $this->create_workflow(
			array( 'StepA', 'StepB' ),
			array( 'user_id' => 7 ),
		);

		$state = $this->workflow->get_state( $wf_id );

		$this->assertSame( 7, $state['user_id'] );
		$this->assertArrayHasKey( '_steps', $state );
		$this->assertCount( 2, $state['_steps'] );
		$this->assertSame( 'StepA', $state['_steps'][0]['class'] );
		$this->assertSame( 'StepB', $state['_steps'][1]['class'] );
		$this->assertSame( 'single', $state['_steps'][0]['type'] );
		$this->assertArrayHasKey( '_queue', $state );
		$this->assertArrayHasKey( '_priority', $state );
		$this->assertArrayHasKey( '_max_attempts', $state );
	}

	public function test_get_state_returns_null_for_nonexistent_workflow(): void {
		$this->assertNull( $this->workflow->get_state( 999999 ) );
	}

	// -- advance_step throws for missing workflow ---------------------------

	public function test_advance_step_throws_for_nonexistent_workflow(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );
		$this->workflow->advance_step( 999999, 1, array() );
	}
}
