<?php
/**
 * WorkerPool integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Schema;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkerPool;
use Queuety\Tests\IntegrationTestCase;

/**
 * Tests for WorkerPool multi-process functionality.
 *
 * These tests require the pcntl extension and are skipped if not available.
 */
class WorkerPoolTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension is required for WorkerPool tests.' );
		}

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	// -- Constructor validation ----------------------------------------------

	public function test_pool_requires_pcntl(): void {
		// If we get here, pcntl IS available (otherwise test is skipped).
		$pool = new WorkerPool(
			1,
			QUEUETY_TEST_DB_HOST,
			QUEUETY_TEST_DB_NAME,
			QUEUETY_TEST_DB_USER,
			QUEUETY_TEST_DB_PASS,
			QUEUETY_TEST_DB_PREFIX,
		);
		$this->assertInstanceOf( WorkerPool::class, $pool );
	}

	public function test_pool_rejects_zero_workers(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Worker count must be between 1 and 32' );

		new WorkerPool(
			0,
			QUEUETY_TEST_DB_HOST,
			QUEUETY_TEST_DB_NAME,
			QUEUETY_TEST_DB_USER,
			QUEUETY_TEST_DB_PASS,
			QUEUETY_TEST_DB_PREFIX,
		);
	}

	public function test_pool_rejects_too_many_workers(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Worker count must be between 1 and 32' );

		new WorkerPool(
			33,
			QUEUETY_TEST_DB_HOST,
			QUEUETY_TEST_DB_NAME,
			QUEUETY_TEST_DB_USER,
			QUEUETY_TEST_DB_PASS,
			QUEUETY_TEST_DB_PREFIX,
		);
	}

	// -- Fork one worker and verify processing -------------------------------

	public function test_fork_worker_processes_job(): void {
		// Register a handler that writes a marker file.
		$marker_file = $this->tmp_dir . '/pool_marker_' . uniqid();
		Queuety::register( 'pool_test_handler', WorkerPoolTestHandler::class );

		// Dispatch a job with the marker file path in the payload.
		$queue = Queuety::queue();
		$queue->dispatch( WorkerPoolTestHandler::class, array( 'marker' => $marker_file ) );

		// Fork a single child worker that processes once.
		$pid = pcntl_fork();

		if ( 0 === $pid ) {
			// Child process: create a fresh connection and run worker once.
			$conn     = new Connection(
				QUEUETY_TEST_DB_HOST,
				QUEUETY_TEST_DB_NAME,
				QUEUETY_TEST_DB_USER,
				QUEUETY_TEST_DB_PASS,
				QUEUETY_TEST_DB_PREFIX,
			);
			Schema::install( $conn );
			$queue_op = new Queue( $conn );
			$logger   = new Logger( $conn );
			$workflow = new Workflow( $conn, $queue_op, $logger );
			$registry = new HandlerRegistry();
			$registry->register( WorkerPoolTestHandler::class, WorkerPoolTestHandler::class );
			$worker = new Worker( $conn, $queue_op, $logger, $workflow, $registry, new Config() );
			$worker->run( 'default', true );
			exit( 0 );
		}

		// Parent: wait for child.
		pcntl_waitpid( $pid, $status, 0 );

		// Give the marker file a moment to be written.
		$this->assertTrue(
			file_exists( $marker_file ),
			'Marker file should exist after worker processes the job.'
		);

		// Clean up marker.
		@unlink( $marker_file );
	}

	// -- Graceful shutdown on SIGTERM ----------------------------------------

	public function test_graceful_shutdown_on_sigterm(): void {
		// Fork a child that just installs a signal handler and sleeps.
		$pid = pcntl_fork();

		if ( 0 === $pid ) {
			pcntl_async_signals( true );
			$stopped = false;
			pcntl_signal( SIGTERM, function () use ( &$stopped ) {
				$stopped = true;
			} );

			// Loop until SIGTERM received (max 5 seconds).
			$start = time();
			while ( ! $stopped && ( time() - $start ) < 5 ) {
				usleep( 100_000 );
			}
			exit( $stopped ? 0 : 1 );
		}

		// Parent: wait briefly, then send SIGTERM.
		usleep( 300_000 );
		posix_kill( $pid, SIGTERM );

		// Wait for the child with a timeout.
		$deadline = time() + 5;
		$result   = 0;
		while ( time() < $deadline ) {
			$result = pcntl_waitpid( $pid, $status, WNOHANG );
			if ( $result > 0 ) {
				break;
			}
			usleep( 100_000 );
		}

		if ( 0 === $result ) {
			posix_kill( $pid, SIGKILL );
			pcntl_waitpid( $pid, $status );
			$this->fail( 'Child did not exit within timeout after SIGTERM.' );
		}

		$this->assertTrue( pcntl_wifexited( $status ) );
		$this->assertSame( 0, pcntl_wexitstatus( $status ) );
	}
}

/**
 * Test handler that writes a marker file.
 */
class WorkerPoolTestHandler implements \Queuety\Handler {

	public function handle( array $payload ): void {
		if ( isset( $payload['marker'] ) ) {
			file_put_contents( $payload['marker'], 'processed' );
		}
	}

	public function config(): array {
		return array();
	}
}
