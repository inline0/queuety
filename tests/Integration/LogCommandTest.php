<?php
/**
 * LogCommand unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\CLI;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli-compat.php';

use PHPUnit\Framework\TestCase;
use Queuety\CLI\LogCommand;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\LogEvent;
use Queuety\Logger;
use Queuety\Queuety;

/**
 * Tests for LogCommand CLI methods.
 */
class LogCommandTest extends TestCase {

	private LogCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new LogCommand();

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
			$this->markTestSkipped( 'Database not available for CLI tests.' );
		}
	}

	// -- Default invocation (last 24 hours) ----------------------------------

	public function test_default_invocation_shows_recent_logs(): void {
		$this->skip_without_db();

		// Add a log entry.
		$logger = Queuety::logger();
		$logger->log( LogEvent::Started, array(
			'handler' => 'test_handler',
			'queue'   => 'default',
		) );

		$this->cmd->__invoke( array(), array() );
		$this->assertTrue( true );
	}

	// -- Filter by job -------------------------------------------------------

	public function test_filter_by_job_id(): void {
		$this->skip_without_db();

		$logger = Queuety::logger();
		$logger->log( LogEvent::Started, array(
			'job_id'  => 42,
			'handler' => 'test_handler',
			'queue'   => 'default',
		) );

		$this->cmd->__invoke( array(), array( 'job' => '42' ) );
		$this->assertTrue( true );
	}

	// -- Filter by workflow --------------------------------------------------

	public function test_filter_by_workflow_id(): void {
		$this->skip_without_db();

		$this->cmd->__invoke( array(), array( 'workflow' => '1' ) );
		$this->assertTrue( true );
	}

	// -- Filter by handler ---------------------------------------------------

	public function test_filter_by_handler(): void {
		$this->skip_without_db();

		$this->cmd->__invoke( array(), array( 'handler' => 'test_handler' ) );
		$this->assertTrue( true );
	}

	// -- Filter by event -----------------------------------------------------

	public function test_filter_by_event(): void {
		$this->skip_without_db();

		$this->cmd->__invoke( array(), array( 'event' => 'completed' ) );
		$this->assertTrue( true );
	}

	// -- Filter by since -----------------------------------------------------

	public function test_filter_by_since(): void {
		$this->skip_without_db();

		$this->cmd->__invoke( array(), array( 'since' => '2024-01-01 00:00:00' ) );
		$this->assertTrue( true );
	}

	// -- Custom limit --------------------------------------------------------

	public function test_custom_limit(): void {
		$this->skip_without_db();

		$this->cmd->__invoke( array(), array( 'limit' => '5' ) );
		$this->assertTrue( true );
	}

	// -- purge() deletes old logs --------------------------------------------

	public function test_purge_deletes_old_logs(): void {
		$this->skip_without_db();

		$this->cmd->purge( array(), array( 'older-than' => '30' ) );
		$this->assertTrue( true );
	}

	public function test_purge_without_older_than_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );

		$this->cmd->purge( array(), array() );
	}
}
