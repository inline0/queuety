<?php
/**
 * WorkflowCommand unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\CLI;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli-compat.php';

use PHPUnit\Framework\TestCase;
use Queuety\CLI\WorkflowCommand;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Workflow;
use Queuety\WorkflowEventLog;

/**
 * Tests for WorkflowCommand CLI methods.
 */
class WorkflowCommandTest extends TestCase {

	private WorkflowCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new WorkflowCommand();

		try {
			$dsn = sprintf(
				'mysql:host=%s;dbname=%s;charset=utf8mb4',
				QUEUETY_TEST_DB_HOST,
				QUEUETY_TEST_DB_NAME
			);
			new \PDO( $dsn, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS );

			$conn = new Connection(
				host: QUEUETY_TEST_DB_HOST,
				dbname: QUEUETY_TEST_DB_NAME,
				user: QUEUETY_TEST_DB_USER,
				password: QUEUETY_TEST_DB_PASS,
				prefix: QUEUETY_TEST_DB_PREFIX,
			);

			Queuety::reset();
			Queuety::init( $conn );
			\Queuety\Schema::install( $conn );
			$this->has_db = true;
		} catch ( \PDOException $e ) {
			// No database.
		}
	}

	protected function tearDown(): void {
		if ( $this->has_db ) {
			try {
				\Queuety\Schema::uninstall( Queuety::connection() );
			} catch ( \Throwable $e ) {
				// Ignore.
			}
			Queuety::reset();
		}
		parent::tearDown();
	}

	private function skip_without_db(): void {
		if ( ! $this->has_db ) {
			$this->markTestSkipped( 'Database not available for CLI tests.' );
		}
	}

	/**
	 * Create a test workflow directly in the database.
	 */
	private function create_test_workflow( string $status = 'running' ): int {
		$conn   = Queuety::connection();
		$wf_tbl = $conn->table( Config::table_workflows() );
		$pdo    = $conn->pdo();

		$state = json_encode( array(
			'_steps'        => array(
				array( 'type' => 'single', 'class' => 'FakeStep' ),
				array( 'type' => 'single', 'class' => 'FakeStep2' ),
			),
			'_queue'        => 'default',
			'_priority'     => 0,
			'_max_attempts' => 3,
			'user_id'       => 42,
		) );

		$stmt = $pdo->prepare(
			"INSERT INTO {$wf_tbl}
			(name, status, state, current_step, total_steps)
			VALUES (:name, :status, :state, 0, 2)"
		);
		$stmt->execute( array(
			'name'   => 'test_workflow',
			'status' => $status,
			'state'  => $state,
		) );

		return (int) $pdo->lastInsertId();
	}

	// -- status() shows workflow info ----------------------------------------

	public function test_status_shows_workflow_info(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow();
		$this->cmd->status( array( $wf_id ), array() );
		$this->assertTrue( true );
	}

	public function test_status_nonexistent_workflow_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );

		$this->cmd->status( array( 999999 ), array() );
	}

	// -- retry() calls Workflow::retry() -------------------------------------

	public function test_retry_calls_workflow_retry(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow( 'failed' );

		$this->cmd->retry( array( $wf_id ), array() );

		$state = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $state->status );
	}

	// -- pause() calls Workflow::pause() -------------------------------------

	public function test_pause_calls_workflow_pause(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow( 'running' );

		$this->cmd->pause( array( $wf_id ), array() );

		$state = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Paused, $state->status );
	}

	// -- resume() calls Workflow::resume() -----------------------------------

	public function test_resume_calls_workflow_resume(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow( 'paused' );

		$this->cmd->resume( array( $wf_id ), array() );

		$state = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $state->status );
	}

	// -- cancel() calls Workflow::cancel() -----------------------------------

	public function test_cancel_calls_workflow_cancel(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow( 'running' );

		$this->cmd->cancel( array( $wf_id ), array() );

		$state = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Cancelled, $state->status );
	}

	public function test_cancel_completed_workflow_throws(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow( 'completed' );

		$this->expectException( \RuntimeException::class );

		$this->cmd->cancel( array( $wf_id ), array() );
	}

	// -- list_() lists workflows ---------------------------------------------

	public function test_list_lists_workflows(): void {
		$this->skip_without_db();

		$this->create_test_workflow();
		$this->create_test_workflow( 'failed' );

		$this->cmd->list_( array(), array() );
		$this->assertTrue( true );
	}

	public function test_list_with_status_filter(): void {
		$this->skip_without_db();

		$this->create_test_workflow( 'running' );
		$this->create_test_workflow( 'failed' );

		$this->cmd->list_( array(), array( 'status' => 'running' ) );
		$this->assertTrue( true );
	}

	// -- timeline() shows events ---------------------------------------------

	public function test_timeline_shows_events(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow();

		// Record some events.
		$event_log = Queuety::workflow_events();
		$event_log->record_step_started( $wf_id, 0, 'FakeStep' );
		$event_log->record_step_completed( $wf_id, 0, 'FakeStep', array( 'user_id' => 42 ), array(), 100 );

		$this->cmd->timeline( array( $wf_id ), array() );
		$this->assertTrue( true );
	}

	public function test_timeline_no_events(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow();
		$this->cmd->timeline( array( $wf_id ), array() );
		$this->assertTrue( true );
	}

	// -- state_at() shows state snapshot -------------------------------------

	public function test_state_at_shows_snapshot(): void {
		$this->skip_without_db();

		$wf_id     = $this->create_test_workflow();
		$event_log = Queuety::workflow_events();
		$event_log->record_step_completed( $wf_id, 0, 'FakeStep', array( 'data' => 'test' ), array( 'data' => 'test' ), 50 );

		$this->cmd->state_at( array( $wf_id, 0 ), array() );
		$this->assertTrue( true );
	}

	public function test_state_at_not_found_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No state snapshot found' );

		$this->cmd->state_at( array( 999999, 0 ), array() );
	}
}
