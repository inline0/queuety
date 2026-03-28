<?php
/**
 * WebhookCommand unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\CLI;

require_once dirname( __DIR__ ) . '/Stubs/wp-cli-compat.php';

use PHPUnit\Framework\TestCase;
use Queuety\CLI\WebhookCommand;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Queuety;

/**
 * Tests for WebhookCommand CLI methods.
 */
class WebhookCommandTest extends TestCase {

	private WebhookCommand $cmd;
	private bool $has_db = false;

	protected function setUp(): void {
		parent::setUp();

		$this->cmd = new WebhookCommand();

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

	// -- add() registers a webhook -------------------------------------------

	public function test_add_registers_webhook(): void {
		$this->skip_without_db();

		$this->cmd->add( array( 'job.completed', 'https://example.com/hook' ), array() );

		$webhooks = Queuety::webhook_notifier()->list();
		$this->assertCount( 1, $webhooks );
		$this->assertSame( 'job.completed', $webhooks[0]['event'] );
		$this->assertSame( 'https://example.com/hook', $webhooks[0]['url'] );
	}

	// -- list_() shows webhooks ----------------------------------------------

	public function test_list_shows_webhooks(): void {
		$this->skip_without_db();

		Queuety::webhook_notifier()->register( 'job.failed', 'https://example.com/fail' );

		$this->cmd->list_( array(), array() );
		$this->assertTrue( true );
	}

	public function test_list_empty_shows_message(): void {
		$this->skip_without_db();

		$this->cmd->list_( array(), array() );
		$this->assertTrue( true );
	}

	// -- remove() removes a webhook ------------------------------------------

	public function test_remove_removes_webhook(): void {
		$this->skip_without_db();

		$id = Queuety::webhook_notifier()->register( 'job.buried', 'https://example.com/buried' );

		$this->cmd->remove( array( $id ), array() );

		$webhooks = Queuety::webhook_notifier()->list();
		$this->assertCount( 0, $webhooks );
	}
}
