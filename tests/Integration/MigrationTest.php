<?php
/**
 * Migration integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Connection;
use Queuety\Schema;
use Queuety\Tests\IntegrationTestCase;

/**
 * Tests for Schema migration methods.
 */
class MigrationTest extends IntegrationTestCase {

	// -- Fresh install with install() ----------------------------------------

	public function test_install_creates_all_tables(): void {
		// Tables are created in setUp via parent::setUp() -> Schema::install().
		$tables = array(
			'queuety_jobs',
			'queuety_workflows',
			'queuety_logs',
			'queuety_schedules',
			'queuety_queue_states',
			'queuety_webhooks',
			'queuety_signals',
			'queuety_locks',
			'queuety_batches',
			'queuety_chunks',
			'queuety_workflow_events',
		);

		foreach ( $tables as $table ) {
			$full_name = $this->conn->table( $table );
			$this->assertTrue(
				Schema::table_exists( $this->conn, $full_name ),
				"Table {$full_name} should exist after install."
			);
		}
	}

	// -- install() is idempotent (CREATE TABLE IF NOT EXISTS) ----------------

	public function test_install_is_idempotent(): void {
		// Call install a second time (already called in setUp).
		Schema::install( $this->conn );

		// Should not throw and tables should still exist.
		$full_name = $this->conn->table( 'queuety_jobs' );
		$this->assertTrue( Schema::table_exists( $this->conn, $full_name ) );
	}

	// -- upgrade()/detect_version() on fresh installs ------------------------

	public function test_detect_version_reports_current_schema_on_fresh_install(): void {
		$this->assertSame( Schema::CURRENT_VERSION, Schema::detect_version( $this->conn ) );
	}

	public function test_upgrade_is_safe_on_fresh_install_with_stale_recorded_version(): void {
		$version = Schema::upgrade( $this->conn, '0.5.0' );

		$this->assertSame( Schema::CURRENT_VERSION, $version );
		$this->assertTrue(
			Schema::column_exists(
				$this->conn,
				$this->conn->table( 'queuety_workflows' ),
				'deadline_at'
			)
		);
	}

	// -- migrate_060 on fresh install does not error -------------------------

	public function test_migrate_060_on_fresh_install(): void {
		// On a fresh install, the tables already have the v0.6 schema.
		// migrate_060 should not error (ALTER MODIFY is idempotent for ENUMs,
		// CREATE TABLE IF NOT EXISTS is safe).
		Schema::migrate_060( $this->conn );

		$sig_table = $this->conn->table( 'queuety_signals' );
		$this->assertTrue( Schema::table_exists( $this->conn, $sig_table ) );
	}

	public function test_migrate_060_is_idempotent(): void {
		Schema::migrate_060( $this->conn );
		Schema::migrate_060( $this->conn );

		$sig_table = $this->conn->table( 'queuety_signals' );
		$this->assertTrue( Schema::table_exists( $this->conn, $sig_table ) );
	}

	// -- migrate_070 on fresh install ----------------------------------------

	public function test_migrate_070_on_fresh_install(): void {
		Schema::migrate_070( $this->conn );

		$batch_table = $this->conn->table( 'queuety_batches' );
		$this->assertTrue( Schema::table_exists( $this->conn, $batch_table ) );
	}

	// -- migrate_080 on fresh install ----------------------------------------

	public function test_migrate_080_on_fresh_install(): void {
		Schema::migrate_080( $this->conn );

		$jobs_table = $this->conn->table( 'queuety_jobs' );
		$this->assertTrue( Schema::table_exists( $this->conn, $jobs_table ) );
	}

	// -- migrate_090 on fresh install ----------------------------------------

	public function test_migrate_090_on_fresh_install(): void {
		Schema::migrate_090( $this->conn );

		$chunks_table = $this->conn->table( 'queuety_chunks' );
		$this->assertTrue( Schema::table_exists( $this->conn, $chunks_table ) );
	}

	public function test_migrate_090_is_idempotent(): void {
		Schema::migrate_090( $this->conn );
		Schema::migrate_090( $this->conn );

		$chunks_table = $this->conn->table( 'queuety_chunks' );
		$this->assertTrue( Schema::table_exists( $this->conn, $chunks_table ) );
	}

	// -- migrate_0110 on fresh install ---------------------------------------

	public function test_migrate_0110_on_fresh_install(): void {
		Schema::migrate_0110( $this->conn );

		$events_table = $this->conn->table( 'queuety_workflow_events' );
		$this->assertTrue( Schema::table_exists( $this->conn, $events_table ) );
	}

	public function test_migrate_0110_is_idempotent(): void {
		Schema::migrate_0110( $this->conn );
		Schema::migrate_0110( $this->conn );

		$events_table = $this->conn->table( 'queuety_workflow_events' );
		$this->assertTrue( Schema::table_exists( $this->conn, $events_table ) );
	}

	public function test_migrate_0120_on_fresh_install(): void {
		Schema::migrate_0120( $this->conn );

		$this->assertTrue(
			Schema::column_exists(
				$this->conn,
				$this->conn->table( 'queuety_workflows' ),
				'deadline_at'
			)
		);
	}

	public function test_migrate_0120_is_idempotent(): void {
		Schema::migrate_0120( $this->conn );
		Schema::migrate_0120( $this->conn );

		$this->assertTrue(
			Schema::index_exists(
				$this->conn,
				$this->conn->table( 'queuety_workflows' ),
				'idx_deadline'
			)
		);
	}

	// -- uninstall drops everything ------------------------------------------

	public function test_uninstall_drops_all_tables(): void {
		Schema::uninstall( $this->conn );

		$tables = array(
			'queuety_jobs',
			'queuety_workflows',
			'queuety_logs',
			'queuety_schedules',
			'queuety_queue_states',
			'queuety_webhooks',
			'queuety_signals',
			'queuety_locks',
			'queuety_batches',
			'queuety_chunks',
			'queuety_workflow_events',
		);

		foreach ( $tables as $table ) {
			$full_name = $this->conn->table( $table );
			$this->assertFalse(
				Schema::table_exists( $this->conn, $full_name ),
				"Table {$full_name} should not exist after uninstall."
			);
		}

		// Re-install for tearDown.
		Schema::install( $this->conn );
	}
}
