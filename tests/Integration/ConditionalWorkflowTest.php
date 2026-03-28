<?php
/**
 * Conditional branching (_goto) workflow tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\ConditionalGoToStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

/**
 * Tests for conditional _goto branching in workflows.
 */
class ConditionalWorkflowTest extends IntegrationTestCase {

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
	 */
	private function process_one(): void {
		$job = $this->queue->claim();
		if ( null !== $job ) {
			$this->worker->process_job( $job );
		}
	}

	public function test_goto_skips_to_named_step(): void {
		// Build workflow: ConditionalGoTo -> AccumulatingStep (name: 'skipped') -> DataFetch (name: 'target')
		$wf_id = Queuety::workflow( 'goto_test' )
			->then( ConditionalGoToStep::class, 'condition' )
			->then( AccumulatingStep::class, 'skipped' )
			->then( DataFetchStep::class, 'target' )
			->dispatch(
				array(
					'should_skip'  => true,
					'goto_target'  => 'target',
				)
			);

		// Process step 0: ConditionalGoToStep returns _goto => 'target'.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		// Should jump to step index 2 ('target'), skipping step 1 ('skipped').
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertTrue( $status->state['conditional_ran'] );

		// Process step 2: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		// DataFetchStep should have run (uses the should_skip user_id=0 default).
		$this->assertArrayHasKey( 'user_name', $status->state );
		// AccumulatingStep should NOT have run.
		$this->assertArrayNotHasKey( 'counter', $status->state );
	}

	public function test_goto_to_nonexistent_step_throws(): void {
		$wf_id = Queuety::workflow( 'bad_goto' )
			->then( ConditionalGoToStep::class, 'condition' )
			->then( AccumulatingStep::class, 'next' )
			->max_attempts( 1 )
			->dispatch(
				array(
					'should_skip' => true,
					'goto_target' => 'nonexistent_step',
				)
			);

		// The RuntimeException from advance_step is caught by process_job's
		// catch block. With max_attempts=1, the job is buried on the first failure.
		$this->process_one();

		// Verify the job was buried with the _goto target error.
		$jobs_table = $this->conn->table( \Queuety\Config::table_jobs() );
		$stmt       = $this->conn->pdo()->prepare(
			"SELECT status, error_message FROM {$jobs_table} WHERE workflow_id = :wf_id"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$row = $stmt->fetch();

		$this->assertSame( 'buried', $row['status'] );
		$this->assertStringContainsString( '_goto target', $row['error_message'] );
	}

	public function test_normal_flow_without_goto(): void {
		$wf_id = Queuety::workflow( 'no_goto' )
			->then( ConditionalGoToStep::class, 'condition' )
			->then( AccumulatingStep::class, 'accumulate' )
			->then( DataFetchStep::class, 'fetch' )
			->dispatch(
				array(
					'should_skip' => false,
				)
			);

		// Process step 0: no _goto, so advances normally to step 1.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertTrue( $status->state['conditional_ran'] );

		// Process step 1: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );

		// Process step 2: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertArrayHasKey( 'user_name', $status->state );
	}

	public function test_named_steps_with_default_index_names(): void {
		// When no name is provided, the step index is used as the name.
		$wf_id = Queuety::workflow( 'default_names' )
			->then( ConditionalGoToStep::class )
			->then( AccumulatingStep::class )
			->then( DataFetchStep::class )
			->dispatch(
				array(
					'should_skip' => true,
					'goto_target' => '2', // Index-based name.
				)
			);

		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}
}
