<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Exceptions\TimeoutException;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SlowHandler;

class JobTimeoutTest extends IntegrationTestCase {

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
	}

	public function test_timeout_exception_can_be_constructed(): void {
		$exception = new TimeoutException( 300 );

		$this->assertInstanceOf( \RuntimeException::class, $exception );
		$this->assertStringContainsString( '300', $exception->getMessage() );
		$this->assertStringContainsString( 'maximum execution time', $exception->getMessage() );
	}

	public function test_timeout_exception_includes_seconds(): void {
		$exception = new TimeoutException( 60 );

		$this->assertSame(
			'Job exceeded maximum execution time of 60 seconds.',
			$exception->getMessage()
		);
	}

	public function test_timeout_handling_with_pcntl_available(): void {
		if ( ! function_exists( 'pcntl_alarm' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			$this->markTestSkipped( 'pcntl extension is not available.' );
		}

		// Enable async signal dispatch so SIGALRM interrupts sleep().
		$prev_async = pcntl_async_signals( true );

		// Override the max execution time for this test.
		if ( ! defined( 'QUEUETY_MAX_EXECUTION_TIME' ) ) {
			define( 'QUEUETY_MAX_EXECUTION_TIME', 1 );
		}

		try {
			$this->registry->register( 'slow', SlowHandler::class );
			$id  = $this->queue->dispatch( 'slow', max_attempts: 1 );
			$job = $this->queue->claim();

			$this->worker->process_job( $job );

			$result = $this->queue->find( $id );

			// The job should be buried since max_attempts=1 and it timed out.
			$this->assertSame( JobStatus::Buried, $result->status );
			$this->assertStringContainsString( 'maximum execution time', $result->error_message );
		} finally {
			pcntl_async_signals( $prev_async );
		}
	}

	public function test_timeout_with_retries_schedules_retry(): void {
		if ( ! function_exists( 'pcntl_alarm' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			$this->markTestSkipped( 'pcntl extension is not available.' );
		}

		// Enable async signal dispatch so SIGALRM interrupts sleep().
		$prev_async = pcntl_async_signals( true );

		// Ensure the constant is defined (may already be from previous test).
		if ( ! defined( 'QUEUETY_MAX_EXECUTION_TIME' ) ) {
			define( 'QUEUETY_MAX_EXECUTION_TIME', 1 );
		}

		try {
			$this->registry->register( 'slow', SlowHandler::class );
			$id  = $this->queue->dispatch( 'slow', max_attempts: 3 );
			$job = $this->queue->claim();

			$this->worker->process_job( $job );

			$result = $this->queue->find( $id );

			// With max_attempts=3 and attempts=1, it should be retried (pending).
			$this->assertSame( JobStatus::Pending, $result->status );
		} finally {
			pcntl_async_signals( $prev_async );
		}
	}
}
