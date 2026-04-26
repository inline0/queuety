<?php
/**
 * Durable delay integration tests.
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
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

/**
 * Tests for durable delay steps in workflows.
 */
class DurableDelayTest extends IntegrationTestCase {

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

	public function test_delay_step_enqueues_job_with_correct_delay(): void {
		$wf_id = Queuety::workflow( 'delay_test' )
			->then( DataFetchStep::class )
			->delay( seconds: 60 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 1 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Step 1 is a delay. The next job should be __queuety_delay.
		$delay_job = $this->queue->claim();
		// Delay job might not be claimable yet since available_at is 60 seconds in the future.
		// Look at it directly in the database.
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT * FROM {$jb_tbl} WHERE workflow_id = :wf_id AND step_index = 1 AND handler = '__queuety_delay'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$row = $stmt->fetch();

		$this->assertNotFalse( $row, 'Delay job should exist in the database.' );
		$this->assertSame( '__queuety_delay', $row['handler'] );

		// Verify the available_at is approximately 60 seconds in the future.
		$available_at = new \DateTimeImmutable( $row['available_at'] );
		$now          = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$diff_seconds = $available_at->getTimestamp() - $now->getTimestamp();

		// Allow some tolerance for test execution time.
		$this->assertGreaterThanOrEqual( 55, $diff_seconds );
		$this->assertLessThanOrEqual( 65, $diff_seconds );
	}

	public function test_delay_job_completion_advances_workflow(): void {
		$wf_id = Queuety::workflow( 'delay_advance' )
			->then( DataFetchStep::class )
			->delay( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 2 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Process step 1: delay with zero delay should be immediately claimable.
		$delay_job = $this->process_one();
		$this->assertNotNull( $delay_job );
		$this->assertSame( '__queuety_delay', $delay_job->handler );

		// Workflow should have advanced past the delay to step 2.
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );
	}

	public function test_delay_between_two_real_steps_completes_correctly(): void {
		$wf_id = Queuety::workflow( 'delay_between_steps' )
			->then( DataFetchStep::class )
			->delay( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 3 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: Delay (zero delay).
		$this->process_one();

		// Step 2: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );
		$this->assertSame( 'User #3', $status->state['user_name'] );
	}

	public function test_delay_as_last_step_completes_workflow(): void {
		$wf_id = Queuety::workflow( 'delay_last' )
			->then( DataFetchStep::class )
			->delay( seconds: 0 )
			->dispatch( array( 'user_id' => 4 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: Delay (last step).
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 2, $status->current_step );
	}

	public function test_sleep_calculates_total_delay_from_all_units(): void {
		$builder = Queuety::workflow( 'delay_calc' )
			->then( DataFetchStep::class )
			->delay( seconds: 30, minutes: 2, hours: 1, days: 1 );

		// Dispatch to check the built steps.
		$wf_id = $builder->dispatch( array( 'user_id' => 5 ) );

		$state = $this->workflow_mgr->get_state( $wf_id );
		$steps = $state['_steps'];

		$this->assertSame( 'delay', $steps[1]['type'] );
		// 30 + (2 * 60) + (1 * 3600) + (1 * 86400) = 30 + 120 + 3600 + 86400 = 90150
		$this->assertSame( 90150, $steps[1]['delay_seconds'] );
	}

	public function test_delay_as_first_step_works(): void {
		$wf_id = Queuety::workflow( 'delay_first' )
			->delay( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch();

		// Step 0: Delay (first step, zero delay).
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Step 1: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_multiple_delays_in_workflow(): void {
		$wf_id = Queuety::workflow( 'multi_delay' )
			->then( AccumulatingStep::class )
			->delay( seconds: 0 )
			->then( AccumulatingStep::class )
			->delay( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch();

		// Step 0: AccumulatingStep.
		$this->process_one();

		// Step 1: Delay.
		$this->process_one();

		// Step 2: AccumulatingStep.
		$this->process_one();

		// Step 3: Delay.
		$this->process_one();

		// Step 4: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->state['counter'] );
	}
}
