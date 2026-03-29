<?php
/**
 * Workflow dependency wait integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\Priority;
use Queuety\Enums\WaitMode;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\FailingStep;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class WorkflowWaitTest extends IntegrationTestCase {

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

	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}

		$this->worker->process_job( $job );
		return $job;
	}

	public function test_await_workflow_pauses_until_dependency_completes(): void {
		$dependency_id = Queuety::workflow( 'dependency' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 11 ) );

		$this->process_one();

		$parent_id = Queuety::workflow( 'parent_wait' )
			->with_priority( Priority::Urgent )
			->await_workflow( $dependency_id, 'dependency' )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingWorkflow, $status->status );
		$this->assertSame( 'workflow', $status->wait_type );
		$this->assertSame( array( (string) $dependency_id ), $status->waiting_for );

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 1, $status->state['dependency']['counter'] );
		$this->assertSame( 'User #11', $status->state['dependency']['user_name'] );

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_await_workflows_any_resumes_on_first_completed_dependency(): void {
		$dependency_a = Queuety::workflow( 'dependency_a' )
			->then( AccumulatingStep::class )
			->dispatch();
		$dependency_b = Queuety::workflow( 'dependency_b' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 12 ) );

		$parent_id = Queuety::workflow( 'parent_any' )
			->with_priority( Priority::Urgent )
			->await_workflows( array( $dependency_a, $dependency_b ), WaitMode::Any, 'dependency_results' )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingWorkflow, $status->status );

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame(
			array(
				(string) $dependency_a => array( 'counter' => 1 ),
			),
			$status->state['dependency_results']
		);
	}

	public function test_await_workflow_resolves_dependency_id_from_state(): void {
		$dependency_id = Queuety::workflow( 'state_key_dependency' )
			->then( AccumulatingStep::class )
			->dispatch();

		$parent_id = Queuety::workflow( 'parent_state_key' )
			->with_priority( Priority::Urgent )
			->await_workflow( 'dependency_id', 'dependency_state' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'dependency_id' => $dependency_id ) );

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( array( 'counter' => 1 ), $status->state['dependency_state'] );
	}

	public function test_await_workflow_fails_when_dependency_fails(): void {
		$dependency_id = Queuety::workflow( 'failing_dependency' )
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch();

		$parent_id = Queuety::workflow( 'parent_fails' )
			->with_priority( Priority::Urgent )
			->await_workflow( $dependency_id )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}
}
