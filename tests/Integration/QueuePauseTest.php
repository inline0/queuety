<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class QueuePauseTest extends IntegrationTestCase {

	private Queue $queue;
	private Worker $worker;
	private HandlerRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$logger         = new Logger( $this->conn );
		$workflow       = new Workflow( $this->conn, $this->queue, $logger );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$logger,
			$workflow,
			$this->registry,
			new Config(),
		);

		SuccessHandler::reset();
	}

	public function test_pause_queue_makes_it_paused(): void {
		$this->assertFalse( $this->queue->is_queue_paused( 'default' ) );

		$this->queue->pause_queue( 'default' );

		$this->assertTrue( $this->queue->is_queue_paused( 'default' ) );
	}

	public function test_resume_queue_unpauses_it(): void {
		$this->queue->pause_queue( 'default' );
		$this->assertTrue( $this->queue->is_queue_paused( 'default' ) );

		$this->queue->resume_queue( 'default' );

		$this->assertFalse( $this->queue->is_queue_paused( 'default' ) );
	}

	public function test_is_queue_paused_returns_false_for_unknown_queue(): void {
		$this->assertFalse( $this->queue->is_queue_paused( 'nonexistent' ) );
	}

	public function test_paused_queues_lists_all_paused(): void {
		$this->queue->pause_queue( 'emails' );
		$this->queue->pause_queue( 'reports' );

		$paused = $this->queue->paused_queues();

		$this->assertContains( 'emails', $paused );
		$this->assertContains( 'reports', $paused );
		$this->assertCount( 2, $paused );
	}

	public function test_paused_queues_excludes_resumed(): void {
		$this->queue->pause_queue( 'emails' );
		$this->queue->pause_queue( 'reports' );
		$this->queue->resume_queue( 'emails' );

		$paused = $this->queue->paused_queues();

		$this->assertNotContains( 'emails', $paused );
		$this->assertContains( 'reports', $paused );
	}

	public function test_pause_is_idempotent(): void {
		$this->queue->pause_queue( 'default' );
		$this->queue->pause_queue( 'default' );

		$this->assertTrue( $this->queue->is_queue_paused( 'default' ) );
	}

	public function test_resume_is_idempotent(): void {
		$this->queue->resume_queue( 'nonexistent' );

		$this->assertFalse( $this->queue->is_queue_paused( 'nonexistent' ) );
	}

	public function test_worker_flush_skips_paused_queue(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'data' => 1 ) );

		$this->queue->pause_queue( 'default' );

		$count = $this->worker->flush();

		$this->assertSame( 0, $count );
		$this->assertCount( 0, SuccessHandler::$processed );

		$stats = $this->queue->stats();
		$this->assertSame( 1, $stats['pending'] );
	}

	public function test_resume_allows_processing(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'data' => 1 ) );

		$this->queue->pause_queue( 'default' );
		$this->assertSame( 0, $this->worker->flush() );

		$this->queue->resume_queue( 'default' );
		$count = $this->worker->flush();

		$this->assertSame( 1, $count );
		$this->assertCount( 1, SuccessHandler::$processed );
	}

	public function test_pause_only_affects_target_queue(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'a' => 1 ), queue: 'emails' );
		$this->queue->dispatch( 'success', array( 'b' => 2 ), queue: 'default' );

		$this->queue->pause_queue( 'emails' );

		// Flushing the default queue should still work.
		$count = $this->worker->flush( 'default' );
		$this->assertSame( 1, $count );

		// Flushing the paused emails queue should skip.
		$count = $this->worker->flush( 'emails' );
		$this->assertSame( 0, $count );
	}
}
