<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;

class StaleRecoveryTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		$registry       = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$registry,
			new Config(),
		);
	}

	public function test_stale_job_gets_retried(): void {
		$id = $this->queue->dispatch( 'handler_a', max_attempts: 3 );
		$this->queue->claim();

		// Simulate a dead worker by backdating reserved_at.
		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ),
			array( 'id' => $id ),
		);

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 1, $recovered );

		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertNull( $job->reserved_at );

		// A retry log entry should exist.
		$logs = $this->logger->for_job( $id );
		$events = array_column( $logs, 'event' );
		$this->assertContains( 'retried', $events );
	}

	public function test_stale_job_gets_buried_at_max_attempts(): void {
		$id = $this->queue->dispatch( 'handler_b', max_attempts: 1 );
		$this->queue->claim();

		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ),
			array( 'id' => $id ),
		);

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 1, $recovered );

		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $job->status );
		$this->assertStringContainsString( 'Stale', $job->error_message );

		$logs = $this->logger->for_job( $id );
		$events = array_column( $logs, 'event' );
		$this->assertContains( 'buried', $events );
	}

	public function test_multiple_stale_jobs_are_all_recovered(): void {
		$id1 = $this->queue->dispatch( 'h', max_attempts: 3 );
		$id2 = $this->queue->dispatch( 'h', max_attempts: 3 );
		$id3 = $this->queue->dispatch( 'h', max_attempts: 1 );

		$this->queue->claim();
		$this->queue->claim();
		$this->queue->claim();

		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 900 );
		$this->raw_update( 'queuety_jobs', array( 'reserved_at' => $stale_time ), array( 'id' => $id1 ) );
		$this->raw_update( 'queuety_jobs', array( 'reserved_at' => $stale_time ), array( 'id' => $id2 ) );
		$this->raw_update( 'queuety_jobs', array( 'reserved_at' => $stale_time ), array( 'id' => $id3 ) );

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 3, $recovered );

		// id1 and id2 should be retried (pending).
		$this->assertSame( JobStatus::Pending, $this->queue->find( $id1 )->status );
		$this->assertSame( JobStatus::Pending, $this->queue->find( $id2 )->status );

		// id3 should be buried (max_attempts = 1, and claim set attempts to 1).
		$this->assertSame( JobStatus::Buried, $this->queue->find( $id3 )->status );
	}

	public function test_non_stale_processing_jobs_are_not_recovered(): void {
		$id = $this->queue->dispatch( 'h', max_attempts: 3 );
		$this->queue->claim();

		// reserved_at is recent, so it should not be stale.
		$recovered = $this->worker->recover_stale();

		$this->assertSame( 0, $recovered );

		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Processing, $job->status );
	}

	public function test_stale_workflow_step_marks_workflow_failed_when_buried(): void {
		$builder = new WorkflowBuilder( 'stale_wf', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder->then( 'StepA' )->then( 'StepB' )->max_attempts( 1 )->dispatch();

		$job = $this->queue->claim();
		$this->assertSame( $wf_id, $job->workflow_id );

		// Simulate worker death.
		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ),
			array( 'id' => $job->id ),
		);

		$this->worker->recover_stale();

		$wf_status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $wf_status->status );

		$buried_job = $this->queue->find( $job->id );
		$this->assertSame( JobStatus::Buried, $buried_job->status );
	}

	public function test_stale_workflow_step_retried_does_not_fail_workflow(): void {
		$builder = new WorkflowBuilder( 'stale_wf2', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder->then( 'StepA' )->then( 'StepB' )->max_attempts( 3 )->dispatch();

		$job = $this->queue->claim();

		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ),
			array( 'id' => $job->id ),
		);

		$this->worker->recover_stale();

		$wf_status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $wf_status->status );

		$retried_job = $this->queue->find( $job->id );
		$this->assertSame( JobStatus::Pending, $retried_job->status );
	}

	public function test_recover_stale_ignores_completed_and_pending_jobs(): void {
		$completed_id = $this->queue->dispatch( 'h' );
		$this->queue->complete( $completed_id );

		$pending_id = $this->queue->dispatch( 'h' );

		$recovered = $this->worker->recover_stale();

		$this->assertSame( 0, $recovered );
	}
}
