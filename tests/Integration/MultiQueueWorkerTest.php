<?php
/**
 * Integration tests for multi-queue worker priorities.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class MultiQueueWorkerTest extends IntegrationTestCase {

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

		$this->registry->register( 'success', SuccessHandler::class );
		SuccessHandler::reset();
	}

	// -- Multi-queue priority ordering --------------------------------------

	public function test_worker_processes_critical_queue_first(): void {
		// Dispatch to low queue first, then critical.
		$low_id      = $this->queue->dispatch( 'success', array( 'queue' => 'low' ), queue: 'low' );
		$critical_id = $this->queue->dispatch( 'success', array( 'queue' => 'critical' ), queue: 'critical' );

		// Run worker with priority order: critical, default, low.
		$this->worker->run( 'critical,default,low', once: true );

		// Critical should be processed first.
		$critical_job = $this->queue->find( $critical_id );
		$this->assertSame( JobStatus::Completed, $critical_job->status );

		// Low should still be pending (worker ran once).
		$low_job = $this->queue->find( $low_id );
		$this->assertSame( JobStatus::Pending, $low_job->status );

		// Verify the handler saw the critical payload first.
		$this->assertCount( 1, SuccessHandler::$processed );
		$this->assertSame( 'critical', SuccessHandler::$processed[0]['queue'] );
	}

	public function test_worker_falls_through_to_default_when_critical_is_empty(): void {
		// Only dispatch to default queue.
		$default_id = $this->queue->dispatch( 'success', array( 'queue' => 'default' ), queue: 'default' );

		// Run worker with priority order: critical, default.
		$this->worker->run( 'critical,default', once: true );

		// Default should be processed since critical is empty.
		$default_job = $this->queue->find( $default_id );
		$this->assertSame( JobStatus::Completed, $default_job->status );

		$this->assertCount( 1, SuccessHandler::$processed );
		$this->assertSame( 'default', SuccessHandler::$processed[0]['queue'] );
	}

	public function test_worker_processes_all_queues_eventually(): void {
		// Dispatch one job to each queue.
		$critical_id = $this->queue->dispatch( 'success', array( 'queue' => 'critical' ), queue: 'critical' );
		$default_id  = $this->queue->dispatch( 'success', array( 'queue' => 'default' ), queue: 'default' );
		$low_id      = $this->queue->dispatch( 'success', array( 'queue' => 'low' ), queue: 'low' );

		// Flush all queues in priority order.
		$count = $this->worker->flush( 'critical,default,low' );

		$this->assertSame( 3, $count );

		$this->assertSame( JobStatus::Completed, $this->queue->find( $critical_id )->status );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $default_id )->status );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $low_id )->status );

		// Verify processing order: critical first, then default, then low.
		$this->assertCount( 3, SuccessHandler::$processed );
		$this->assertSame( 'critical', SuccessHandler::$processed[0]['queue'] );
		$this->assertSame( 'default', SuccessHandler::$processed[1]['queue'] );
		$this->assertSame( 'low', SuccessHandler::$processed[2]['queue'] );
	}

	public function test_comma_separated_queue_string_is_parsed_correctly(): void {
		// Dispatch one job per queue.
		$this->queue->dispatch( 'success', array( 'queue' => 'alpha' ), queue: 'alpha' );
		$this->queue->dispatch( 'success', array( 'queue' => 'beta' ), queue: 'beta' );

		// Use comma-separated string with spaces.
		$count = $this->worker->flush( 'alpha, beta' );

		$this->assertSame( 2, $count );
		$this->assertCount( 2, SuccessHandler::$processed );
		$this->assertSame( 'alpha', SuccessHandler::$processed[0]['queue'] );
		$this->assertSame( 'beta', SuccessHandler::$processed[1]['queue'] );
	}

	public function test_array_queue_names_accepted(): void {
		$this->queue->dispatch( 'success', array( 'queue' => 'first' ), queue: 'first' );
		$this->queue->dispatch( 'success', array( 'queue' => 'second' ), queue: 'second' );

		$count = $this->worker->flush( array( 'first', 'second' ) );

		$this->assertSame( 2, $count );
		$this->assertCount( 2, SuccessHandler::$processed );
		$this->assertSame( 'first', SuccessHandler::$processed[0]['queue'] );
		$this->assertSame( 'second', SuccessHandler::$processed[1]['queue'] );
	}

	public function test_higher_priority_queue_is_always_drained_first(): void {
		// Dispatch multiple jobs to both queues.
		$this->queue->dispatch( 'success', array( 'order' => 1, 'queue' => 'high' ), queue: 'high' );
		$this->queue->dispatch( 'success', array( 'order' => 2, 'queue' => 'low' ), queue: 'low' );
		$this->queue->dispatch( 'success', array( 'order' => 3, 'queue' => 'high' ), queue: 'high' );

		$count = $this->worker->flush( 'high,low' );

		$this->assertSame( 3, $count );
		$this->assertCount( 3, SuccessHandler::$processed );

		// Both high-priority jobs should be processed before the low one.
		$this->assertSame( 'high', SuccessHandler::$processed[0]['queue'] );
		$this->assertSame( 'high', SuccessHandler::$processed[1]['queue'] );
		$this->assertSame( 'low', SuccessHandler::$processed[2]['queue'] );
	}

	public function test_single_queue_string_still_works(): void {
		$id = $this->queue->dispatch( 'success', array( 'single' => true ), queue: 'default' );

		$count = $this->worker->flush( 'default' );

		$this->assertSame( 1, $count );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $id )->status );
	}
}
