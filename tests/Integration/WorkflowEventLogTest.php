<?php
/**
 * WorkflowEventLog unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\WorkflowEventLog;

/**
 * Tests for WorkflowEventLog value construction and method signatures.
 *
 * Tests that require a database are skipped if one is unavailable.
 */
class WorkflowEventLogTest extends TestCase {

	private bool $has_db = false;
	private ?WorkflowEventLog $event_log = null;
	private ?Connection $conn = null;

	protected function setUp(): void {
		parent::setUp();

		try {
			$dsn = sprintf(
				'mysql:host=%s;dbname=%s;charset=utf8mb4',
				QUEUETY_TEST_DB_HOST,
				QUEUETY_TEST_DB_NAME
			);
			new \PDO( $dsn, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS );

			$this->conn = new Connection(
				host: QUEUETY_TEST_DB_HOST,
				dbname: QUEUETY_TEST_DB_NAME,
				user: QUEUETY_TEST_DB_USER,
				password: QUEUETY_TEST_DB_PASS,
				prefix: QUEUETY_TEST_DB_PREFIX,
			);

			\Queuety\Schema::install( $this->conn );
			$this->event_log = new WorkflowEventLog( $this->conn );
			$this->has_db    = true;
		} catch ( \PDOException $e ) {
			// No database.
		}
	}

	protected function tearDown(): void {
		if ( $this->has_db && null !== $this->conn ) {
			try {
				\Queuety\Schema::uninstall( $this->conn );
			} catch ( \Throwable $e ) {
				// Ignore.
			}
		}
		parent::tearDown();
	}

	private function skip_without_db(): void {
		if ( ! $this->has_db ) {
			$this->markTestSkipped( 'Database not available.' );
		}
	}

	/**
	 * Create a workflow row for testing.
	 */
	private function create_workflow(): int {
		$wf_tbl = $this->conn->table( \Queuety\Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$stmt = $pdo->prepare(
			"INSERT INTO {$wf_tbl}
			(name, status, state, current_step, total_steps)
			VALUES ('test_wf', 'running', '{}', 0, 2)"
		);
		$stmt->execute();

		return (int) $pdo->lastInsertId();
	}

	// -- Constructor acceptance ----------------------------------------------

	public function test_constructor_accepts_connection(): void {
		$this->skip_without_db();

		$this->assertInstanceOf( WorkflowEventLog::class, $this->event_log );
	}

	// -- record_step_started -------------------------------------------------

	public function test_record_step_started(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_started( $wf_id, 0, 'TestHandler' );

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'step_started', $timeline[0]['event'] );
		$this->assertSame( 'TestHandler', $timeline[0]['handler'] );
		$this->assertSame( 0, (int) $timeline[0]['step_index'] );
	}

	// -- record_step_completed -----------------------------------------------

	public function test_record_step_completed(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_completed(
			workflow_id: $wf_id,
			step_index: 0,
			handler: 'StepA',
			state_before: array( 'input' => 'value' ),
			state_after: array( 'data' => 'value' ),
			output: array( 'result' => 42 ),
			duration_ms: 150,
			step_name: 'loadData',
			step_type: 'ability',
			job_id: 99,
			attempt: 1,
			queue: 'default',
		);

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'step_completed', $timeline[0]['event'] );
		$this->assertSame( array( 'input' => 'value' ), $timeline[0]['state_before'] );
		$this->assertSame( array( 'data' => 'value' ), $timeline[0]['state_after'] );
		$this->assertSame( array( 'result' => 42 ), $timeline[0]['output'] );
		$this->assertSame( 'loadData', $timeline[0]['step_name'] );
		$this->assertSame( 'ability', $timeline[0]['step_type'] );
		$this->assertSame( 99, (int) $timeline[0]['job_id'] );
		$this->assertSame( 150, (int) $timeline[0]['duration_ms'] );
	}

	// -- record_step_failed --------------------------------------------------

	public function test_record_step_failed(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_failed(
			workflow_id: $wf_id,
			step_index: 1,
			handler: 'FailingStep',
			error: array( 'message' => 'Something went wrong', 'class' => \RuntimeException::class ),
			duration_ms: 50,
		);

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'step_failed', $timeline[0]['event'] );
		$this->assertSame( 'Something went wrong', $timeline[0]['error_message'] );
	}

	public function test_record_workflow_waiting_and_resumed_events(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_workflow_waiting(
			workflow_id: $wf_id,
			step_index: 1,
			handler: '__queuety_wait_for_signal',
			state_before: array( 'counter' => 1 ),
			state_after: array( 'counter' => 1 ),
			wait_type: 'signal',
			waiting_for: array( 'approval' ),
		);
		$this->event_log->record_workflow_resumed(
			workflow_id: $wf_id,
			step_index: 1,
			handler: '__queuety_wait_for_signal',
			state_before: array( 'counter' => 1 ),
			state_after: array( 'counter' => 1, 'approval' => array( 'approved' => true ) ),
			output: array( 'approval' => array( 'approved' => true ) ),
		);

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 2, $timeline );
		$this->assertSame( 'workflow_waiting', $timeline[0]['event'] );
		$this->assertSame(
			array(
				'wait_type'   => 'signal',
				'waiting_for' => array( 'approval' ),
			),
			$timeline[0]['output']
		);
		$this->assertSame( 'workflow_resumed', $timeline[1]['event'] );
		$this->assertSame(
			array( 'approval' => array( 'approved' => true ) ),
			$timeline[1]['output']
		);
	}

	public function test_record_workflow_replayed_event(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_workflow_replayed(
			workflow_id: $wf_id,
			step_index: 0,
			state_after: array( 'input' => 'data' ),
			context: array(
				'source_workflow_id' => 12,
				'definition_hash'    => str_repeat( 'b', 64 ),
			),
		);

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'workflow_replayed', $timeline[0]['event'] );
		$this->assertSame( 12, $timeline[0]['output']['source_workflow_id'] );
	}

	// -- get_timeline returns events in order --------------------------------

	public function test_get_timeline_returns_events_in_order(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_started( $wf_id, 0, 'StepA' );
		$this->event_log->record_step_completed( $wf_id, 0, 'StepA', array(), array(), array(), 100 );
		$this->event_log->record_step_started( $wf_id, 1, 'StepB' );

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 3, $timeline );
		$this->assertSame( 'step_started', $timeline[0]['event'] );
		$this->assertSame( 'step_completed', $timeline[1]['event'] );
		$this->assertSame( 'step_started', $timeline[2]['event'] );
	}

	public function test_get_timeline_supports_limit_and_offset(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_started( $wf_id, 0, 'StepA' );
		$this->event_log->record_step_completed( $wf_id, 0, 'StepA', array(), array(), array(), 100 );
		$this->event_log->record_step_started( $wf_id, 1, 'StepB' );

		$timeline = $this->event_log->get_timeline( $wf_id, 1, 1 );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'step_completed', $timeline[0]['event'] );
	}

	// -- get_state_at_step ---------------------------------------------------

	public function test_get_state_at_step(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_completed( $wf_id, 0, 'StepA', array(), array( 'key' => 'val0' ), array(), 100 );
		$this->event_log->record_step_completed( $wf_id, 1, 'StepB', array(), array( 'key' => 'val1' ), array(), 200 );

		$state0 = $this->event_log->get_state_at_step( $wf_id, 0 );
		$this->assertSame( array( 'key' => 'val0' ), $state0 );

		$state1 = $this->event_log->get_state_at_step( $wf_id, 1 );
		$this->assertSame( array( 'key' => 'val1' ), $state1 );
	}

	public function test_get_state_at_step_returns_null_for_missing(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();

		$result = $this->event_log->get_state_at_step( $wf_id, 99 );
		$this->assertNull( $result );
	}

	// -- get_timeline for non-existent workflow returns empty ----------------

	public function test_get_timeline_nonexistent_workflow_returns_empty(): void {
		$this->skip_without_db();

		$timeline = $this->event_log->get_timeline( 999999 );
		$this->assertSame( array(), $timeline );
	}

	// -- prune old events ---------------------------------------------------

	public function test_prune_removes_old_events(): void {
		$this->skip_without_db();

		$wf_id = $this->create_workflow();
		$this->event_log->record_step_started( $wf_id, 0, 'OldStep' );

		// Manually backdate the event.
		$table = $this->conn->table( \Queuety\Config::table_workflow_events() );
		$this->conn->pdo()->exec(
			"UPDATE {$table} SET created_at = DATE_SUB(NOW(), INTERVAL 100 DAY)"
		);

		$deleted = $this->event_log->prune( 30 );
		$this->assertGreaterThan( 0, $deleted );

		$timeline = $this->event_log->get_timeline( $wf_id );
		$this->assertCount( 0, $timeline );
	}
}
