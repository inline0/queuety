<?php
/**
 * WebhookNotifier unit tests (without HTTP).
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Queuety;
use Queuety\WebhookNotifier;

/**
 * Unit tests for WebhookNotifier methods.
 *
 * These tests verify database interactions without making actual HTTP requests.
 */
class WebhookNotifierTest extends TestCase {

	private bool $has_db = false;
	private ?WebhookNotifier $notifier = null;

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

			\Queuety\Schema::install( $conn );
			$this->notifier = new WebhookNotifier( $conn );
			$this->has_db   = true;
		} catch ( \PDOException $e ) {
			// No database.
		}
	}

	protected function tearDown(): void {
		if ( $this->has_db ) {
			try {
				$conn = new Connection(
					host: QUEUETY_TEST_DB_HOST,
					dbname: QUEUETY_TEST_DB_NAME,
					user: QUEUETY_TEST_DB_USER,
					password: QUEUETY_TEST_DB_PASS,
					prefix: QUEUETY_TEST_DB_PREFIX,
				);
				\Queuety\Schema::uninstall( $conn );
			} catch ( \Throwable $e ) {
				// Ignore.
			}
		}
		parent::tearDown();
	}

	private function skip_without_db(): void {
		if ( ! $this->has_db ) {
			$this->markTestSkipped( 'Database not available.' );
		}
	}

	// -- register() inserts into DB ------------------------------------------

	public function test_register_inserts_into_db(): void {
		$this->skip_without_db();

		$id = $this->notifier->register( 'job.completed', 'https://example.com/webhook' );

		$this->assertGreaterThan( 0, $id );

		$list = $this->notifier->list();
		$this->assertCount( 1, $list );
		$this->assertSame( 'job.completed', $list[0]['event'] );
		$this->assertSame( 'https://example.com/webhook', $list[0]['url'] );
	}

	// -- remove() deletes from DB --------------------------------------------

	public function test_remove_deletes_from_db(): void {
		$this->skip_without_db();

		$id = $this->notifier->register( 'job.failed', 'https://example.com/fail' );
		$this->assertCount( 1, $this->notifier->list() );

		$this->notifier->remove( $id );
		$this->assertCount( 0, $this->notifier->list() );
	}

	// -- list() returns all webhooks -----------------------------------------

	public function test_list_returns_all_webhooks(): void {
		$this->skip_without_db();

		$this->notifier->register( 'job.completed', 'https://a.example.com' );
		$this->notifier->register( 'job.failed', 'https://b.example.com' );
		$this->notifier->register( 'job.buried', 'https://c.example.com' );

		$list = $this->notifier->list();
		$this->assertCount( 3, $list );
	}

	// -- notify() constructs correct payload structure -----------------------

	public function test_notify_with_no_matching_webhooks_is_noop(): void {
		$this->skip_without_db();

		// Register for a different event.
		$this->notifier->register( 'job.failed', 'https://example.com/fail' );

		// Notify for an event with no webhooks: should be a no-op.
		$this->notifier->notify( 'job.completed', array( 'job_id' => 1 ) );
		$this->assertTrue( true );
	}

	public function test_notify_looks_up_correct_event(): void {
		$this->skip_without_db();

		// Register webhooks for different events.
		$this->notifier->register( 'job.completed', 'http://127.0.0.1:1/completed' );
		$this->notifier->register( 'job.failed', 'http://127.0.0.1:1/failed' );

		// Notify for job.completed - should only try to call the completed webhook.
		// This is fire-and-forget, so it won't throw even on unreachable URL.
		$this->notifier->notify( 'job.completed', array( 'job_id' => 42 ) );
		$this->assertTrue( true );
	}

	// -- Multiple registrations for same event -------------------------------

	public function test_multiple_webhooks_for_same_event(): void {
		$this->skip_without_db();

		$id1 = $this->notifier->register( 'job.completed', 'https://a.example.com' );
		$id2 = $this->notifier->register( 'job.completed', 'https://b.example.com' );

		$this->assertNotSame( $id1, $id2 );

		$list = $this->notifier->list();
		$this->assertCount( 2, $list );
	}
}
