<?php
/**
 * Parallel steps workflow tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\ParallelStepA;
use Queuety\Tests\Integration\Fixtures\ParallelStepB;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

/**
 * Tests for parallel step groups in workflows.
 */
class ParallelStepsTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow_mgr = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry     = new HandlerRegistry();
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			$this->registry,
			new Config(),
		);

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/**
	 * Process exactly one job.
	 *
	 * @return Job|null
	 */
	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}
		$this->worker->process_job( $job );
		return $job;
	}

	public function test_parallel_steps_all_enqueued(): void {
		$builder = new WorkflowBuilder( 'parallel_test', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->parallel( array( ParallelStepA::class, ParallelStepB::class ) )
			->dispatch();

		// Both parallel jobs should be enqueued at step_index 0.
		$job_a = $this->queue->claim();
		$this->assertNotNull( $job_a );
		$this->assertSame( $wf_id, $job_a->workflow_id );
		$this->assertSame( 0, $job_a->step_index );

		$job_b = $this->queue->claim();
		$this->assertNotNull( $job_b );
		$this->assertSame( $wf_id, $job_b->workflow_id );
		$this->assertSame( 0, $job_b->step_index );

		// The two jobs should have different handlers.
		$handlers = array( $job_a->handler, $job_b->handler );
		sort( $handlers );
		$this->assertSame(
			array( ParallelStepA::class, ParallelStepB::class ),
			$handlers
		);

		// No more jobs should be available.
		$this->assertNull( $this->queue->claim() );
	}

	public function test_workflow_dependencies_for_all_parallel_jobs(): void {
		$builder = new WorkflowBuilder( 'parallel_wait', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->parallel( array( ParallelStepA::class, ParallelStepB::class ) )
			->then( AccumulatingStep::class )
			->dispatch();

		// Process only one of the parallel jobs.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		// Workflow should still be at step 0 (waiting for the other parallel job).
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Process the second parallel job.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		// Now should have advanced to step 1.
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// The next step (AccumulatingStep) should be enqueued.
		$next_job = $this->queue->claim();
		$this->assertNotNull( $next_job );
		$this->assertSame( AccumulatingStep::class, $next_job->handler );
		$this->assertSame( 1, $next_job->step_index );
	}

	public function test_parallel_state_merges_correctly(): void {
		$builder = new WorkflowBuilder( 'parallel_merge', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->parallel( array( ParallelStepA::class, ParallelStepB::class ) )
			->then( AccumulatingStep::class )
			->dispatch( array( 'initial' => 'value' ) );

		// Process both parallel steps.
		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );

		// Both parallel steps' outputs should be merged into state.
		$this->assertTrue( $status->state['parallel_a_ran'] );
		$this->assertSame( 'result_from_a', $status->state['parallel_a_result'] );
		$this->assertTrue( $status->state['parallel_b_ran'] );
		$this->assertSame( 'result_from_b', $status->state['parallel_b_result'] );
		$this->assertSame( 'value', $status->state['initial'] );

		// Process the final step.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_parallel_as_middle_step(): void {
		$builder = new WorkflowBuilder( 'parallel_middle', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( DataFetchStep::class )
			->parallel( array( ParallelStepA::class, ParallelStepB::class ) )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 5 ) );

		// Process step 0 (single step).
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #5', $status->state['user_name'] );

		// Process both parallel steps (step 1).
		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertTrue( $status->state['parallel_a_ran'] );
		$this->assertTrue( $status->state['parallel_b_ran'] );

		// Process step 2.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	public function test_single_parallel_step_completes_workflow(): void {
		$builder = new WorkflowBuilder( 'parallel_only', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->parallel( array( ParallelStepA::class, ParallelStepB::class ) )
			->dispatch();

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['parallel_a_ran'] );
		$this->assertTrue( $status->state['parallel_b_ran'] );
	}
}
