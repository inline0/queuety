<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\ForEachMode;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\ForEachItemStep;
use Queuety\Tests\Integration\Fixtures\ForEachPlanningStep;
use Queuety\Tests\Integration\Fixtures\ForEachSummaryReducer;
use Queuety\Tests\Integration\Fixtures\FlakyForEachItemStep;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

class ForEachWorkflowTest extends IntegrationTestCase {

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

		FlakyForEachItemStep::reset();

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

	public function test_dynamic_for_each_collects_results_and_reducer_output(): void {
		$builder = new WorkflowBuilder( 'for_each_dynamic', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: ForEachItemStep::class,
				result_key: 'task_results',
				reducer_class: ForEachSummaryReducer::class,
				name: 'run_tasks',
			)
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'a', 'value' => 'first' ),
						array( 'id' => 'b', 'value' => 'second' ),
					),
					'source'       => 'planner',
				)
			);

		$this->process_one(); // planner
		$this->process_one(); // for-each placeholder
		$this->process_one(); // branch 0
		$this->process_one(); // branch 1

		$status = $this->workflow->status( $wf_id );

		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->state['task_results']['succeeded'] );
		$this->assertSame( 2, count( $status->state['task_results']['results'] ) );
		$this->assertSame( 2, $status->state['for_each_count'] );
		$this->assertSame( 'a', $status->state['winner_id'] );

		$next = $this->queue->claim();
		$this->assertNotNull( $next );
		$this->assertSame( AccumulatingStep::class, $next->handler );
		$this->worker->process_job( $next );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	public function test_first_success_completion_advances_and_ignores_late_failure(): void {
		$builder = new WorkflowBuilder( 'for_each_first_success', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: ForEachItemStep::class,
				result_key: 'task_results',
				mode: ForEachMode::FirstSuccess,
				name: 'race',
			)
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'fast', 'value' => 'ok' ),
						array( 'id' => 'slow', 'action' => 'fail' ),
					),
				)
			);

		$this->process_one(); // planner
		$this->process_one(); // placeholder

		$job_fast = $this->queue->claim();
		$job_slow = $this->queue->claim();

		$this->assertNotNull( $job_fast );
		$this->assertNotNull( $job_slow );

		$this->worker->process_job( $job_fast );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->state['task_results']['succeeded'] );

		$this->worker->process_job( $job_slow );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );

		$next = $this->queue->claim();
		$this->assertNotNull( $next );
		$this->assertSame( AccumulatingStep::class, $next->handler );
		$this->worker->process_job( $next );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	public function test_quorum_completion_tolerates_branch_failures(): void {
		$builder = new WorkflowBuilder( 'for_each_quorum', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: ForEachItemStep::class,
				result_key: 'task_results',
				mode: ForEachMode::Quorum,
				quorum: 2,
				reducer_class: ForEachSummaryReducer::class,
				name: 'quorum',
			)
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'f', 'action' => 'fail' ),
						array( 'id' => 's1', 'value' => 'one' ),
						array( 'id' => 's2', 'value' => 'two' ),
					),
				)
			);

		$this->process_one(); // planner
		$this->process_one(); // placeholder
		$this->process_one(); // failure branch

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		$this->process_one(); // success 1
		$this->process_one(); // success 2

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 2, $status->state['task_results']['succeeded'] );
		$this->assertSame( 1, $status->state['task_results']['failed'] );
		$this->assertSame( 2, $status->state['for_each_count'] );
	}

	public function test_impossible_quorum_fails_workflow(): void {
		$builder = new WorkflowBuilder( 'for_each_impossible', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: ForEachItemStep::class,
				result_key: 'task_results',
				mode: ForEachMode::Quorum,
				quorum: 3,
				name: 'must_all_succeed',
			)
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'bad', 'action' => 'fail' ),
						array( 'id' => 'good_1', 'value' => 'one' ),
						array( 'id' => 'good_2', 'value' => 'two' ),
					),
				)
			);

		$this->process_one(); // planner
		$this->process_one(); // placeholder
		$this->process_one(); // first branch fails, quorum becomes impossible

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
	}

	public function test_retry_only_requeues_failed_for_each_branches(): void {
		$builder = new WorkflowBuilder( 'for_each_retry_partial', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( ForEachPlanningStep::class )
			->for_each(
				items_key: 'tasks',
				handler_class: FlakyForEachItemStep::class,
				result_key: 'task_results',
				name: 'retryable_all',
			)
			->then( AccumulatingStep::class )
			->dispatch(
				array(
					'planned_tasks' => array(
						array( 'id' => 'good_1', 'value' => 'one' ),
						array( 'id' => 'good_2', 'value' => 'two' ),
						array( 'id' => 'flaky', 'value' => 'three', 'action' => 'fail_once' ),
					),
				)
			);

		$this->process_one(); // planner
		$this->process_one(); // placeholder
		$this->process_one(); // good branch 1
		$this->process_one(); // good branch 2
		$this->process_one(); // flaky branch fails and workflow fails

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );

		$this->workflow->retry( $wf_id );

		$this->process_one(); // retried placeholder should enqueue only the failed branch

		$retry_branch = $this->queue->claim();
		$this->assertNotNull( $retry_branch );
		$this->assertSame( FlakyForEachItemStep::class, $retry_branch->handler );
		$this->assertSame( 'flaky', $retry_branch->payload['__for_each']['item']['id'] ?? null );
		$this->assertNull( $this->queue->claim() );

		$this->worker->process_job( $retry_branch );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 3, $status->state['task_results']['succeeded'] );

		$next = $this->queue->claim();
		$this->assertNotNull( $next );
		$this->assertSame( AccumulatingStep::class, $next->handler );
		$this->worker->process_job( $next );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}
}
