<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Schema;
use Queuety\Tests\IntegrationTestCase;

class SchemaTest extends IntegrationTestCase {

	public function test_install_creates_all_three_tables(): void {
		$this->assertTrue(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_jobs() ) )
		);
		$this->assertTrue(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_workflows() ) )
		);
		$this->assertTrue(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_logs() ) )
		);
	}

	public function test_install_is_idempotent(): void {
		Schema::install( $this->conn );
		Schema::install( $this->conn );

		$this->assertTrue(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_jobs() ) )
		);
	}

	public function test_uninstall_drops_all_tables(): void {
		Schema::uninstall( $this->conn );

		$this->assertFalse(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_jobs() ) )
		);
		$this->assertFalse(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_workflows() ) )
		);
		$this->assertFalse(
			Schema::table_exists( $this->conn, $this->conn->table( Config::table_logs() ) )
		);

		// Re-install so tearDown does not fail.
		Schema::install( $this->conn );
	}

	public function test_table_exists_returns_false_for_nonexistent_table(): void {
		$this->assertFalse(
			Schema::table_exists( $this->conn, 'this_table_does_not_exist_ever' )
		);
	}
}
