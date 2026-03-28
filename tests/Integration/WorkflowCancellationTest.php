<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\CancelCleanupHandler;

class WorkflowCancellationTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		CancelCleanupHandler::reset();
	}

	// -- helpers ------------------------------------------------------------

	private function create_workflow( array $steps, array $initial_payload = array() ): int {
		$builder = new WorkflowBuilder( 'test_cancel_wf', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		return $builder->dispatch( $initial_payload );
	}

	private function create_workflow_with_cancel_handler( array $steps, array $initial_payload = array() ): int {
		$builder = new WorkflowBuilder( 'test_cancel_wf', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		$builder->on_cancel( CancelCleanupHandler::class );
		return $builder->dispatch( $initial_payload );
	}

	// -- cancel running workflow --------------------------------------------

	public function test_cancel_running_workflow_sets_status_to_cancelled(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );

		$this->workflow->cancel( $wf_id );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Cancelled, $status->status );
	}

	public function test_cancel_buries_pending_jobs(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );

		// The first step is pending.
		$this->workflow->cancel( $wf_id );

		// Check that the pending job is now buried.
		$jobs_table = $this->conn->table( Config::table_jobs() );
		$stmt       = $this->conn->pdo()->prepare(
			"SELECT status, error_message FROM {$jobs_table} WHERE workflow_id = :wf_id"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$rows = $stmt->fetchAll();

		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'buried', $row['status'] );
			$this->assertSame( 'Workflow cancelled', $row['error_message'] );
		}
	}

	// -- cancel with cleanup handler ----------------------------------------

	public function test_cancel_with_cleanup_handler_runs_handler(): void {
		$wf_id = $this->create_workflow_with_cancel_handler(
			array( 'StepA', 'StepB' ),
			array( 'user_id' => 42 ),
		);

		$this->workflow->cancel( $wf_id );

		$this->assertTrue( CancelCleanupHandler::$called );
		$this->assertSame( 42, CancelCleanupHandler::$received_state['user_id'] );
	}

	public function test_cancel_with_cleanup_handler_passes_public_state_only(): void {
		$wf_id = $this->create_workflow_with_cancel_handler(
			array( 'StepA' ),
			array( 'data' => 'value' ),
		);

		$this->workflow->cancel( $wf_id );

		$this->assertTrue( CancelCleanupHandler::$called );
		// Public state should not contain reserved keys.
		foreach ( CancelCleanupHandler::$received_state as $key => $value ) {
			$this->assertStringStartsNotWith( '_', $key, "Reserved key '{$key}' leaked to cleanup handler." );
		}
	}

	// -- cancel already completed workflow ----------------------------------

	public function test_cancel_completed_workflow_throws(): void {
		$wf_id = $this->create_workflow( array( 'StepA' ) );

		// Advance to completion.
		$job = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job->id, array() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already completed' );
		$this->workflow->cancel( $wf_id );
	}

	public function test_cancel_already_cancelled_workflow_throws(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );
		$this->workflow->cancel( $wf_id );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already cancelled' );
		$this->workflow->cancel( $wf_id );
	}

	// -- cancel paused workflow ---------------------------------------------

	public function test_cancel_paused_workflow_works(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB', 'StepC' ) );
		$this->workflow->pause( $wf_id );

		$this->workflow->cancel( $wf_id );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Cancelled, $status->status );
	}

	// -- log entry ----------------------------------------------------------

	public function test_cancel_logs_workflow_cancelled_event(): void {
		$wf_id = $this->create_workflow( array( 'StepA', 'StepB' ) );

		$this->workflow->cancel( $wf_id );

		$logs = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );
		$this->assertContains( 'workflow_cancelled', $events );
	}
}
