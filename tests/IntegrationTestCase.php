<?php

namespace Queuety\Tests;

use Queuety\Connection;
use Queuety\Schema;

/**
 * Base test case for integration tests requiring a MySQL database.
 */
class IntegrationTestCase extends QueuetyTestCase {

	protected Connection $conn;

	protected function setUp(): void {
		parent::setUp();
		$this->skip_if_no_database();

		$this->conn = new Connection(
			host: QUEUETY_TEST_DB_HOST,
			dbname: QUEUETY_TEST_DB_NAME,
			user: QUEUETY_TEST_DB_USER,
			password: QUEUETY_TEST_DB_PASS,
			prefix: QUEUETY_TEST_DB_PREFIX,
		);

		Schema::install( $this->conn );
	}

	protected function tearDown(): void {
		if ( isset( $this->conn ) ) {
			Schema::uninstall( $this->conn );
		}
		parent::tearDown();
	}

	protected function skip_if_no_database(): void {
		try {
			$host = QUEUETY_TEST_DB_HOST;
			if ( str_starts_with( $host, '/' ) ) {
				$dsn = sprintf( 'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $host, QUEUETY_TEST_DB_NAME );
			} else {
				$dsn = sprintf( 'mysql:host=%s;dbname=%s;charset=utf8mb4', $host, QUEUETY_TEST_DB_NAME );
			}
			new \PDO( $dsn, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS );
		} catch ( \PDOException $e ) {
			$this->markTestSkipped( 'MySQL is not available: ' . $e->getMessage() );
		}
	}

	/**
	 * Directly manipulate a row in the database for test setup.
	 */
	protected function raw_update( string $table, array $set, array $where ): void {
		$set_parts   = array();
		$where_parts = array();
		$params      = array();

		foreach ( $set as $col => $val ) {
			$set_parts[]          = "{$col} = :set_{$col}";
			$params["set_{$col}"] = $val;
		}

		foreach ( $where as $col => $val ) {
			$where_parts[]          = "{$col} = :where_{$col}";
			$params["where_{$col}"] = $val;
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$this->conn->table( $table ),
			implode( ', ', $set_parts ),
			implode( ' AND ', $where_parts )
		);

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
	}
}
