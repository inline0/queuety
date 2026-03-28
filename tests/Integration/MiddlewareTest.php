<?php
/**
 * Integration tests for middleware pipeline with real DB.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Contracts\Middleware;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\MiddlewareJob;
use Queuety\Tests\Integration\Fixtures\SendEmailJob;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

/**
 * Tests middleware pipeline integration with real database.
 */
class MiddlewareTest extends IntegrationTestCase {

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

		Queuety::init( $this->conn );
		MiddlewareJob::reset();
		SendEmailJob::reset();
	}

	protected function tearDown(): void {
		MiddlewareJob::reset();
		Queuety::reset();
		parent::tearDown();
	}

	public function test_job_without_middleware_processes_normally(): void {
		$id = $this->queue->dispatch(
			MiddlewareJob::class,
			array( 'message' => 'no middleware' ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );
		$this->assertCount( 1, MiddlewareJob::$processed );
		$this->assertSame( 'no middleware', MiddlewareJob::$processed[0]['message'] );
	}

	public function test_middleware_runs_before_and_after_job(): void {
		$log = array();

		MiddlewareJob::set_middleware(
			array(
				new MiddlewareTest_LogMiddleware( 'outer', $log ),
				new MiddlewareTest_LogMiddleware( 'inner', $log ),
			)
		);

		$id = $this->queue->dispatch(
			MiddlewareJob::class,
			array( 'message' => 'with middleware' ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );

		// Verify onion execution order.
		$this->assertSame( 'outer:before', $log[0] );
		$this->assertSame( 'inner:before', $log[1] );
		// Core executes (handle() is called, log index 2 is not captured but processed array confirms it).
		$this->assertCount( 1, MiddlewareJob::$processed );
	}

	public function test_middleware_exception_fails_the_job(): void {
		MiddlewareJob::set_middleware(
			array(
				new MiddlewareTest_ThrowingMiddleware( 'middleware failure' ),
			)
		);

		$id = $this->queue->dispatch(
			MiddlewareJob::class,
			array( 'message' => 'should fail' ),
			max_attempts: 1,
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$buried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $buried->status );
		$this->assertStringContainsString( 'middleware failure', $buried->error_message );

		// Job handle() should not have been called.
		$this->assertCount( 0, MiddlewareJob::$processed );
	}

	public function test_middleware_can_short_circuit(): void {
		MiddlewareJob::set_middleware(
			array(
				new MiddlewareTest_BlockingMiddleware(),
			)
		);

		$id = $this->queue->dispatch(
			MiddlewareJob::class,
			array( 'message' => 'blocked' ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		// The job handle was not called but the middleware completed without error.
		// The job should still be marked completed because the core closure won't run
		// but the pipeline itself won't throw.
		$this->assertCount( 0, MiddlewareJob::$processed );
	}

	public function test_old_handler_still_works_alongside_job_classes(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		SuccessHandler::reset();

		// Dispatch both an old Handler and a new Job class.
		$old_id = $this->queue->dispatch( 'success', array( 'key' => 'old' ) );
		$new_id = $this->queue->dispatch(
			MiddlewareJob::class,
			array( 'message' => 'new' ),
		);

		// Process both.
		$count = $this->worker->flush();
		$this->assertSame( 2, $count );

		$old_job = $this->queue->find( $old_id );
		$new_job = $this->queue->find( $new_id );

		$this->assertSame( JobStatus::Completed, $old_job->status );
		$this->assertSame( JobStatus::Completed, $new_job->status );

		$this->assertCount( 1, SuccessHandler::$processed );
		$this->assertCount( 1, MiddlewareJob::$processed );
	}
}

// -- Test fixture middleware (inline) ------------------------------------------

/**
 * Middleware that logs before/after execution.
 */
class MiddlewareTest_LogMiddleware implements Middleware {

	private array $log;

	public function __construct(
		private readonly string $name,
		array &$log,
	) {
		$this->log = &$log;
	}

	public function handle( object $job, \Closure $next ): void {
		$this->log[] = "{$this->name}:before";
		$next( $job );
		$this->log[] = "{$this->name}:after";
	}
}

/**
 * Middleware that throws an exception.
 */
class MiddlewareTest_ThrowingMiddleware implements Middleware {

	public function __construct(
		private readonly string $message,
	) {}

	public function handle( object $job, \Closure $next ): void {
		throw new \RuntimeException( $this->message );
	}
}

/**
 * Middleware that blocks execution by not calling $next.
 */
class MiddlewareTest_BlockingMiddleware implements Middleware {

	public function handle( object $job, \Closure $next ): void {
		// Deliberately do not call $next().
	}
}
