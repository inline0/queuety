<?php
/**
 * Workflow signal integration tests.
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
use Queuety\Tests\Integration\Fixtures\SignalCheckStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

/**
 * Tests for workflow signal steps and external signal delivery.
 */
class SignalTest extends IntegrationTestCase {

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

	public function test_wait_for_signal_pauses_workflow(): void {
		$wf_id = Queuety::workflow( 'signal_pause' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'approval' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 1 ) );

		// Process step 0: DataFetchStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Process step 1: signal placeholder job.
		$this->process_one();

		// Workflow should now be in waiting_signal status.
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );
		$this->assertSame( 1, $status->current_step );

		// No more jobs should be available.
		$next_job = $this->queue->claim();
		$this->assertNull( $next_job );
	}

	public function test_signal_resumes_workflow_and_merges_data(): void {
		$wf_id = Queuety::workflow( 'signal_resume' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'approval' )
			->then( SignalCheckStep::class )
			->dispatch( array( 'user_id' => 2 ) );

		// Process step 0.
		$this->process_one();

		// Process step 1: signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );

		// Send the signal with data.
		Queuety::signal( $wf_id, 'approval', array(
			'approval_status' => 'approved',
			'approved_by'     => 'admin',
		) );

		// Workflow should be running again.
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );

		// The signal data should be in the state.
		$this->assertSame( 'approved', $status->state['approval_status'] );
		$this->assertSame( 'admin', $status->state['approved_by'] );

		// Process step 2: SignalCheckStep should see the signal data.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['signal_received'] );
		$this->assertSame( 'approved', $status->state['approval_status'] );
		$this->assertSame( 'admin', $status->state['approved_by'] );
	}

	public function test_signal_sent_before_workflow_reaches_wait_step(): void {
		$wf_id = Queuety::workflow( 'signal_preexisting' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'early_signal' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 3 ) );

		// Send the signal BEFORE the workflow reaches the wait step.
		Queuety::signal( $wf_id, 'early_signal', array(
			'early_data' => 'sent_early',
		) );

		// Process step 0.
		$this->process_one();

		// Process step 1: signal placeholder. Since the signal already exists,
		// the workflow should skip waiting and advance immediately.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		// Workflow should have advanced past the signal step.
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 'sent_early', $status->state['early_data'] );

		// Process step 2: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_signal_with_wrong_name_does_not_resume(): void {
		$wf_id = Queuety::workflow( 'signal_wrong_name' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'correct_signal' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 4 ) );

		// Process step 0.
		$this->process_one();

		// Process step 1: signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );

		// Send a signal with the WRONG name.
		Queuety::signal( $wf_id, 'wrong_signal', array( 'data' => 'ignored' ) );

		// Workflow should still be waiting.
		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );
		$this->assertSame( 1, $status->current_step );

		// The wrong signal should be stored in the signals table though.
		$sig_tbl = $this->conn->table( Config::table_signals() );
		$stmt    = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) AS cnt FROM {$sig_tbl} WHERE workflow_id = :wf_id AND signal_name = 'wrong_signal'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$row = $stmt->fetch();
		$this->assertSame( 1, (int) $row['cnt'] );
	}

	public function test_signal_data_available_in_subsequent_steps(): void {
		$wf_id = Queuety::workflow( 'signal_data_flow' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'review' )
			->then( SignalCheckStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 5 ) );

		// Step 0.
		$this->process_one();

		// Step 1: signal placeholder.
		$this->process_one();

		// Send the signal.
		Queuety::signal( $wf_id, 'review', array(
			'approval_status' => 'reviewed',
			'approved_by'     => 'reviewer',
		) );

		// Step 2: SignalCheckStep - verifies signal data in state.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertTrue( $status->state['signal_received'] );

		// Step 3: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
		$this->assertTrue( $status->state['signal_received'] );
		$this->assertSame( 'reviewed', $status->state['approval_status'] );
	}

	public function test_multiple_signals_in_one_workflow(): void {
		$wf_id = Queuety::workflow( 'multi_signal' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'first_approval' )
			->then( AccumulatingStep::class )
			->wait_for_signal( 'second_approval' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 6 ) );

		// Step 0: DataFetchStep.
		$this->process_one();

		// Step 1: first signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );

		// Send first signal.
		Queuety::signal( $wf_id, 'first_approval', array( 'first' => 'data' ) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );

		// Step 2: AccumulatingStep.
		$this->process_one();

		// Step 3: second signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );
		$this->assertSame( 3, $status->current_step );

		// Send second signal.
		Queuety::signal( $wf_id, 'second_approval', array( 'second' => 'data' ) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 4, $status->current_step );

		// Step 4: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 2, $status->state['counter'] );
		$this->assertSame( 'data', $status->state['first'] );
		$this->assertSame( 'data', $status->state['second'] );
	}

	public function test_signal_as_last_step_completes_workflow(): void {
		$wf_id = Queuety::workflow( 'signal_last' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'final_approval' )
			->dispatch( array( 'user_id' => 7 ) );

		// Step 0.
		$this->process_one();

		// Step 1: signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );

		// Send signal - should complete the workflow since it's the last step.
		Queuety::signal( $wf_id, 'final_approval', array( 'approved' => true ) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['approved'] );
	}

	public function test_signal_audit_trail_is_stored(): void {
		$wf_id = Queuety::workflow( 'signal_audit' )
			->then( DataFetchStep::class )
			->wait_for_signal( 'audit_signal' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 8 ) );

		// Send signals (one before, one during wait).
		Queuety::signal( $wf_id, 'audit_signal', array( 'round' => 1 ) );
		Queuety::signal( $wf_id, 'audit_signal', array( 'round' => 2 ) );

		// Both signals should be in the audit table.
		$sig_tbl = $this->conn->table( Config::table_signals() );
		$stmt    = $this->conn->pdo()->prepare(
			"SELECT * FROM {$sig_tbl} WHERE workflow_id = :wf_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$signals = $stmt->fetchAll();

		$this->assertCount( 2, $signals );
		$this->assertSame( 'audit_signal', $signals[0]['signal_name'] );
		$this->assertSame( 'audit_signal', $signals[1]['signal_name'] );

		$payload_1 = json_decode( $signals[0]['payload'], true );
		$payload_2 = json_decode( $signals[1]['payload'], true );
		$this->assertSame( 1, $payload_1['round'] );
		$this->assertSame( 2, $payload_2['round'] );
	}

	public function test_signal_as_first_step_pauses_immediately(): void {
		$wf_id = Queuety::workflow( 'signal_first' )
			->wait_for_signal( 'start_signal' )
			->then( AccumulatingStep::class )
			->dispatch();

		// Step 0: signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );
		$this->assertSame( 0, $status->current_step );

		// Send signal.
		Queuety::signal( $wf_id, 'start_signal', array( 'started' => true ) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );
		$this->assertTrue( $status->state['started'] );

		// Step 1: AccumulatingStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}
}
