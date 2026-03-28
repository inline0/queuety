<?php
/**
 * Integration tests for workflow event log (state snapshots).
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
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowEventLog;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\FailingStep;

class WorkflowEventLogTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private WorkflowEventLog $event_log;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue     = new Queue( $this->conn );
		$this->logger    = new Logger( $this->conn );
		$this->event_log = new WorkflowEventLog( $this->conn );
		$this->workflow  = new Workflow( $this->conn, $this->queue, $this->logger, null, $this->event_log );
		$this->registry  = new HandlerRegistry();
		$this->worker    = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
			null,  // rate_limiter
			null,  // scheduler
			null,  // webhook_notifier
			null,  // batch_manager
			null,  // chunk_store
			$this->event_log,
		);
	}

	/**
	 * Create a workflow using the builder.
	 *
	 * @param array  $steps   Step handler classes.
	 * @param array  $payload Initial payload.
	 * @param string $queue   Queue name.
	 * @return int Workflow ID.
	 */
	private function create_workflow( array $steps, array $payload = array(), string $queue = 'default' ): int {
		$builder = new WorkflowBuilder( 'test_workflow', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		$builder->on_queue( $queue );
		return $builder->dispatch( $payload );
	}

	/**
	 * Claim and process one job.
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

	// -- step events recorded during workflow execution ----------------------

	public function test_step_events_recorded_during_workflow_execution(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class, AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process step 0.
		$this->process_one();

		// Process step 1.
		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		// Expect: step_started(0), step_completed(0), step_started(1), step_completed(1).
		$this->assertCount( 4, $timeline );

		$this->assertSame( 'step_started', $timeline[0]['event'] );
		$this->assertSame( 0, (int) $timeline[0]['step_index'] );

		$this->assertSame( 'step_completed', $timeline[1]['event'] );
		$this->assertSame( 0, (int) $timeline[1]['step_index'] );

		$this->assertSame( 'step_started', $timeline[2]['event'] );
		$this->assertSame( 1, (int) $timeline[2]['step_index'] );

		$this->assertSame( 'step_completed', $timeline[3]['event'] );
		$this->assertSame( 1, (int) $timeline[3]['step_index'] );
	}

	// -- get_timeline returns all events in order ---------------------------

	public function test_get_timeline_returns_all_events_in_order(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class, AccumulatingStep::class, AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process all three steps.
		$this->process_one();
		$this->process_one();
		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		// 3 steps * 2 events each = 6 events.
		$this->assertCount( 6, $timeline );

		// Verify ordering by id.
		$ids = array_map( fn( array $e ) => (int) $e['id'], $timeline );
		$sorted = $ids;
		sort( $sorted );
		$this->assertSame( $sorted, $ids );
	}

	// -- get_state_at_step returns correct snapshot --------------------------

	public function test_get_state_at_step_returns_correct_snapshot(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class, AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process step 0.
		$this->process_one();

		$state_at_0 = $this->event_log->get_state_at_step( $wf_id, 0 );
		$this->assertNotNull( $state_at_0 );
		$this->assertSame( 1, $state_at_0['counter'] );

		// Process step 1.
		$this->process_one();

		$state_at_1 = $this->event_log->get_state_at_step( $wf_id, 1 );
		$this->assertNotNull( $state_at_1 );
		$this->assertSame( 2, $state_at_1['counter'] );
	}

	// -- state snapshot captures full state at that moment -------------------

	public function test_state_snapshot_captures_full_state(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class, AccumulatingStep::class ),
			array( 'counter' => 10 ),
		);

		// Process step 0 (counter: 10 -> 11).
		$this->process_one();

		$snapshot = $this->event_log->get_state_at_step( $wf_id, 0 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 11, $snapshot['counter'] );

		// Process step 1 (counter: 11 -> 12).
		$this->process_one();

		$snapshot_1 = $this->event_log->get_state_at_step( $wf_id, 1 );
		$this->assertNotNull( $snapshot_1 );
		$this->assertSame( 12, $snapshot_1['counter'] );

		// Step 0 snapshot should still show 11 (historical).
		$snapshot_0_again = $this->event_log->get_state_at_step( $wf_id, 0 );
		$this->assertSame( 11, $snapshot_0_again['counter'] );
	}

	// -- step output recorded separately from state -------------------------

	public function test_step_output_recorded_separately(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class ),
			array( 'counter' => 5 ),
		);

		// Process step 0.
		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		// Find the step_completed event.
		$completed_events = array_filter( $timeline, fn( $e ) => 'step_completed' === $e['event'] );
		$completed        = array_values( $completed_events );

		$this->assertCount( 1, $completed );

		// The step output should be { counter: 6 } (what the step returned).
		$this->assertIsArray( $completed[0]['step_output'] );
		$this->assertSame( 6, $completed[0]['step_output']['counter'] );

		// The state snapshot should also contain counter: 6
		// (full state after merge, minus reserved keys).
		$this->assertIsArray( $completed[0]['state_snapshot'] );
		$this->assertSame( 6, $completed[0]['state_snapshot']['counter'] );
	}

	// -- failed step records error ------------------------------------------

	public function test_failed_step_records_error(): void {
		$wf_id = $this->create_workflow(
			array( FailingStep::class ),
			array(),
		);

		// Process step 0 - this will fail.
		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		// Should have step_started and step_failed events.
		$started_events = array_filter( $timeline, fn( $e ) => 'step_started' === $e['event'] );
		$failed_events  = array_filter( $timeline, fn( $e ) => 'step_failed' === $e['event'] );

		$this->assertCount( 1, $started_events );
		$this->assertCount( 1, $failed_events );

		$failed = array_values( $failed_events )[0];
		$this->assertNotNull( $failed['error_message'] );
		$this->assertNotEmpty( $failed['error_message'] );
		$this->assertNotNull( $failed['duration_ms'] );
	}

	// -- prune removes old events -------------------------------------------

	public function test_prune_removes_old_events(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process step 0.
		$this->process_one();

		// Verify events exist.
		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertNotEmpty( $timeline );

		// Backdate all events to 30 days ago.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 30 * 86400 ) );
		$table    = $this->conn->table( Config::table_workflow_events() );
		$stmt     = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET created_at = :old_date WHERE workflow_id = :wf_id"
		);
		$stmt->execute( array( 'old_date' => $old_date, 'wf_id' => $wf_id ) );

		// Prune events older than 7 days.
		$deleted = $this->event_log->prune( 7 );
		$this->assertGreaterThan( 0, $deleted );

		// Verify events are gone.
		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertEmpty( $timeline );
	}

	// -- timeline shows duration for completed steps ------------------------

	public function test_timeline_shows_duration_for_completed_steps(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process step 0.
		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		// Find the step_completed event.
		$completed_events = array_filter( $timeline, fn( $e ) => 'step_completed' === $e['event'] );
		$completed        = array_values( $completed_events );

		$this->assertCount( 1, $completed );
		$this->assertNotNull( $completed[0]['duration_ms'] );
		$this->assertIsNumeric( $completed[0]['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0, (int) $completed[0]['duration_ms'] );
	}

	// -- event_log is optional (null = no recording) ------------------------

	public function test_workflow_works_without_event_log(): void {
		// Create a workflow/worker pair WITHOUT the event log.
		$workflow_no_log = new Workflow( $this->conn, $this->queue, $this->logger );
		$worker_no_log   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$workflow_no_log,
			$this->registry,
			new Config(),
		);

		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		// Process step 0 using the no-log worker.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$worker_no_log->process_job( $job );

		// Workflow should still complete (no errors from null event_log).
		$status = $workflow_no_log->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );

		// Event log table should have no entries from this worker.
		// (It may have entries from setUp, but the no-log worker doesn't add any.)
		// We just verify no exception was thrown.
		$this->assertTrue( true );
	}

	// -- get_state_at_step returns null for missing steps --------------------

	public function test_get_state_at_step_returns_null_for_missing_step(): void {
		$result = $this->event_log->get_state_at_step( 999999, 0 );
		$this->assertNull( $result );
	}

	// -- handler name is recorded correctly ---------------------------------

	public function test_handler_name_is_recorded_in_events(): void {
		$wf_id = $this->create_workflow(
			array( AccumulatingStep::class ),
			array( 'counter' => 0 ),
		);

		$this->process_one();

		$timeline = $this->event_log->get_timeline( $wf_id );

		foreach ( $timeline as $event ) {
			$this->assertSame( AccumulatingStep::class, $event['handler'] );
		}
	}
}
