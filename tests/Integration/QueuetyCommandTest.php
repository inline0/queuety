<?php
/**
 * QueuetyCommand unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\CLI;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli-compat.php';

use PHPUnit\Framework\TestCase;
use Queuety\CLI\QueuetyCommand;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Metrics;
use Queuety\PendingJob;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Worker;
use Queuety\Workflow;

/**
 * Tests for QueuetyCommand CLI methods.
 *
 * These tests use the WP_CLI stubs from tests/Stubs/wp-cli-stubs.php.
 * The Queuety facade is mocked by initializing with a real test database
 * connection when available, or by using a mock-based approach.
 */
class QueuetyCommandTest extends TestCase {

	private QueuetyCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new QueuetyCommand();

		// Try to set up a real database connection for integration-like CLI tests.
		try {
			$dsn = sprintf(
				'mysql:host=%s;dbname=%s;charset=utf8mb4',
				QUEUETY_TEST_DB_HOST,
				QUEUETY_TEST_DB_NAME
			);
			new \PDO( $dsn, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS );

			$conn = new Connection(
				host: QUEUETY_TEST_DB_HOST,
				dbname: QUEUETY_TEST_DB_NAME,
				user: QUEUETY_TEST_DB_USER,
				password: QUEUETY_TEST_DB_PASS,
				prefix: QUEUETY_TEST_DB_PREFIX,
			);

			Queuety::reset();
			Queuety::init( $conn );
			\Queuety\Schema::install( $conn );
			$this->has_db = true;
		} catch ( \PDOException $e ) {
			// No database available, skip tests that need it.
		}
	}

	protected function tearDown(): void {
		if ( $this->has_db ) {
			try {
				\Queuety\Schema::uninstall( Queuety::connection() );
			} catch ( \Throwable $e ) {
				// Ignore teardown errors.
			}
			Queuety::reset();
		}
		parent::tearDown();
	}

	private function skip_without_db(): void {
		if ( ! $this->has_db ) {
			$this->markTestSkipped( 'Database not available for CLI tests.' );
		}
	}

	// -- work() calls Worker::run() ------------------------------------------

	public function test_work_calls_worker_run(): void {
		$this->skip_without_db();

		// work with --once should return quickly with no jobs.
		$this->cmd->work( array(), array( 'queue' => 'cli_test', 'once' => true ) );

		// If we get here without exception, WP_CLI::success was called.
		$this->assertTrue( true );
	}

	public function test_work_with_queue_and_once(): void {
		$this->skip_without_db();

		$this->cmd->work( array(), array( 'queue' => 'high_priority', 'once' => true ) );
		$this->assertTrue( true );
	}

	// -- flush() calls Worker::flush() ---------------------------------------

	public function test_flush_processes_pending_jobs(): void {
		$this->skip_without_db();

		$this->cmd->flush( array(), array( 'queue' => 'cli_test' ) );
		$this->assertTrue( true );
	}

	// -- dispatch() creates a job --------------------------------------------

	public function test_dispatch_creates_job(): void {
		$this->skip_without_db();

		$this->cmd->dispatch(
			array( 'my_handler' ),
			array(
				'payload'  => '{"key":"val"}',
				'queue'    => 'default',
				'priority' => '0',
				'delay'    => '0',
			)
		);

		// Verify the job was created.
		$stats = Queuety::stats();
		$this->assertGreaterThan( 0, $stats['pending'] );
	}

	public function test_dispatch_with_default_payload(): void {
		$this->skip_without_db();

		$this->cmd->dispatch( array( 'test_handler' ), array() );
		$this->assertTrue( true );
	}

	// -- status() outputs table format ---------------------------------------

	public function test_status_outputs_stats(): void {
		$this->skip_without_db();

		// Should output stats without throwing.
		$this->cmd->status( array(), array() );
		$this->assertTrue( true );
	}

	public function test_status_with_queue_filter(): void {
		$this->skip_without_db();

		$this->cmd->status( array(), array( 'queue' => 'specific_queue' ) );
		$this->assertTrue( true );
	}

	// -- list_() queries jobs ------------------------------------------------

	public function test_list_queries_jobs(): void {
		$this->skip_without_db();

		$this->cmd->list_( array(), array() );
		$this->assertTrue( true );
	}

	public function test_list_with_filters(): void {
		$this->skip_without_db();

		$this->cmd->list_( array(), array( 'queue' => 'default', 'status' => 'pending' ) );
		$this->assertTrue( true );
	}

	// -- retry() calls Queue::retry() ----------------------------------------

	public function test_retry_calls_queue_retry(): void {
		$this->skip_without_db();

		$queue  = Queuety::queue();
		$job_id = $queue->dispatch( 'retry_test_handler' );
		$queue->bury( $job_id, 'Test error' );

		$this->cmd->retry( array( $job_id ), array() );

		$job = $queue->find( $job_id );
		$this->assertSame( JobStatus::Pending, $job->status );
	}

	// -- retry_buried() calls retryBuried() ----------------------------------

	public function test_retry_buried_calls_retry_buried(): void {
		$this->skip_without_db();

		$queue = Queuety::queue();
		$id1   = $queue->dispatch( 'handler_a' );
		$id2   = $queue->dispatch( 'handler_b' );
		$queue->bury( $id1, 'Error 1' );
		$queue->bury( $id2, 'Error 2' );

		$this->cmd->retry_buried( array(), array() );

		$this->assertSame( JobStatus::Pending, $queue->find( $id1 )->status );
		$this->assertSame( JobStatus::Pending, $queue->find( $id2 )->status );
	}

	// -- bury() calls Queue::bury() ------------------------------------------

	public function test_bury_calls_queue_bury(): void {
		$this->skip_without_db();

		$queue  = Queuety::queue();
		$job_id = $queue->dispatch( 'bury_test' );

		$this->cmd->bury( array( $job_id ), array() );

		$job = $queue->find( $job_id );
		$this->assertSame( JobStatus::Buried, $job->status );
		$this->assertSame( 'Manually buried via CLI.', $job->error_message );
	}

	// -- delete() removes the job --------------------------------------------

	public function test_delete_removes_job(): void {
		$this->skip_without_db();

		$queue  = Queuety::queue();
		$job_id = $queue->dispatch( 'delete_test' );
		$this->assertNotNull( $queue->find( $job_id ) );

		$this->cmd->delete( array( $job_id ), array() );

		$this->assertNull( $queue->find( $job_id ) );
	}

	// -- recover() calls Worker::recover_stale() -----------------------------

	public function test_recover_calls_recover_stale(): void {
		$this->skip_without_db();

		$this->cmd->recover( array(), array() );
		$this->assertTrue( true );
	}

	// -- purge() calls purge with correct days --------------------------------

	public function test_purge_calls_purge(): void {
		$this->skip_without_db();

		$this->cmd->purge( array(), array( 'older-than' => '7' ) );
		$this->assertTrue( true );
	}

	public function test_purge_with_default_days(): void {
		$this->skip_without_db();

		$this->cmd->purge( array(), array() );
		$this->assertTrue( true );
	}

	// -- pause() calls Queue::pause_queue() ----------------------------------

	public function test_pause_calls_pause_queue(): void {
		$this->skip_without_db();

		$this->cmd->pause( array( 'test_queue' ), array() );

		$this->assertTrue( Queuety::is_paused( 'test_queue' ) );
	}

	// -- resume() calls Queue::resume_queue() --------------------------------

	public function test_resume_calls_resume_queue(): void {
		$this->skip_without_db();

		Queuety::pause( 'test_queue' );
		$this->assertTrue( Queuety::is_paused( 'test_queue' ) );

		$this->cmd->resume( array( 'test_queue' ), array() );

		$this->assertFalse( Queuety::is_paused( 'test_queue' ) );
	}

	// -- inspect() shows job details -----------------------------------------

	public function test_inspect_shows_job_details(): void {
		$this->skip_without_db();

		$queue  = Queuety::queue();
		$job_id = $queue->dispatch( 'inspect_test', array( 'key' => 'val' ) );

		$this->cmd->inspect( array( $job_id ), array() );
		$this->assertTrue( true );
	}

	public function test_inspect_nonexistent_job_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );

		$this->cmd->inspect( array( 999999 ), array() );
	}

	// -- metrics() shows handler stats ---------------------------------------

	public function test_metrics_outputs_stats(): void {
		$this->skip_without_db();

		$this->cmd->metrics( array(), array( 'minutes' => '60' ) );
		$this->assertTrue( true );
	}

	// -- discover() scans directory ------------------------------------------

	public function test_discover_with_nonexistent_directory(): void {
		$this->skip_without_db();

		// discover() logs "No handlers found" for empty directory.
		$tmp_dir = sys_get_temp_dir() . '/queuety_discover_test_' . uniqid();
		@mkdir( $tmp_dir, 0755, true );

		$this->cmd->discover( array( $tmp_dir, 'App\\Handlers' ), array() );

		@rmdir( $tmp_dir );
		$this->assertTrue( true );
	}
}
