<?php
/**
 * Workflow deadline (Dead Man's Switch) integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;

class WorkflowDeadlineTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
	}

	public function test_check_deadlines_does_not_fire_before_deadline(): void {
		// Create a workflow with a 1-hour deadline.
		$builder = new WorkflowBuilder( 'test_deadline', $this->conn, $this->queue, $this->logger );
		$builder->then( 'StepA' )
			->then( 'StepB' )
			->must_complete_within( hours: 1 );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		// Check deadlines - should not fire (deadline is 1 hour from now).
		$count = $this->workflow->check_deadlines();
		$this->assertSame( 0, $count );

		// Workflow should still be running.
		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
	}

	public function test_expired_deadline_marks_workflow_as_failed(): void {
		// Create a workflow with a deadline.
		$builder = new WorkflowBuilder( 'test_deadline_fail', $this->conn, $this->queue, $this->logger );
		$builder->then( 'StepA' )
			->then( 'StepB' )
			->must_complete_within( seconds: 3600 );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		// Manually set deadline_at to the past.
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl} SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id"
		)->execute( array( 'id' => $wf_id ) );

		// Check deadlines.
		$count = $this->workflow->check_deadlines();
		$this->assertSame( 1, $count );

		// Workflow should now be failed.
		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_expired_deadline_with_handler_calls_handler(): void {
		// Create a simple test handler class in the test namespace.
		$handler_class = DeadlineTestHandler::class;

		// Reset the static call tracker.
		DeadlineTestHandler::$called_with = null;

		$builder = new WorkflowBuilder( 'test_deadline_handler', $this->conn, $this->queue, $this->logger );
		$builder->then( 'StepA' )
			->must_complete_within( seconds: 3600 )
			->on_deadline( $handler_class );
		$wf_id = $builder->dispatch( array( 'user_id' => 42 ) );

		// Manually expire the deadline.
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl} SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id"
		)->execute( array( 'id' => $wf_id ) );

		// Check deadlines.
		$count = $this->workflow->check_deadlines();
		$this->assertSame( 1, $count );

		// Verify the handler was called with the public state.
		$this->assertNotNull( DeadlineTestHandler::$called_with );
		$this->assertSame( 42, DeadlineTestHandler::$called_with['user_id'] );
	}

	public function test_completed_workflow_before_deadline_no_failure(): void {
		$builder = new WorkflowBuilder( 'test_deadline_ok', $this->conn, $this->queue, $this->logger );
		$builder->then( 'StepA' )
			->must_complete_within( hours: 1 );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		// Complete the workflow.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'result' => 'done' ) );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );

		// Check deadlines - should not affect completed workflow.
		$count = $this->workflow->check_deadlines();
		$this->assertSame( 0, $count );

		// Workflow should still be completed.
		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}
}

/**
 * Simple test handler for deadline testing.
 */
class DeadlineTestHandler {

	/**
	 * State passed to handle().
	 *
	 * @var array|null
	 */
	public static ?array $called_with = null;

	/**
	 * Handle the deadline event.
	 *
	 * @param array $state The workflow's public state.
	 */
	public function handle( array $state ): void {
		self::$called_with = $state;
	}
}
