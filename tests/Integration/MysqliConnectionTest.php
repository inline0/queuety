<?php

namespace Queuety\Tests\Integration;

use Queuety\Connection;
use Queuety\Enums\Priority;
use Queuety\Queue;
use Queuety\Schema;
use Queuety\Tests\QueuetyTestCase;

class MysqliConnectionTest extends QueuetyTestCase {

	private ?Connection $conn = null;

	protected function setUp(): void {
		parent::setUp();

		$this->skip_if_no_mysqli_database();

		$this->conn = new Connection(
			host: QUEUETY_TEST_DB_HOST,
			dbname: QUEUETY_TEST_DB_NAME,
			user: QUEUETY_TEST_DB_USER,
			password: QUEUETY_TEST_DB_PASS,
			prefix: QUEUETY_TEST_DB_PREFIX . 'mysqli_',
			driver: 'mysqli',
		);

		Schema::install( $this->conn );
	}

	protected function tearDown(): void {
		if ( null !== $this->conn ) {
			Schema::uninstall( $this->conn );
		}

		parent::tearDown();
	}

	public function test_dispatch_and_claim_uses_mysqli_driver(): void {
		$this->assertNotNull( $this->conn );
		$this->assertSame( 'mysqli', $this->conn->driver() );

		$queue = new Queue( $this->conn );
		$id    = $queue->dispatch(
			handler: 'mysqli-handler',
			payload: array( 'driver' => 'mysqli' ),
			queue: 'default',
			priority: Priority::High,
		);

		$job = $queue->claim( 'default' );

		$this->assertNotNull( $job );
		$this->assertSame( $id, $job->id );
		$this->assertSame( array( 'driver' => 'mysqli' ), $job->payload );
	}

	public function test_duplicate_key_errors_still_surface_as_pdo_exceptions(): void {
		$this->assertNotNull( $this->conn );

		$table = $this->conn->table( \Queuety\Config::table_locks() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (lock_key, owner, acquired_at)
			VALUES (:lock_key, :owner, NOW())"
		);
		$stmt->execute(
			array(
				'lock_key' => 'duplicate',
				'owner'    => 'one',
			)
		);

		$this->expectException( \PDOException::class );

		$stmt->execute(
			array(
				'lock_key' => 'duplicate',
				'owner'    => 'two',
			)
		);
	}

	private function skip_if_no_mysqli_database(): void {
		if ( ! \Queuety\MysqliPdo::available() ) {
			$this->markTestSkipped( 'mysqli is not available.' );
		}

		$host = QUEUETY_TEST_DB_HOST;
		$port = null;
		if ( ! str_starts_with( $host, '/' ) && 1 === substr_count( $host, ':' ) ) {
			list( $host, $suffix ) = explode( ':', $host, 2 );
			if ( ctype_digit( $suffix ) ) {
				$port = (int) $suffix;
			}
		}

		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			$this->markTestSkipped( 'mysqli could not initialize.' );
		}

		mysqli_report( MYSQLI_REPORT_OFF );

		$connected = @mysqli_real_connect(
			$mysqli,
			str_starts_with( QUEUETY_TEST_DB_HOST, '/' ) ? 'localhost' : $host,
			QUEUETY_TEST_DB_USER,
			QUEUETY_TEST_DB_PASS,
			QUEUETY_TEST_DB_NAME,
			$port,
			str_starts_with( QUEUETY_TEST_DB_HOST, '/' ) ? QUEUETY_TEST_DB_HOST : null,
		);

		if ( false === $connected ) {
			$this->markTestSkipped( 'MySQL is not available through mysqli.' );
		}

		mysqli_close( $mysqli );
	}
}
