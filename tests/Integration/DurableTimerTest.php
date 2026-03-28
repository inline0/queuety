<?php
/**
 * Durable timer integration tests.
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
 * Tests for durable timer steps in workflows.
 */
class DurableTimerTest extends IntegrationTestCase {

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

	public function test_timer_step_enqueues_job_with_correct_delay(): void {
		$wf_id = Queuety::workflow( 'timer_test' )
			->then( DataFetchStep::class )
			->sleep( seconds: 60 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 1 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Step 1 is a timer. The next job should be __queuety_timer.
		$timer_job = $this->queue->claim();
		// Timer job might not be claimable yet since available_at is 60 seconds in the future.
		// Look at it directly in the database.
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT * FROM {$jb_tbl} WHERE workflow_id = :wf_id AND step_index = 1 AND handler = '__queuety_timer'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$row = $stmt->fetch();

		$this->assertNotFalse( $row, 'Timer job should exist in the database.' );
		$this->assertSame( '__queuety_timer', $row['handler'] );

		// Verify the available_at is approximately 60 seconds in the future.
		$available_at = new \DateTimeImmutable( $row['available_at'] );
		$now          = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$diff_seconds = $available_at->getTimestamp() - $now->getTimestamp();

		// Allow some tolerance for test execution time.
		$this->assertGreaterThanOrEqual( 55, $diff_seconds );
		$this->assertLessThanOrEqual( 65, $diff_seconds );
	}

	public function test_timer_job_completion_advances_workflow(): void {
		$wf_id = Queuety::workflow( 'timer_advance' )
			->then( DataFetchStep::class )
			->sleep( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 2 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Process step 1: timer with zero delay should be immediately claimable.
		$timer_job = $this->process_one();
		$this->assertNotNull( $timer_job );
		$this->assertSame( '__queuety_timer', $timer_job->handler );

		// Workflow should have advanced past the timer to step 2.
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );
	}

	public function test_timer_between_two_real_steps_completes_correctly(): void {
		$wf_id = Queuety::workflow( 'timer_between_steps' )
			->then( DataFetchStep::class )
			->sleep( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 3 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: Timer (zero delay).
		$this->process_one();

		// Step 2: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );
		$this->assertSame( 'User #3', $status->state['user_name'] );
	}

	public function test_timer_as_last_step_completes_workflow(): void {
		$wf_id = Queuety::workflow( 'timer_last' )
			->then( DataFetchStep::class )
			->sleep( seconds: 0 )
			->dispatch( array( 'user_id' => 4 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: Timer (last step).
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 2, $status->current_step );
	}

	public function test_sleep_calculates_total_delay_from_all_units(): void {
		$builder = Queuety::workflow( 'timer_calc' )
			->then( DataFetchStep::class )
			->sleep( seconds: 30, minutes: 2, hours: 1, days: 1 );

		// Dispatch to check the built steps.
		$wf_id = $builder->dispatch( array( 'user_id' => 5 ) );

		$state = $this->workflow_mgr->get_state( $wf_id );
		$steps = $state['_steps'];

		$this->assertSame( 'timer', $steps[1]['type'] );
		// 30 + (2 * 60) + (1 * 3600) + (1 * 86400) = 30 + 120 + 3600 + 86400 = 90150
		$this->assertSame( 90150, $steps[1]['delay_seconds'] );
	}

	public function test_timer_as_first_step_works(): void {
		$wf_id = Queuety::workflow( 'timer_first' )
			->sleep( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch();

		// Step 0: Timer (first step, zero delay).
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

	public function test_multiple_timers_in_workflow(): void {
		$wf_id = Queuety::workflow( 'multi_timer' )
			->then( AccumulatingStep::class )
			->sleep( seconds: 0 )
			->then( AccumulatingStep::class )
			->sleep( seconds: 0 )
			->then( AccumulatingStep::class )
			->dispatch();

		// Step 0: AccumulatingStep.
		$this->process_one();

		// Step 1: Timer.
		$this->process_one();

		// Step 2: AccumulatingStep.
		$this->process_one();

		// Step 3: Timer.
		$this->process_one();

		// Step 4: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->state['counter'] );
	}
}
