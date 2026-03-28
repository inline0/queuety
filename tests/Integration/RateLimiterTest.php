<?php
/**
 * Integration tests for RateLimiter.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\PendingJob;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\RateLimiter;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\RateLimitedHandler;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class RateLimiterTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private RateLimiter $rate_limiter;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow     = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry     = new HandlerRegistry();
		$this->rate_limiter = new RateLimiter( $this->conn );
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
			$this->rate_limiter,
		);

		SuccessHandler::reset();
		RateLimitedHandler::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	// -- rate limiter refreshes from DB --------------------------------------

	public function test_rate_limiter_refreshes_from_db(): void {
		$handler_name = RateLimitedHandler::class;
		$this->rate_limiter->register( $handler_name, 2, 60 );

		// Insert completed log entries directly to simulate past executions.
		$this->logger->log(
			LogEvent::Completed,
			array(
				'handler' => $handler_name,
				'queue'   => 'default',
			)
		);
		$this->logger->log(
			LogEvent::Completed,
			array(
				'handler' => $handler_name,
				'queue'   => 'default',
			)
		);

		// After refresh, should be limited (2 completed in the window).
		$this->assertTrue( $this->rate_limiter->is_limited( $handler_name ) );
	}

	// -- worker skips rate-limited handler -----------------------------------

	public function test_worker_skips_rate_limited_handler(): void {
		$handler_name = RateLimitedHandler::class;
		$this->registry->register( 'rate_limited', $handler_name );

		// Dispatch 4 jobs with rate limit of 2 per 60s.
		$this->queue->dispatch( 'rate_limited', array( 'n' => 1 ) );
		$this->queue->dispatch( 'rate_limited', array( 'n' => 2 ) );
		$this->queue->dispatch( 'rate_limited', array( 'n' => 3 ) );
		$this->queue->dispatch( 'rate_limited', array( 'n' => 4 ) );

		$processed = $this->worker->flush();

		// Only 2 should have been processed due to rate limit.
		$this->assertSame( 2, $processed );
		$this->assertCount( 2, RateLimitedHandler::$processed );

		// The remaining 2 jobs should still be pending.
		$stats = $this->queue->stats();
		$this->assertSame( 2, $stats['pending'] );
		$this->assertSame( 2, $stats['completed'] );
	}

	// -- worker reads rate_limit from handler config -------------------------

	public function test_worker_reads_rate_limit_from_handler_config(): void {
		$handler_name = RateLimitedHandler::class;

		// Dispatch 3 jobs - handler config specifies rate_limit [2, 60].
		$this->queue->dispatch( $handler_name, array( 'a' => 1 ) );
		$this->queue->dispatch( $handler_name, array( 'a' => 2 ) );
		$this->queue->dispatch( $handler_name, array( 'a' => 3 ) );

		$processed = $this->worker->flush();

		// Rate limit is [2, 60], so only 2 should process.
		$this->assertSame( 2, $processed );
		$this->assertCount( 2, RateLimitedHandler::$processed );
	}

	// -- PendingJob.rate_limit() registers the limit -------------------------

	public function test_pending_job_rate_limit_registers_with_facade(): void {
		Queuety::init( $this->conn );

		$pending = new PendingJob( 'test_handler', array(), $this->queue );
		$pending->rate_limit( 5, 120 )->id();

		$limiter = Queuety::rate_limiter();

		// Handler should be registered and not yet limited (0 of 5).
		$this->assertFalse( $limiter->is_limited( 'test_handler' ) );

		// Record 5 executions to hit the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->record( 'test_handler' );
		}

		$this->assertTrue( $limiter->is_limited( 'test_handler' ) );
	}

	// -- rate-limited job is unclaimed without wasting attempts ---------------

	public function test_rate_limited_job_is_unclaimed_without_wasting_attempts(): void {
		$handler_name = RateLimitedHandler::class;

		// Pre-register rate limit with max=0 so everything is rate-limited.
		$this->rate_limiter->register( $handler_name, 0, 60 );

		$job_id = $this->queue->dispatch( $handler_name, array( 'x' => 1 ), max_attempts: 3 );

		// Run once so the worker claims and unclaims.
		$this->worker->run( once: true );

		// Job should be back to pending with attempts = 0 (not wasted).
		$job = $this->queue->find( $job_id );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertSame( 0, $job->attempts );
		$this->assertNull( $job->reserved_at );
	}

	// -- worker without rate limiter still works normally ---------------------

	public function test_worker_without_rate_limiter_processes_all(): void {
		$worker_no_limiter = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
		);

		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'a' => 1 ) );
		$this->queue->dispatch( 'success', array( 'b' => 2 ) );

		$processed = $worker_no_limiter->flush();

		$this->assertSame( 2, $processed );
		$this->assertCount( 2, SuccessHandler::$processed );
	}
}
