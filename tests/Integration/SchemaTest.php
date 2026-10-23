<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Connection;
use Queuety\Queuety;
use Queuety\Schema;
use Queuety\Tests\IntegrationTestCase;

class SchemaTest extends IntegrationTestCase {

	public function test_fresh_install_records_ready_version(): void {
		$this->assertSame( array( 'version' => Schema::VERSION, 'state' => 'ready' ), Schema::status( $this->conn ) );
		Schema::assert_compatible( $this->conn );
	}

	public function test_initialization_never_installs_missing_schema(): void {
		Schema::uninstall( $this->conn );
		Queuety::reset();
		try {
			Queuety::init( $this->conn );
			$this->fail( 'Missing schema must require an explicit installation.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Incompatible Queuety schema', $error->getMessage() );
		}
		$this->assertFalse( Queuety::is_initialized() );
		$this->assertFalse( Schema::table_exists( $this->conn, $this->conn->table( Config::table_jobs() ) ) );
		Queuety::ensure_schema( $this->conn );
		Queuety::init( $this->conn );
		$this->assertTrue( Queuety::is_initialized() );
		Queuety::reset();
	}

	public function test_newer_schema_refuses_initialization_and_downgrade(): void {
		$schema = $this->conn->table( Config::table_schema() );
		$this->conn->pdo()->exec( "UPDATE {$schema} SET version = 999 WHERE id = 1" );
		Queuety::reset();
		try {
			Queuety::init( $this->conn );
			$this->fail( 'A newer schema cannot run in this release.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Incompatible Queuety schema version 999', $error->getMessage() );
		}
		try {
			Schema::install( $this->conn );
			$this->fail( 'Installing older code must not downgrade schema metadata.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'refuses to downgrade', $error->getMessage() );
		}
		$this->assertSame( 999, Schema::status( $this->conn )['version'] );
	}

	public function test_upgrade_requires_drain_and_resumes_without_losing_job(): void {
		$pdo = $this->conn->pdo();
		$schema = $this->conn->table( Config::table_schema() );
		$jobs = $this->conn->table( Config::table_jobs() );
		$pdo->exec( "UPDATE {$schema} SET version = 0 WHERE id = 1" );
		$pdo->exec( "INSERT INTO {$jobs} (handler, payload, status) VALUES ('legacy.handler', '{}', 'processing')" );
		$id = (int) $pdo->lastInsertId();
		try {
			Schema::install( $this->conn );
			$this->fail( 'Active workers must drain before altering their schema.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Queuety is draining', $error->getMessage() );
		}
		$this->assertSame( array( 'version' => 0, 'state' => 'draining' ), Schema::status( $this->conn ) );
		$this->assertSame( 'processing', $pdo->query( "SELECT status FROM {$jobs} WHERE id = {$id}" )->fetchColumn() );
		$pdo->exec( "UPDATE {$jobs} SET status = 'pending' WHERE id = {$id}" );
		Schema::install( $this->conn );
		$this->assertSame( Schema::VERSION, Schema::status( $this->conn )['version'] );
		$this->assertSame( 'pending', $pdo->query( "SELECT status FROM {$jobs} WHERE id = {$id}" )->fetchColumn() );
	}

	public function test_legacy_upgrade_retains_history_unfinished_state_and_duplicate_chunks(): void {
		$pdo = $this->conn->pdo();
		$schema = $this->conn->table( Config::table_schema() );
		$jobs = $this->conn->table( Config::table_jobs() );
		$workflows = $this->conn->table( Config::table_workflows() );
		$logs = $this->conn->table( Config::table_logs() );
		$events = $this->conn->table( Config::table_workflow_events() );
		$machine_events = $this->conn->table( Config::table_state_machine_events() );
		$chunks = $this->conn->table( Config::table_chunks() );
		$pdo->exec( "DROP TABLE {$schema}" );
		$pdo->exec( "ALTER TABLE {$jobs} DROP INDEX idx_dispatch_key, DROP COLUMN active_dispatch_key, DROP COLUMN claim_token, DROP COLUMN lease_expires_at, DROP COLUMN action_deadline_at, DROP COLUMN revision, DROP COLUMN workflow_revision, DROP COLUMN dispatch_key, DROP COLUMN dispatch_fingerprint, DROP COLUMN dispatch_key_durable" );
		$pdo->exec( "ALTER TABLE {$workflows} DROP COLUMN revision" );
		$pdo->exec( "ALTER TABLE {$chunks} DROP INDEX idx_attempt_chunk, DROP COLUMN claim_token" );
		$pdo->exec( "INSERT INTO {$jobs} (handler, payload) VALUES ('legacy.handler', '{\"cursor\":3}')" );
		$pdo->exec( "INSERT INTO {$workflows} (name, status, state, total_steps) VALUES ('legacy-workflow', 'paused', '{\"cursor\":7}', 3)" );
		$pdo->exec( "INSERT INTO {$logs} (handler, event) VALUES ('legacy.handler', 'completed')" );
		$pdo->exec( "INSERT INTO {$chunks} (job_id, chunk_index, content) VALUES (1, 0, 'first'), (1, 0, 'repeated')" );
		$pdo->exec( "DROP TABLE {$events}" );
		$pdo->exec( "CREATE TABLE {$events} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workflow_id BIGINT UNSIGNED NOT NULL, step_index TINYINT UNSIGNED NOT NULL, handler VARCHAR(255) NOT NULL, event ENUM('state_snapshot') NOT NULL, state_snapshot LONGTEXT DEFAULT NULL, step_output LONGTEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB" );
		$pdo->exec( "INSERT INTO {$events} (workflow_id, step_index, handler, event, state_snapshot, step_output) VALUES (1, 0, 'legacy.handler', 'state_snapshot', '{\"cursor\":7}', '{\"value\":4}')" );
		$pdo->exec( "DROP TABLE {$machine_events}" );
		$pdo->exec( "CREATE TABLE {$machine_events} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, machine_id BIGINT UNSIGNED NOT NULL, state_name VARCHAR(191) NOT NULL, event ENUM('action_failed') NOT NULL, state_snapshot LONGTEXT DEFAULT NULL, payload LONGTEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB" );
		$pdo->exec( "INSERT INTO {$machine_events} (machine_id, state_name, event, state_snapshot, payload, error_message) VALUES (9, 'plan', 'action_failed', '{}', '{\"value\":4}', 'legacy failure')" );
		Schema::install( $this->conn );
		$this->assertSame( '{"cursor":3}', $pdo->query( "SELECT payload FROM {$jobs}" )->fetchColumn() );
		$this->assertSame( '{"cursor":7}', $pdo->query( "SELECT state FROM {$workflows}" )->fetchColumn() );
		$this->assertSame( 'paused', $pdo->query( "SELECT status FROM {$workflows}" )->fetchColumn() );
		$this->assertSame( 1, (int) $pdo->query( "SELECT COUNT(*) FROM {$logs}" )->fetchColumn() );
		$this->assertSame( 2, (int) $pdo->query( "SELECT COUNT(*) FROM {$chunks}" )->fetchColumn() );
		$row = $pdo->query( "SELECT event, state_snapshot, state_after, output FROM {$events}" )->fetch();
		$this->assertSame( 'state_snapshot', $row['event'] );
		$this->assertSame( $row['state_snapshot'], $row['state_after'] );
		$this->assertSame( '{"value":4}', $row['output'] );
		$row = $pdo->query( "SELECT state_after, input, error, error_message FROM {$machine_events}" )->fetch();
		$this->assertSame( '{}', $row['state_after'] );
		$this->assertSame( '{"value":4}', $row['input'] );
		$this->assertSame( 'legacy failure', json_decode( $row['error'], true )['message'] );
		$this->assertSame( 'legacy failure', $row['error_message'] );
		Schema::install( $this->conn );
		$this->assertSame( 2, (int) $pdo->query( "SELECT COUNT(*) FROM {$chunks}" )->fetchColumn() );
	}

	public function test_claim_barrier_refuses_draining_schema(): void {
		$table = $this->conn->table( Config::table_schema() );
		$pdo = $this->conn->pdo();
		$pdo->exec( "UPDATE {$table} SET state = 'draining' WHERE id = 1" );
		$pdo->beginTransaction();
		try {
			Schema::assert_claimable( $this->conn );
			$this->fail( 'No fresh claim may pass the drain barrier.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'draining', $error->getMessage() );
		} finally {
			$pdo->rollBack();
		}
	}

	public function test_retired_active_workflow_refuses_upgrade_without_rewriting_its_state(): void {
		$pdo = $this->conn->pdo();
		$schema = $this->conn->table( Config::table_schema() );
		$workflows = $this->conn->table( Config::table_workflows() );
		$pdo->exec( "DROP TABLE {$schema}" );
		$pdo->exec( "ALTER TABLE {$workflows} MODIFY status ENUM('running', 'completed', 'failed', 'paused', 'waiting_signal', 'waiting_workflow', 'cancelled') NOT NULL DEFAULT 'running'" );
		$state = '{"_steps":[{"type":"signal","signal":"approval"}],"_waiting_signal":"approval","draft":"preserve this"}';
		$stmt = $pdo->prepare( "INSERT INTO {$workflows} (name, status, state, total_steps) VALUES ('old-review', 'waiting_signal', ?, 1)" );
		$stmt->execute( array( $state ) );
		try {
			Schema::install( $this->conn );
			$this->fail( 'Retired active semantics cannot be labeled compatible.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Retired Queuety workflow definition', $error->getMessage() );
		}
		$this->assertSame( array( 'version' => 0, 'state' => 'draining' ), Schema::status( $this->conn ) );
		$row = $pdo->query( "SELECT status, state FROM {$workflows}" )->fetch();
		$this->assertSame( 'waiting_signal', $row['status'] );
		$this->assertSame( $state, $row['state'] );
		$pdo->exec( "UPDATE {$workflows} SET status = 'completed'" );
		Schema::install( $this->conn );
		$this->assertSame( $state, $pdo->query( "SELECT state FROM {$workflows}" )->fetchColumn() );
		$pdo->exec( "INSERT INTO {$workflows} (name, status, state, total_steps) VALUES ('new-review', 'waiting_for_signal', '{}', 1)" );
		$this->assertSame( 2, (int) $pdo->query( "SELECT COUNT(*) FROM {$workflows}" )->fetchColumn() );
	}

	public function test_retired_pending_handler_is_not_silently_executed_by_new_runtime(): void {
		$schema = $this->conn->table( Config::table_schema() );
		$jobs = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->exec( "DROP TABLE {$schema}" );
		$this->conn->pdo()->exec( "INSERT INTO {$jobs} (handler, payload) VALUES ('__queuety_timer', '{}')" );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Retired Queuety job handlers' );
		Schema::install( $this->conn );
	}

	public function test_two_installations_keep_schema_and_runtime_cache_isolated(): void {
		$options = $this->conn->worker_options();
		$options['table_prefix'] = 'second_';
		$other = new Connection( ...$options );
		Queuety::reset();
		try {
			Schema::install( $other );
			Queuety::init( $this->conn );
			Queuety::cache()->set( 'workflow:1', 'first-installation' );
			try {
				Queuety::init( $other );
				$this->fail( 'Switching an initialized singleton requires an explicit reset.' );
			} catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'one installation per process', $error->getMessage() );
			}
			Queuety::reset();
			Queuety::init( $other );
			$this->assertNull( Queuety::cache()->get( 'workflow:1' ) );
			$this->assertSame( Schema::VERSION, Schema::status( $other )['version'] );
		} finally {
			Queuety::reset();
			Schema::uninstall( $other );
		}
	}

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
