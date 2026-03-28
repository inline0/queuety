<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\FailHandler;
use Queuety\Tests\Integration\Fixtures\FailingStep;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class WorkerTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
		);

		SuccessHandler::reset();
	}

	// -- process_job: success -----------------------------------------------

	public function test_process_job_with_successful_handler(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$id  = $this->queue->dispatch( 'success', array( 'key' => 'val' ) );
		$job = $this->queue->claim();

		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );
		$this->assertNotNull( $completed->completed_at );
		$this->assertCount( 1, SuccessHandler::$processed );
		$this->assertSame( array( 'key' => 'val' ), SuccessHandler::$processed[0] );
	}

	// -- process_job: failure with retries ----------------------------------

	public function test_process_job_with_failing_handler_retries(): void {
		$id  = $this->queue->dispatch(
			FailHandler::class,
			array( 'message' => 'boom' ),
			max_attempts: 3,
		);
		$job = $this->queue->claim();

		$this->worker->process_job( $job );

		$retried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $retried->status );
	}

	// -- process_job: max attempts exhausted --------------------------------

	public function test_process_job_buries_when_max_attempts_exhausted(): void {
		$id = $this->queue->dispatch(
			FailHandler::class,
			array( 'message' => 'fatal' ),
			max_attempts: 1,
		);
		$job = $this->queue->claim();

		$this->worker->process_job( $job );

		$buried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $buried->status );
		$this->assertSame( 'fatal', $buried->error_message );
	}

	// -- process_job: workflow step -----------------------------------------

	public function test_process_job_with_workflow_step_accumulates_state(): void {
		$builder = new WorkflowBuilder(
			'accumulator',
			$this->conn,
			$this->queue,
			$this->logger,
		);
		$wf_id = $builder
			->then( AccumulatingStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'counter' => 0 ) );

		// Process step 0.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->worker->process_job( $job0 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 1, $state['counter'] );

		// Process step 1.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->worker->process_job( $job1 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 2, $state['counter'] );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	public function test_process_job_workflow_step_failure_marks_workflow_failed(): void {
		$builder = new WorkflowBuilder(
			'failing_wf',
			$this->conn,
			$this->queue,
			$this->logger,
		);
		$wf_id = $builder
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch();

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$wf_status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $wf_status->status );

		$buried = $this->queue->find( $job->id );
		$this->assertSame( JobStatus::Buried, $buried->status );
	}

	// -- flush --------------------------------------------------------------

	public function test_flush_processes_all_pending_jobs(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'a' => 1 ) );
		$this->queue->dispatch( 'success', array( 'b' => 2 ) );
		$this->queue->dispatch( 'success', array( 'c' => 3 ) );

		$count = $this->worker->flush();

		$this->assertSame( 3, $count );
		$this->assertCount( 3, SuccessHandler::$processed );

		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );
		$this->assertSame( 3, $stats['completed'] );
	}

	public function test_flush_returns_zero_on_empty_queue(): void {
		$this->assertSame( 0, $this->worker->flush() );
	}

	// -- run with once=true -------------------------------------------------

	public function test_run_once_processes_one_batch(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success' );

		$this->worker->run( once: true );

		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );
		$this->assertSame( 1, $stats['completed'] );
	}

	public function test_run_once_exits_immediately_when_queue_empty(): void {
		$this->worker->run( once: true );

		// If it exits without hanging, the test passes.
		$this->assertTrue( true );
	}

	// -- recover_stale ------------------------------------------------------

	public function test_recover_stale_retries_stale_jobs(): void {
		$id = $this->queue->dispatch( 'h', max_attempts: 3 );
		$this->queue->claim();

		// Backdate reserved_at to make it stale.
		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ),
			array( 'id' => $id ),
		);

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 1, $recovered );

		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $job->status );
	}

	public function test_recover_stale_buries_when_max_attempts_reached(): void {
		$id = $this->queue->dispatch( 'h', max_attempts: 1 );
		$this->queue->claim();

		// Backdate reserved_at.
		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ),
			array( 'id' => $id ),
		);

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 1, $recovered );

		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $job->status );
		$this->assertStringContainsString( 'Stale', $job->error_message );
	}

	public function test_recover_stale_returns_zero_when_no_stale_jobs(): void {
		$this->assertSame( 0, $this->worker->recover_stale() );
	}
}
