<?php
/**
 * dispatch_sync integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Contracts\Job as JobContract;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;

/**
 * Tests for Queuety::dispatch_sync().
 */
class DispatchSyncTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		Queuety::reset();
		Queuety::init( $this->conn );
		DispatchSyncTestJob::reset();
		DispatchSyncTestHandler::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	// -- Job contract instance -----------------------------------------------

	public function test_dispatch_sync_with_job_instance_executes_immediately(): void {
		$job = new DispatchSyncTestJob( 'hello@example.com', 'Test subject' );

		Queuety::dispatch_sync( $job );

		$this->assertCount( 1, DispatchSyncTestJob::$processed );
		$this->assertSame( 'hello@example.com', DispatchSyncTestJob::$processed[0]['to'] );
		$this->assertSame( 'Test subject', DispatchSyncTestJob::$processed[0]['subject'] );
	}

	// -- String handler with payload -----------------------------------------

	public function test_dispatch_sync_with_string_handler_executes_immediately(): void {
		Queuety::dispatch_sync( DispatchSyncTestJob::class, array(
			'to'      => 'user@test.com',
			'subject' => 'Sync test',
		) );

		$this->assertCount( 1, DispatchSyncTestJob::$processed );
		$this->assertSame( 'user@test.com', DispatchSyncTestJob::$processed[0]['to'] );
	}

	// -- No database row created ---------------------------------------------

	public function test_dispatch_sync_creates_no_database_row(): void {
		$stats_before = Queuety::stats();
		$total_before = array_sum( $stats_before );

		Queuety::dispatch_sync( new DispatchSyncTestJob( 'no-db@test.com', 'No DB' ) );

		$stats_after = Queuety::stats();
		$total_after = array_sum( $stats_after );

		$this->assertSame( $total_before, $total_after );
	}

	// -- Handle method receives correct data ---------------------------------

	public function test_job_handle_receives_correct_data(): void {
		$job = new DispatchSyncTestJob( 'data@test.com', 'Data test' );

		Queuety::dispatch_sync( $job );

		$this->assertSame( 'data@test.com', DispatchSyncTestJob::$processed[0]['to'] );
		$this->assertSame( 'Data test', DispatchSyncTestJob::$processed[0]['subject'] );
	}

	// -- Exceptions propagate ------------------------------------------------

	public function test_dispatch_sync_exception_propagates(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Sync job failed' );

		Queuety::dispatch_sync( new DispatchSyncTestFailingJob() );
	}

	// -- Fallback to registry-based handler ----------------------------------

	public function test_dispatch_sync_with_registered_handler(): void {
		Queuety::register( 'sync_handler', DispatchSyncTestHandler::class );

		Queuety::dispatch_sync( 'sync_handler', array( 'action' => 'test' ) );

		$this->assertCount( 1, DispatchSyncTestHandler::$processed );
		$this->assertSame( array( 'action' => 'test' ), DispatchSyncTestHandler::$processed[0] );
	}
}

/**
 * Test job for dispatch_sync.
 */
class DispatchSyncTestJob implements JobContract {

	public static array $processed = array();

	public function __construct(
		public readonly string $to,
		public readonly string $subject,
	) {}

	public function handle(): void {
		self::$processed[] = array(
			'to'      => $this->to,
			'subject' => $this->subject,
		);
	}

	public static function reset(): void {
		self::$processed = array();
	}
}

/**
 * Test job that always throws.
 */
class DispatchSyncTestFailingJob implements JobContract {

	public function handle(): void {
		throw new \RuntimeException( 'Sync job failed' );
	}
}

/**
 * Test handler for dispatch_sync fallback.
 */
class DispatchSyncTestHandler implements Handler {

	public static array $processed = array();

	public function handle( array $payload ): void {
		self::$processed[] = $payload;
	}

	public function config(): array {
		return array();
	}

	public static function reset(): void {
		self::$processed = array();
	}
}
