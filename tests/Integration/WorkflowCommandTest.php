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
use Queuety\Queuety;

/**
 * Tests for WorkflowCommand CLI methods.
 */
class WorkflowCommandTest extends TestCase {

	private WorkflowCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new WorkflowCommand();
		\WP_CLI::reset_capture();

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
		\WP_CLI::reset_capture();
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
	private function create_test_workflow( string $status = 'running', ?array $state = null, int $current_step = 0, ?int $total_steps = null ): int {
		$conn   = Queuety::connection();
		$wf_tbl = $conn->table( Config::table_workflows() );
		$pdo    = $conn->pdo();

		$state ??= array(
			'_steps'        => array(
				array( 'type' => 'single', 'class' => 'FakeStep' ),
				array( 'type' => 'single', 'class' => 'FakeStep2' ),
			),
			'_queue'        => 'default',
			'_priority'     => 0,
			'_max_attempts' => 3,
			'user_id'       => 42,
		);

		$total_steps ??= count( $state['_steps'] ?? array() );

		$stmt = $pdo->prepare(
			"INSERT INTO {$wf_tbl}
			(name, status, state, current_step, total_steps)
			VALUES (:name, :status, :state, :current_step, :total_steps)"
		);
		$stmt->execute( array(
			'name'       => 'test_workflow',
			'status'     => $status,
			'state'      => json_encode( $state, JSON_THROW_ON_ERROR ),
			'current_step' => $current_step,
			'total_steps'  => $total_steps,
		) );

		return (int) $pdo->lastInsertId();
	}

	private function assert_logged_contains( string $needle ): void {
		$matched = array_filter(
			\WP_CLI::$log_messages,
			static fn( string $message ): bool => str_contains( $message, $needle )
		);

		$this->assertNotEmpty(
			$matched,
			sprintf( 'Expected WP_CLI log to contain "%s". Logs were: %s', $needle, json_encode( \WP_CLI::$log_messages ) )
		);
	}

	private function last_format_call(): array {
		$this->assertNotEmpty( \WP_CLI::$format_calls, 'Expected WP_CLI to record a formatted table.' );
		return \WP_CLI::$format_calls[ array_key_last( \WP_CLI::$format_calls ) ];
	}

	// -- status() shows workflow info ----------------------------------------

	public function test_status_shows_workflow_info(): void {
		$this->skip_without_db();

		$dependency_id = $this->create_test_workflow();
		$wf_id         = $this->create_test_workflow(
			'waiting_workflow',
			array(
				'_steps' => array(
					array(
						'type'       => 'workflow_wait',
						'name'       => 'await_dependency',
						'workflows'  => array( $dependency_id ),
						'wait_mode'  => 'all',
						'result_key' => 'dependency',
					),
				),
				'_wait'  => array(
					'type'        => 'workflow',
					'waiting_for' => array( (string) $dependency_id ),
					'wait_mode'   => 'all',
					'result_key'  => 'dependency',
				),
				'user_id' => 42,
			),
			0,
			1
		);
		Queuety::put_artifact( $wf_id, 'research_brief', array( 'summary' => 'ok' ) );

		$this->cmd->status( array( $wf_id ), array() );

		$this->assert_logged_contains( "Workflow: test_workflow (#{$wf_id})" );
		$this->assert_logged_contains( 'Status:   waiting_workflow' );
		$this->assert_logged_contains( 'Step:     0/1' );
		$this->assert_logged_contains( 'StepName: await_dependency' );
		$this->assert_logged_contains( "Waiting:  workflow => {$dependency_id}" );
		$this->assert_logged_contains( 'WaitMode: all' );
		$this->assert_logged_contains( 'Artifacts: 1' );
		$this->assert_logged_contains( 'ArtifactKeys: research_brief' );
		$this->assert_logged_contains( '"user_id": 42' );
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

		$call = $this->last_format_call();
		$statuses = array_values( array_unique( array_column( $call['items'], 'Status' ) ) );
		sort( $statuses );
		$this->assertSame( 'table', $call['format'] );
		$this->assertSame( array( 'ID', 'Name', 'Version', 'Hash', 'Status', 'Step', 'StepName', 'WaitMode', 'Waiting' ), $call['fields'] );
		$this->assertCount( 2, $call['items'] );
		$this->assertSame( array( 'failed', 'running' ), $statuses );
	}

	public function test_list_with_status_filter(): void {
		$this->skip_without_db();

		$this->create_test_workflow( 'running' );
		$this->create_test_workflow( 'failed' );

		$this->cmd->list_( array(), array( 'status' => 'running' ) );

		$call = $this->last_format_call();
		$this->assertCount( 1, $call['items'] );
		$this->assertSame( 'running', $call['items'][0]['Status'] );
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

		$call = $this->last_format_call();
		$this->assertCount( 2, $call['items'] );
		$this->assertSame( array( 'step_started', 'step_completed' ), array_column( $call['items'], 'Event' ) );
		$this->assertSame( array( 'FakeStep', 'FakeStep' ), array_column( $call['items'], 'Handler' ) );
	}

	public function test_timeline_no_events(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow();
		$this->cmd->timeline( array( $wf_id ), array() );

		$this->assertSame( array( "No events found for workflow #{$wf_id}." ), \WP_CLI::$log_messages );
	}

	public function test_approve_reject_and_input_commands_store_signals(): void {
		$this->skip_without_db();

		$wf_id   = $this->create_test_workflow();
		$sig_tbl = Queuety::connection()->table( Config::table_signals() );

		$this->cmd->approve( array( $wf_id ), array( 'data' => '{"by":"editor"}' ) );
		$this->cmd->reject( array( $wf_id ), array( 'data' => '{"reason":"bad"}' ) );
		$this->cmd->input( array( $wf_id ), array( 'data' => '{"note":"revise"}' ) );

		$stmt = Queuety::connection()->pdo()->prepare(
			"SELECT signal_name FROM {$sig_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $wf_id ) );

		$this->assertSame(
			array( 'approval', 'rejected', 'input' ),
			array_map( static fn( array $row ): string => $row['signal_name'], $stmt->fetchAll() )
		);
		$this->assertSame(
			array(
				"Approval sent to workflow #{$wf_id}.",
				"Rejection sent to workflow #{$wf_id}.",
				"Input sent to workflow #{$wf_id}.",
			),
			\WP_CLI::$success_messages
		);
	}

	public function test_artifact_and_artifacts_commands_show_stored_artifacts(): void {
		$this->skip_without_db();

		$wf_id = $this->create_test_workflow();
		Queuety::put_artifact( $wf_id, 'research_brief', array( 'summary' => 'ok' ) );

		$this->cmd->artifacts( array( $wf_id ), array( 'with-content' => true ) );
		$call = $this->last_format_call();
		$this->assertCount( 1, $call['items'] );
		$this->assertSame( 'research_brief', $call['items'][0]['Key'] );
		$this->assertSame( '{"summary":"ok"}', $call['items'][0]['Content'] );

		\WP_CLI::reset_capture();
		$this->cmd->artifact( array( $wf_id, 'research_brief' ), array() );
		$this->assert_logged_contains( '"key": "research_brief"' );
		$this->assert_logged_contains( '"summary": "ok"' );
	}

	// -- state_at() shows state snapshot -------------------------------------

	public function test_state_at_shows_snapshot(): void {
		$this->skip_without_db();

		$wf_id     = $this->create_test_workflow();
		$event_log = Queuety::workflow_events();
		$event_log->record_step_completed( $wf_id, 0, 'FakeStep', array( 'data' => 'test' ), array( 'data' => 'test' ), 50 );

		$this->cmd->state_at( array( $wf_id, 0 ), array() );
		$this->assert_logged_contains( "State at step 0 for workflow #{$wf_id}:" );
		$this->assert_logged_contains( '"data": "test"' );
	}

	public function test_state_at_not_found_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No state snapshot found' );

		$this->cmd->state_at( array( 999999, 0 ), array() );
	}
}
