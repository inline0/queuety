<?php
/**
 * ScheduleCommand unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\CLI;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli-compat.php';

use PHPUnit\Framework\TestCase;
use Queuety\CLI\ScheduleCommand;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Queuety;

/**
 * Tests for ScheduleCommand CLI methods.
 */
class ScheduleCommandTest extends TestCase {

	private ScheduleCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new ScheduleCommand();

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

	// -- list_() lists schedules ---------------------------------------------

	public function test_list_schedules(): void {
		$this->skip_without_db();

		$this->cmd->list_( array(), array() );
		$this->assertTrue( true );
	}

	// -- add() with --every --------------------------------------------------

	public function test_add_schedule_with_every(): void {
		$this->skip_without_db();

		$this->cmd->add( array( 'my_handler' ), array(
			'every' => '30 minutes',
			'queue' => 'default',
		) );

		$schedules = Queuety::scheduler()->list();
		$found     = false;
		foreach ( $schedules as $s ) {
			if ( $s->handler === 'my_handler' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Schedule should be registered for my_handler.' );
	}

	// -- add() with --cron ---------------------------------------------------

	public function test_add_schedule_with_cron(): void {
		$this->skip_without_db();

		$this->cmd->add( array( 'cron_handler' ), array(
			'cron'  => '0 3 * * *',
			'queue' => 'default',
		) );

		$schedules = Queuety::scheduler()->list();
		$found     = false;
		foreach ( $schedules as $s ) {
			if ( $s->handler === 'cron_handler' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Schedule should be registered for cron_handler.' );
	}

	// -- add() without expression throws -------------------------------------

	public function test_add_without_expression_throws(): void {
		$this->skip_without_db();

		$this->expectException( \RuntimeException::class );

		$this->cmd->add( array( 'no_expr_handler' ), array() );
	}

	// -- remove() removes a schedule -----------------------------------------

	public function test_remove_schedule(): void {
		$this->skip_without_db();

		$this->cmd->add( array( 'removable_handler' ), array(
			'every' => '1 hour',
		) );

		$this->cmd->remove( array( 'removable_handler' ), array() );

		// Verify schedule was removed.
		$schedule = Queuety::scheduler()->find( 'removable_handler' );
		$this->assertNull( $schedule );

		$schedules = Queuety::scheduler()->list();
		foreach ( $schedules as $s ) {
			$this->assertNotSame( 'removable_handler', $s->handler );
		}
	}

	// -- run() triggers scheduler tick ---------------------------------------

	public function test_run_scheduler_tick(): void {
		$this->skip_without_db();

		$this->cmd->run( array(), array() );
		$this->assertTrue( true );
	}
}
