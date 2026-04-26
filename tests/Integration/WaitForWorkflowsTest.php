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

class WaitForWorkflowsTest extends IntegrationTestCase {

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

	public function test_wait_for_workflow_pauses_until_dependency_completes(): void {
		$dependency_id = Queuety::workflow( 'dependency' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 11 ) );

		$this->process_one();

		$parent_id = Queuety::workflow( 'parent_wait' )
			->with_priority( Priority::Urgent )
			->wait_for_workflow( $dependency_id, 'dependency' )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );
		$this->assertSame( 'workflow', $status->wait_type );
		$this->assertSame( array( (string) $dependency_id ), $status->waiting_for );
		$this->assertSame( 'all', $status->wait_mode );
		$this->assertSame( 'wait_for_workflow', $status->current_step_name );
		$this->assertSame( array(), $status->wait_details['matched'] );
		$this->assertSame( array( (string) $dependency_id ), $status->wait_details['remaining'] );

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

	public function test_wait_for_workflows_any_resumes_on_first_completed_dependency(): void {
		$dependency_a = Queuety::workflow( 'dependency_a' )
			->then( AccumulatingStep::class )
			->dispatch();
		$dependency_b = Queuety::workflow( 'dependency_b' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 12 ) );

		$parent_id = Queuety::workflow( 'parent_any' )
			->with_priority( Priority::Urgent )
			->wait_for_workflows( array( $dependency_a, $dependency_b ), WaitMode::Any, 'dependency_results' )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );

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

	public function test_wait_for_workflows_quorum_resumes_after_required_dependencies_complete(): void {
		$dependency_a = Queuety::workflow( 'dependency_a' )
			->then( AccumulatingStep::class )
			->dispatch();
		$dependency_b = Queuety::workflow( 'dependency_b' )
			->then( AccumulatingStep::class )
			->dispatch();
		$dependency_c = Queuety::workflow( 'dependency_c' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 17 ) );

		$parent_id = Queuety::workflow( 'parent_quorum' )
			->with_priority( Priority::Urgent )
			->wait_for_workflows(
				array( $dependency_a, $dependency_b, $dependency_c ),
				WaitMode::Quorum,
				'dependency_results',
				'wait_for_quorum',
				2
			)
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );
		$this->assertSame( 'quorum', $status->wait_mode );
		$this->assertSame( 2, $status->wait_details['quorum'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );
		$this->assertCount( 1, $status->wait_details['matched'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );
		$this->assertCount( 2, $status->state['dependency_results'] );
	}

	public function test_wait_for_workflow_resolves_dependency_id_from_state(): void {
		$dependency_id = Queuety::workflow( 'state_key_dependency' )
			->then( AccumulatingStep::class )
			->dispatch();

		$parent_id = Queuety::workflow( 'parent_state_key' )
			->with_priority( Priority::Urgent )
			->wait_for_workflow( 'dependency_id', 'dependency_state' )
			->then( AccumulatingStep::class )
			->dispatch( array( 'dependency_id' => $dependency_id ) );

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( array( 'counter' => 1 ), $status->state['dependency_state'] );
	}

	public function test_wait_for_workflow_fails_when_dependency_fails(): void {
		$dependency_id = Queuety::workflow( 'failing_dependency' )
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch();

		$parent_id = Queuety::workflow( 'parent_fails' )
			->with_priority( Priority::Urgent )
			->wait_for_workflow( $dependency_id )
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_wait_for_workflows_quorum_fails_when_remaining_dependencies_cannot_satisfy_it(): void {
		$dependency_a = Queuety::workflow( 'dependency_a' )
			->then( AccumulatingStep::class )
			->dispatch();
		$dependency_b = Queuety::workflow( 'dependency_b' )
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch();
		$dependency_c = Queuety::workflow( 'dependency_c' )
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch();

		$parent_id = Queuety::workflow( 'parent_quorum_failure' )
			->with_priority( Priority::Urgent )
			->wait_for_workflows(
				array( $dependency_a, $dependency_b, $dependency_c ),
				WaitMode::Quorum,
				'dependency_results',
				'wait_for_quorum_failure',
				2
			)
			->then( AccumulatingStep::class )
			->dispatch();

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );
		$this->assertCount( 1, $status->wait_details['matched'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, $status->status );
		$this->assertSame( array( (string) $dependency_b ), $status->wait_details['failed'] );
		$this->assertSame( array( (string) $dependency_c ), $status->wait_details['remaining'] );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}
}
