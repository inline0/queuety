<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\CostlyAccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\ForEachItemStep;
use Queuety\Tests\Integration\Fixtures\ForEachPlanningStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;

class WorkflowGuardrailsTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			new HandlerRegistry(),
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

	public function test_idempotent_dispatch_returns_existing_workflow(): void {
		$builder = new WorkflowBuilder( 'idempotent_agent_run', $this->conn, $this->queue, $this->logger );
		$first   = $builder
			->version( 'research.v2' )
			->idempotency_key( 'agent-run:42' )
			->then( DataFetchStep::class )
			->dispatch( array( 'user_id' => 42 ) );

		$second = ( new WorkflowBuilder( 'idempotent_agent_run', $this->conn, $this->queue, $this->logger ) )
			->version( 'research.v2' )
			->idempotency_key( 'agent-run:42' )
			->then( DataFetchStep::class )
			->dispatch( array( 'user_id' => 999 ) );

		$this->assertSame( $first, $second );

		$status = $this->workflow->status( $first );
		$this->assertSame( 'research.v2', $status->definition_version );
		$this->assertNotNull( $status->definition_hash );
		$this->assertSame( 64, strlen( $status->definition_hash ) );
		$this->assertSame( 'agent-run:42', $status->idempotency_key );

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->assertSame( $first, $job->workflow_id );
		$this->assertNull( $this->queue->claim() );

		$key_table = $this->conn->table( Config::table_workflow_dispatch_keys() );
		$stmt      = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) FROM {$key_table} WHERE dispatch_key = :dispatch_key"
		);
		$stmt->execute( array( 'dispatch_key' => 'agent-run:42' ) );
		$this->assertSame( 1, (int) $stmt->fetchColumn() );
	}

	public function test_max_for_each_items_fails_workflow_immediately(): void {
		$workflow_id = ( new WorkflowBuilder( 'for_each_budget', $this->conn, $this->queue, $this->logger ) )
			->max_for_each_items( 2 )
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: ForEachItemStep::class,
				result_key: 'task_results',
				name: 'expand_tasks',
			)
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'a', 'value' => 'one' ),
						array( 'id' => 'b', 'value' => 'two' ),
						array( 'id' => 'c', 'value' => 'three' ),
					),
				)
			);

		$this->process_one();
		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_max_transitions_fails_workflow_when_limit_is_exceeded(): void {
		$workflow_id = ( new WorkflowBuilder( 'transition_budget', $this->conn, $this->queue, $this->logger ) )
			->max_transitions( 1 )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'user_id' => 7 ) );

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->budget['transitions'] );

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_max_transitions_fails_repeating_workflow_when_repeat_runs_too_long(): void {
		$workflow_id = ( new WorkflowBuilder( 'repeat_transition_budget', $this->conn, $this->queue, $this->logger ) )
			->max_transitions( 3 )
			->then( AccumulatingStep::class, 'increment' )
			->repeat_until( 'increment', 'counter', 99, 'keep_counting' )
			->then( DataFetchStep::class )
			->dispatch();

		$this->process_one();
		$this->process_one();
		$this->process_one();
		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_max_state_bytes_fails_workflow_when_public_state_grows_too_large(): void {
		$workflow_id = ( new WorkflowBuilder( 'state_budget', $this->conn, $this->queue, $this->logger ) )
			->max_state_bytes( 64 )
			->then( DataFetchStep::class )
			->dispatch( array( 'user_id' => 55 ) );

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_max_cost_units_fails_workflow_when_completed_steps_exceed_budget(): void {
		$workflow_id = ( new WorkflowBuilder( 'cost_budget', $this->conn, $this->queue, $this->logger ) )
			->max_cost_units( 3 )
			->then( CostlyAccumulatingStep::class )
			->then( CostlyAccumulatingStep::class )
			->dispatch();

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->budget['cost_units'] );

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_max_started_workflows_fails_before_start_step_dispatches_children(): void {
		$child = ( new WorkflowBuilder( 'child_agent', $this->conn, $this->queue, $this->logger ) )
			->then( AccumulatingStep::class );

		$workflow_id = ( new WorkflowBuilder( 'start_budget', $this->conn, $this->queue, $this->logger ) )
			->max_started_workflows( 1 )
			->start_workflows( 'tasks', $child, 'child_workflow_ids' )
			->dispatch(
				array(
					'tasks' => array(
						array( 'topic' => 'pricing' ),
						array( 'topic' => 'reviews' ),
					),
				)
			);

		$this->process_one();

		$status = $this->workflow->status( $workflow_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
		$this->assertArrayNotHasKey( 'child_workflow_ids', $status->state );
	}
}
