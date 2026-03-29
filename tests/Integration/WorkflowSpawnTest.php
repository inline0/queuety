<?php
/**
 * Workflow spawn integration tests.
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
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;

class WorkflowSpawnTest extends IntegrationTestCase {

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
	}

	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}

		$this->worker->process_job( $job );
		return $job;
	}

	public function test_spawn_workflows_dispatches_independent_child_workflows_and_stores_ids(): void {
		$child = ( new WorkflowBuilder( 'agent_task', $this->conn, $this->queue, $this->logger ) )
			->version( 'agent-task.v1' )
			->then( AccumulatingStep::class );

		$parent_id = ( new WorkflowBuilder( 'planner', $this->conn, $this->queue, $this->logger ) )
			->spawn_workflows( 'tasks', $child, 'child_workflow_ids', 'topic', true, 'spawn_agents' )
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'brief_id' => 42,
					'tasks'    => array(
						array( 'topic' => 'pricing' ),
						array( 'topic' => 'reviews' ),
					),
				)
			);

		$this->process_one();

		$parent_status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $parent_status->status );
		$this->assertSame( 1, $parent_status->current_step );
		$this->assertCount( 2, $parent_status->state['child_workflow_ids'] );

		$topics = array();
		foreach ( $parent_status->state['child_workflow_ids'] as $child_id ) {
			$child_status = $this->workflow_mgr->status( (int) $child_id );
			$this->assertNotNull( $child_status );
			$this->assertSame( WorkflowStatus::Running, $child_status->status );
			$this->assertSame( 'agent-task.v1', $child_status->definition_version );
			$this->assertSame( 42, $child_status->state['brief_id'] );
			$topics[] = $child_status->state['topic'];
		}

		sort( $topics );
		$this->assertSame( array( 'pricing', 'reviews' ), $topics );
	}

	public function test_spawned_workflows_can_be_joined_later_with_await_workflows(): void {
		$child = ( new WorkflowBuilder( 'agent_task', $this->conn, $this->queue, $this->logger ) )
			->then( AccumulatingStep::class );

		$parent_id = ( new WorkflowBuilder( 'planner_waiter', $this->conn, $this->queue, $this->logger ) )
			->with_priority( Priority::Urgent )
			->spawn_workflows( 'tasks', $child, 'child_workflow_ids' )
			->await_workflows( 'child_workflow_ids', WaitMode::All, 'child_results' )
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'campaign' => 'spring',
					'tasks'    => array(
						array( 'topic' => 'pricing' ),
						array( 'topic' => 'reviews' ),
					),
				)
			);

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingWorkflow, $status->status );
		$this->assertCount( 2, $status->waiting_for );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingWorkflow, $status->status );

		$this->process_one();
		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );
		$this->assertCount( 2, $status->state['child_results'] );

		$first_result  = $status->state['child_results'][ (string) $status->state['child_workflow_ids'][0] ];
		$second_result = $status->state['child_results'][ (string) $status->state['child_workflow_ids'][1] ];

		$this->assertSame( 'spring', $first_result['campaign'] );
		$this->assertSame( 'pricing', $first_result['topic'] );
		$this->assertSame( 0, $first_result['spawn_item_index'] );
		$this->assertSame( 1, $first_result['counter'] );

		$this->assertSame( 'spring', $second_result['campaign'] );
		$this->assertSame( 'reviews', $second_result['topic'] );
		$this->assertSame( 1, $second_result['spawn_item_index'] );
		$this->assertSame( 1, $second_result['counter'] );

		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_agent_aliases_wrap_spawn_and_wait_primitives(): void {
		$child = ( new WorkflowBuilder( 'agent_worker', $this->conn, $this->queue, $this->logger ) )
			->then( AccumulatingStep::class );

		$parent_id = ( new WorkflowBuilder( 'planner_aliases', $this->conn, $this->queue, $this->logger ) )
			->spawn_agents( 'agent_tasks', $child )
			->await_agents()
			->dispatch(
				array(
					'campaign'    => 'summer',
					'agent_tasks' => array(
						array( 'topic' => 'landing-page' ),
						array( 'topic' => 'pricing-copy' ),
					),
				)
			);

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::WaitingWorkflow, $status->status );
		$this->assertSame( 'all', $status->wait_mode );
		$this->assertSame( array(), $status->wait_details['matched'] );
		$this->assertCount( 2, $status->wait_details['remaining'] );

		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $parent_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertCount( 2, $status->state['agent_results'] );
	}
}
