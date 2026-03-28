<?php
/**
 * Sub-workflow tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

/**
 * Tests for sub-workflow dispatching and parent workflow coordination.
 */
class SubWorkflowTest extends IntegrationTestCase {

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

	public function test_sub_workflow_dispatched_and_parent_waits(): void {
		$sub_builder = new WorkflowBuilder( 'sub_wf', $this->conn, $this->queue, $this->logger );
		$sub_builder->then( AccumulatingStep::class );

		$wf_id = Queuety::workflow( 'parent_with_sub' )
			->then( DataFetchStep::class )
			->sub_workflow( 'sub_task', $sub_builder )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 10 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #10', $status->state['user_name'] );

		// Step 1 is a sub_workflow. The next job should be the __queuety_sub_workflow placeholder.
		$placeholder = $this->queue->claim();
		$this->assertNotNull( $placeholder );
		$this->assertSame( '__queuety_sub_workflow', $placeholder->handler );
		$this->assertSame( 1, $placeholder->step_index );
		$this->assertSame( $wf_id, $placeholder->workflow_id );

		// Process the placeholder: this dispatches the sub-workflow.
		$this->worker->process_job( $placeholder );

		// Parent should still be at step 1 (waiting for sub-workflow).
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// There should now be a sub-workflow in the database.
		$all_workflows = $this->workflow_mgr->list( WorkflowStatus::Running );
		$sub_wf        = null;
		foreach ( $all_workflows as $wf ) {
			if ( null !== $wf->parent_workflow_id && $wf->parent_workflow_id === $wf_id ) {
				$sub_wf = $wf;
				break;
			}
		}
		$this->assertNotNull( $sub_wf, 'Sub-workflow should exist.' );
		$this->assertSame( $wf_id, $sub_wf->parent_workflow_id );
		$this->assertSame( 1, $sub_wf->parent_step_index );
	}

	public function test_parent_advances_when_sub_completes(): void {
		$sub_builder = new WorkflowBuilder( 'sub_wf', $this->conn, $this->queue, $this->logger );
		$sub_builder->then( AccumulatingStep::class );

		$wf_id = Queuety::workflow( 'parent_advance' )
			->then( DataFetchStep::class )
			->sub_workflow( 'sub_task', $sub_builder )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 20 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: placeholder triggers sub-workflow.
		$this->process_one();

		// Process sub-workflow step 0 (AccumulatingStep in the sub).
		$this->process_one();

		// Sub-workflow should be completed now.
		$all_workflows = $this->workflow_mgr->list( WorkflowStatus::Completed );
		$sub_completed = false;
		foreach ( $all_workflows as $wf ) {
			if ( null !== $wf->parent_workflow_id && $wf->parent_workflow_id === $wf_id ) {
				$sub_completed = true;
				break;
			}
		}
		$this->assertTrue( $sub_completed, 'Sub-workflow should be completed.' );

		// Parent should have advanced past step 1 to step 2.
		$parent_status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $parent_status->current_step );
		$this->assertSame( WorkflowStatus::Running, $parent_status->status );

		// Process step 2: AccumulatingStep in the parent.
		$this->process_one();

		$parent_status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $parent_status->status );
	}

	public function test_sub_workflow_state_merges_into_parent(): void {
		$sub_builder = new WorkflowBuilder( 'sub_merge', $this->conn, $this->queue, $this->logger );
		$sub_builder->then( AccumulatingStep::class );

		$wf_id = Queuety::workflow( 'parent_merge' )
			->then( DataFetchStep::class )
			->sub_workflow( 'sub_task', $sub_builder )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 30 ) );

		// Step 0.
		$this->process_one();
		// Step 1: sub-workflow placeholder.
		$this->process_one();
		// Sub-workflow step 0: AccumulatingStep sets counter=1.
		$this->process_one();

		// Parent should now have the sub-workflow's state merged in.
		$parent_status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $parent_status->state['counter'] );
		$this->assertSame( 'User #30', $parent_status->state['user_name'] );

		// Process step 2: AccumulatingStep in the parent.
		// It should see counter=1 from sub-workflow and increment to 2.
		$this->process_one();

		$parent_status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $parent_status->status );
		$this->assertSame( 2, $parent_status->state['counter'] );
		$this->assertSame( 'User #30', $parent_status->state['user_name'] );
	}

	public function test_sub_workflow_as_last_step_completes_parent(): void {
		$sub_builder = new WorkflowBuilder( 'sub_last', $this->conn, $this->queue, $this->logger );
		$sub_builder->then( AccumulatingStep::class );

		$wf_id = Queuety::workflow( 'parent_sub_last' )
			->then( DataFetchStep::class )
			->sub_workflow( 'sub_task', $sub_builder )
			->dispatch( array( 'user_id' => 40 ) );

		// Step 0.
		$this->process_one();
		// Step 1: sub-workflow placeholder.
		$this->process_one();
		// Sub-workflow step 0.
		$this->process_one();

		// Parent workflow should be completed since sub_workflow is the last step.
		$parent_status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $parent_status->status );
		$this->assertSame( 1, $parent_status->state['counter'] );
	}
}
