<?php
/**
 * Dispatchable trait unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Queuety\ChainBuilder;
use Queuety\Connection;
use Queuety\Contracts\Job as JobContract;
use Queuety\Dispatchable;
use Queuety\PendingJob;
use Queuety\Queuety;

/**
 * Tests for the Dispatchable trait.
 */
class DispatchableTest extends TestCase {

	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

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
			// No database.
		}
	}

	protected function tearDown(): void {
		if ( $this->has_db ) {
			try {
				\Queuety\Schema::uninstall( Queuety::connection() );
			} catch ( \Throwable $e ) {
				// Ignore.
			}
			Queuety::reset();
		}
		parent::tearDown();
	}

	private function skip_without_db(): void {
		if ( ! $this->has_db ) {
			$this->markTestSkipped( 'Database not available for Dispatchable tests.' );
		}
	}

	// -- dispatch_if(true) returns PendingJob --------------------------------

	public function test_dispatch_if_true_returns_pending_job(): void {
		$this->skip_without_db();

		$result = DispatchableTestJob::dispatch_if( true, 'test_label' );
		$this->assertInstanceOf( PendingJob::class, $result );
	}

	// -- dispatch_if(false) returns null -------------------------------------

	public function test_dispatch_if_false_returns_null(): void {
		$this->skip_without_db();

		$result = DispatchableTestJob::dispatch_if( false, 'test_label' );
		$this->assertNull( $result );
	}

	// -- dispatch_unless(true) returns null ----------------------------------

	public function test_dispatch_unless_true_returns_null(): void {
		$this->skip_without_db();

		$result = DispatchableTestJob::dispatch_unless( true, 'test_label' );
		$this->assertNull( $result );
	}

	// -- dispatch_unless(false) returns PendingJob ---------------------------

	public function test_dispatch_unless_false_returns_pending_job(): void {
		$this->skip_without_db();

		$result = DispatchableTestJob::dispatch_unless( false, 'test_label' );
		$this->assertInstanceOf( PendingJob::class, $result );
	}

	// -- with_chain() returns ChainBuilder -----------------------------------

	public function test_with_chain_returns_chain_builder(): void {
		$this->skip_without_db();

		$chain = DispatchableTestJob::with_chain( array(
			new DispatchableTestJob( 'step1' ),
			new DispatchableTestJob( 'step2' ),
		) );
		$this->assertInstanceOf( ChainBuilder::class, $chain );
	}

	// -- dispatch() returns PendingJob ---------------------------------------

	public function test_dispatch_returns_pending_job(): void {
		$this->skip_without_db();

		$result = DispatchableTestJob::dispatch( 'dispatched_label' );
		$this->assertInstanceOf( PendingJob::class, $result );
	}

	// -- dispatch() creates job in database ----------------------------------

	public function test_dispatch_creates_job_in_database(): void {
		$this->skip_without_db();

		$pending = DispatchableTestJob::dispatch( 'db_check' );
		$id      = $pending->id();

		$job = Queuety::queue()->find( $id );
		$this->assertNotNull( $job );
		$this->assertSame( DispatchableTestJob::class, $job->handler );
		$this->assertSame( 'db_check', $job->payload['label'] );
	}
}

/**
 * Test job class with Dispatchable trait.
 */
class DispatchableTestJob implements JobContract {

	use Dispatchable;

	public static array $log = array();

	public function __construct(
		public readonly string $label = 'default',
	) {}

	public function handle(): void {
		self::$log[] = $this->label;
	}
}
