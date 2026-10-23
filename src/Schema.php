<?php
/**
 * Database schema management.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Installs versioned schemas and validates runtime compatibility.
 */
class Schema {

	/** Schema understood by this release. */
	public const VERSION = 1;

	/**
	 * Install or explicitly upgrade after stopping workers from older releases.
	 *
	 * Upgrades publish a persistent drain barrier before checking active claims.
	 * A failed or interrupted migration stays closed until installation succeeds.
	 * DDL is resumable because MySQL does not transactionally roll it back.
	 *
	 * @param Connection $conn Database connection.
	 * @throws \RuntimeException When workers have not drained or the schema is newer.
	 */
	public static function install( Connection $conn ): void {
		$pdo = $conn->pdo();
		if ( $pdo->inTransaction() ) {
			throw new \RuntimeException( 'Schema installation cannot run inside a transaction.' );
		}
		$lock_name = 'queuety-schema:' . substr( $conn->namespace(), 0, 48 );
		$lock = $pdo->prepare( 'SELECT GET_LOCK(?, 0)' );
		$lock->execute( array( $lock_name ) );
		if ( 1 !== (int) $lock->fetchColumn() ) {
			throw new \RuntimeException( 'Another Queuety schema migration is running.' );
		}
		try {
			$table = $conn->table( Config::table_schema() );
			$pdo->exec(
				"CREATE TABLE IF NOT EXISTS {$table} (
					id TINYINT UNSIGNED PRIMARY KEY,
					version INT UNSIGNED NOT NULL,
					state VARCHAR(16) NOT NULL,
					updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);
			$pdo->exec( "INSERT IGNORE INTO {$table} (id, version, state) VALUES (1, 0, 'draining')" );
			$status = self::status( $conn );
			if ( $status['version'] > self::VERSION ) {
				throw new \RuntimeException( 'Queuety refuses to downgrade a newer database schema.' );
			}
			if ( self::VERSION === $status['version'] && 'ready' === $status['state'] ) {
				return;
			}
			$pdo->exec( "UPDATE {$table} SET state = 'draining' WHERE id = 1" );
			$jobs = $conn->table( Config::table_jobs() );
			if ( self::table_exists( $conn, $jobs ) ) {
				$active = $pdo->query( "SELECT COUNT(*) FROM {$jobs} WHERE status = 'processing'" );
				if ( false === $active || 0 !== (int) $active->fetchColumn() ) {
					throw new \RuntimeException( 'Queuety is draining. Stop older workers and finish or recover active claims, then run Schema::install() again.' );
				}
			}
			if ( $status['version'] < 1 ) {
				self::assert_legacy_runtime_compatible( $conn );
				self::migrate_version_one( $conn );
			}
			$version = self::VERSION;
			$pdo->exec( "UPDATE {$table} SET version = {$version}, state = 'ready' WHERE id = 1" );
		} finally {
			if ( ! $conn->is_discarded() ) {
				$unlock = $pdo->prepare( 'SELECT RELEASE_LOCK(?)' );
				$unlock->execute( array( $lock_name ) );
			}
		}
	}

	/**
	 * Read schema status without changing tables.
	 *
	 * @param Connection $conn Database connection.
	 * @return array{version: int, state: string}
	 */
	public static function status( Connection $conn ): array {
		$table = $conn->table( Config::table_schema() );
		if ( ! self::table_exists( $conn, $table ) ) {
			return array(
				'version' => 0,
				'state' => 'uninstalled',
			);
		}
		$stmt = $conn->pdo()->query( "SELECT version, state FROM {$table} WHERE id = 1" );
		$row = false === $stmt ? false : $stmt->fetch();
		return self::normalize_status( $row );
	}

	/**
	 * Require an explicitly installed, compatible schema without running DDL.
	 *
	 * @param Connection $conn Database connection.
	 * @throws \RuntimeException When a migration or deployment is required.
	 */
	public static function assert_compatible( Connection $conn ): void {
		self::assert_status( self::status( $conn ) );
	}

	/**
	 * Hold the schema drain barrier until the caller's claim transaction ends.
	 *
	 * @param Connection $conn Database connection with an active transaction.
	 * @throws \RuntimeException When no transaction or compatible schema exists.
	 */
	public static function assert_claimable( Connection $conn ): void {
		$pdo = $conn->pdo();
		if ( ! $pdo->inTransaction() ) {
			throw new \RuntimeException( 'The schema claim barrier requires a transaction.' );
		}
		$table = $conn->table( Config::table_schema() );
		$stmt = $pdo->query( "SELECT version, state FROM {$table} WHERE id = 1 LOCK IN SHARE MODE" );
		self::assert_status( self::normalize_status( false === $stmt ? false : $stmt->fetch() ) );
	}

	/**
	 * Decode one schema metadata row.
	 *
	 * @param mixed $row Database result.
	 * @return array{version: int, state: string}
	 */
	private static function normalize_status( mixed $row ): array {
		if ( ! is_array( $row ) || ! isset( $row['version'], $row['state'] ) || ! is_scalar( $row['version'] ) || ! is_string( $row['state'] ) ) {
			return array(
				'version' => 0,
				'state' => 'uninstalled',
			);
		}
		return array(
			'version' => (int) $row['version'],
			'state' => $row['state'],
		);
	}

	/**
	 * Check one metadata snapshot.
	 *
	 * @param array{version: int, state: string} $status Schema metadata.
	 * @throws \RuntimeException When this runtime cannot use the schema.
	 */
	private static function assert_status( array $status ): void {
		if ( self::VERSION !== $status['version'] ) {
			throw new \RuntimeException( sprintf( 'Incompatible Queuety schema version %d; runtime requires %d. Stop workers and run the matching release\'s Schema::install() explicitly.', $status['version'], self::VERSION ) );
		}
		if ( 'ready' !== $status['state'] ) {
			throw new \RuntimeException( 'Queuety schema is draining or its migration is incomplete.' );
		}
	}

	/**
	 * Refuse retired active definitions instead of relabeling incompatible work.
	 *
	 * @param Connection $conn Database connection.
	 * @throws \RuntimeException When active work requires its original runtime.
	 */
	private static function assert_legacy_runtime_compatible( Connection $conn ): void {
		$pdo = $conn->pdo();
		$jobs = $conn->table( Config::table_jobs() );
		if ( self::table_exists( $conn, $jobs ) ) {
			$stmt = $pdo->query( "SELECT id FROM {$jobs} WHERE status IN ('pending', 'processing') AND handler IN ('__queuety_fan_out', '__queuety_sub_workflow', '__queuety_spawn_workflows', '__queuety_timer', '__queuety_signal', '__queuety_workflow_wait', '__queuety_loop') LIMIT 1" );
			if ( false !== $stmt && false !== $stmt->fetch() ) {
				throw new \RuntimeException( 'Retired Queuety job handlers require completion or explicit migration with the matching old release before upgrading.' );
			}
		}
		$workflows = $conn->table( Config::table_workflows() );
		if ( ! self::table_exists( $conn, $workflows ) ) {
			return;
		}
		$cursor = 0;
		do {
			$stmt = $pdo->prepare( "SELECT id, status, state FROM {$workflows} WHERE id > ? AND status NOT IN ('completed', 'failed', 'cancelled') ORDER BY id LIMIT 100" );
			$stmt->execute( array( $cursor ) );
			$rows = $stmt->fetchAll();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'], $row['status'], $row['state'] ) || ! is_scalar( $row['id'] ) || ! is_string( $row['state'] ) ) {
					throw new \RuntimeException( 'Invalid legacy workflow metadata; preserve and repair it before upgrading.' );
				}
				$cursor = (int) $row['id'];
				$state = json_decode( $row['state'], true );
				if ( ! is_array( $state ) || ! in_array( $row['status'], array( 'running', 'paused', 'waiting_for_signal', 'waiting_for_workflows' ), true )
					|| array_intersect( array( '_waiting_signal', '_fan_out_steps', '_loop_steps', '_spawned_by_workflow_id', '_spawned_by_step_index' ), array_keys( $state ) )
					|| self::contains_retired_steps( $state['_steps'] ?? array() )
					|| self::contains_retired_steps( array( $state['_wait'] ?? array() ) ) ) {
					throw new \RuntimeException( sprintf( 'Retired Queuety workflow definition %d requires completion or explicit migration with its matching old release. Its data has been preserved.', $cursor ) );
				}
			}
			$page_count = count( $rows );
		} while ( $page_count === 100 );
	}

	/**
	 * Check persisted orchestration definitions without inspecting user payloads.
	 *
	 * @param mixed $steps Persisted step collection or nested workflow definition.
	 * @return bool
	 */
	private static function contains_retired_steps( mixed $steps ): bool {
		if ( ! is_array( $steps ) ) {
			return false;
		}
		if ( array_is_list( $steps ) ) {
			foreach ( $steps as $step ) {
				if ( self::contains_retired_steps( $step ) ) {
					return true;
				}
			}
			return false;
		}
		if ( in_array( $steps['type'] ?? null, array( 'fan_out', 'sub_workflow', 'spawn_workflows', 'timer', 'signal', 'workflow_wait', 'loop' ), true ) ) {
			return true;
		}
		foreach ( array( 'steps', 'sub_steps', 'workflow_steps', 'workflow_definition' ) as $key ) {
			if ( self::contains_retired_steps( $steps[ $key ] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add missing definitions while retaining historical columns and rows.
	 *
	 * @param Connection $conn Database connection.
	 * @param string     $sql Canonical table definition for this migration.
	 * @throws \RuntimeException When an internal table definition is malformed.
	 */
	private static function create_table( Connection $conn, string $sql ): void {
		if ( ! preg_match( '/CREATE TABLE IF NOT EXISTS ([a-zA-Z0-9_]+)/', $sql, $match ) ) {
			throw new \RuntimeException( 'Invalid Queuety migration table definition.' );
		}
		$table = $match[1];
		$pdo = $conn->pdo();
		$exists = self::table_exists( $conn, $table );
		$pdo->exec( $sql );
		if ( ! $exists ) {
			return;
		}
		$stmt = $pdo->query( "SHOW COLUMNS FROM {$table}" );
		$columns = array();
		foreach ( false === $stmt ? array() : $stmt->fetchAll() as $column ) {
			if ( is_array( $column ) && isset( $column['Field'] ) && is_string( $column['Field'] ) ) {
				$columns[] = $column['Field'];
			}
		}
		$stmt = $pdo->query( "SHOW INDEX FROM {$table}" );
		$indexes = array();
		foreach ( false === $stmt ? array() : $stmt->fetchAll() as $index ) {
			if ( is_array( $index ) && isset( $index['Key_name'] ) && is_string( $index['Key_name'] ) ) {
				$indexes[] = $index['Key_name'];
			}
		}
		$lines = explode( "\n", $sql );
		foreach ( array_slice( $lines, 1, -1 ) as $line ) {
			$definition = rtrim( trim( $line ), ',' );
			if ( preg_match( '/^(?:UNIQUE )?INDEX ([a-zA-Z0-9_]+)/', $definition, $match ) ) {
				if ( ! in_array( $match[1], $indexes, true ) ) {
					if ( 'idx_attempt_chunk' === $match[1] ) {
						self::preserve_duplicate_chunks( $conn, $table );
					}
					$pdo->exec( "ALTER TABLE {$table} ADD {$definition}" );
				}
			} elseif ( preg_match( '/^([a-zA-Z0-9_]+) /', $definition, $match ) ) {
				if ( ! in_array( $match[1], $columns, true ) ) {
					$pdo->exec( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
				} elseif ( 'event' === $match[1] ) {
					// A VARCHAR preserves retired enum values while accepting new events.
					$pdo->exec( "ALTER TABLE {$table} MODIFY COLUMN event VARCHAR(64) NOT NULL" );
				} elseif ( 'available_at' === $match[1] ) {
					$pdo->exec( "ALTER TABLE {$table} MODIFY COLUMN available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)" );
				} elseif ( 'status' === $match[1] && $table === $conn->table( Config::table_workflows() ) ) {
					$pdo->exec( "ALTER TABLE {$table} MODIFY COLUMN {$definition}" );
				}
			}
		}
		foreach ( array(
			'state_snapshot' => 'state_after',
			'step_output' => 'output',
			'payload' => 'input',
		) as $old => $new ) {
			if ( in_array( $old, $columns, true ) && str_contains( $sql, "\n\t\t\t\t{$new} LONGTEXT" ) ) {
				$pdo->exec( "UPDATE {$table} SET {$new} = {$old} WHERE {$new} IS NULL" );
			}
		}
		if ( in_array( 'error_message', $columns, true ) && str_contains( $sql, "\n\t\t\t\terror LONGTEXT" ) ) {
			$pdo->exec( "UPDATE {$table} SET error = JSON_OBJECT('message', error_message) WHERE error IS NULL AND error_message IS NOT NULL" );
		}
	}

	/**
	 * Retain repeated legacy chunk indexes under distinct historical attempts.
	 *
	 * @param Connection $conn Database connection.
	 * @param string     $table Chunk table.
	 */
	private static function preserve_duplicate_chunks( Connection $conn, string $table ): void {
		$conn->pdo()->exec(
			"UPDATE {$table} AS chunks
			JOIN (SELECT job_id, claim_token, chunk_index, MIN(id) AS first_id
				FROM {$table} GROUP BY job_id, claim_token, chunk_index HAVING COUNT(*) > 1) AS duplicates
			ON chunks.job_id = duplicates.job_id AND chunks.claim_token = duplicates.claim_token
				AND chunks.chunk_index = duplicates.chunk_index AND chunks.id <> duplicates.first_id
			SET chunks.claim_token = CONCAT('legacy:', chunks.id)"
		);
	}

	/**
	 * Create all Queuety tables.
	 *
	 * @param Connection $conn Database connection.
	 */
	private static function migrate_version_one( Connection $conn ): void {
		$jobs                  = $conn->table( Config::table_jobs() );
		$wf                    = $conn->table( Config::table_workflows() );
		$logs                  = $conn->table( Config::table_logs() );
		$schedules             = $conn->table( Config::table_schedules() );
		$queue_states          = $conn->table( Config::table_queue_states() );
		$webhooks              = $conn->table( Config::table_webhooks() );
		$signals               = $conn->table( Config::table_signals() );
		$workflow_dependencies = $conn->table( Config::table_workflow_dependencies() );
		$workflow_keys         = $conn->table( Config::table_workflow_dispatch_keys() );
		$locks                 = $conn->table( Config::table_locks() );
		$batches               = $conn->table( Config::table_batches() );
		$chunks                = $conn->table( Config::table_chunks() );
		$workflow_events       = $conn->table( Config::table_workflow_events() );
		$artifacts             = $conn->table( Config::table_artifacts() );
		$state_machines        = $conn->table( Config::table_state_machines() );
		$state_machine_events  = $conn->table( Config::table_state_machine_events() );

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$jobs} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				queue VARCHAR(64) NOT NULL DEFAULT 'default',
				handler VARCHAR(255) NOT NULL,
				payload LONGTEXT NOT NULL,
				payload_hash VARCHAR(64) DEFAULT NULL,
				priority TINYINT NOT NULL DEFAULT 0,
				status ENUM('pending', 'processing', 'completed', 'failed', 'buried') NOT NULL DEFAULT 'pending',
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
				available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
				reserved_at DATETIME DEFAULT NULL,
				claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				lease_expires_at DATETIME(6) DEFAULT NULL,
				action_deadline_at DATETIME(6) DEFAULT NULL,
				revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
				dispatch_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				dispatch_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				dispatch_key_durable TINYINT(1) NOT NULL DEFAULT 0,
				active_dispatch_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN dispatch_key_durable = 1 OR status IN ('pending', 'processing') THEN RTRIM(dispatch_key) ELSE NULL END) STORED,
				completed_at DATETIME DEFAULT NULL,
				failed_at DATETIME DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
				workflow_revision BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				depends_on BIGINT UNSIGNED DEFAULT NULL,
				batch_id BIGINT UNSIGNED DEFAULT NULL,
				concurrency_group VARCHAR(191) DEFAULT NULL,
				concurrency_limit SMALLINT UNSIGNED DEFAULT NULL,
				cost_units INT UNSIGNED NOT NULL DEFAULT 1,
				heartbeat_data JSON DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_queue_status_available (queue, status, available_at, priority),
				INDEX idx_queue_claim (queue, status, priority, id, available_at),
				INDEX idx_status (status),
				INDEX idx_status_completed (status, completed_at),
				INDEX idx_reserved (status, reserved_at),
				INDEX idx_lease (status, lease_expires_at),
				UNIQUE INDEX idx_dispatch_key (active_dispatch_key),
				INDEX idx_handler_status (handler, status),
				INDEX idx_group_status (concurrency_group, status),
				INDEX idx_workflow (workflow_id, step_index),
				INDEX idx_unique (handler, payload_hash, status),
				INDEX idx_depends (depends_on),
				INDEX idx_batch (batch_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$wf} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				status ENUM('running', 'completed', 'failed', 'paused', 'waiting_for_signal', 'waiting_for_workflows', 'cancelled') NOT NULL DEFAULT 'running',
				state LONGTEXT NOT NULL,
				current_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
				revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
				total_steps TINYINT UNSIGNED NOT NULL,
				parent_workflow_id BIGINT UNSIGNED DEFAULT NULL,
				parent_step_index TINYINT UNSIGNED DEFAULT NULL,
				started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				completed_at DATETIME DEFAULT NULL,
				failed_at DATETIME DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				deadline_at DATETIME DEFAULT NULL,
				INDEX idx_status (status),
				INDEX idx_status_completed (status, completed_at),
				INDEX idx_parent (parent_workflow_id),
				INDEX idx_deadline (status, deadline_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$logs} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				job_id BIGINT UNSIGNED DEFAULT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				handler VARCHAR(255) NOT NULL,
				queue VARCHAR(64) NOT NULL DEFAULT 'default',
				event VARCHAR(64) NOT NULL,
				attempt TINYINT UNSIGNED DEFAULT NULL,
				duration_ms INT UNSIGNED DEFAULT NULL,
				memory_peak_kb INT UNSIGNED DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				error_class VARCHAR(255) DEFAULT NULL,
				error_trace TEXT DEFAULT NULL,
				context JSON DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_job (job_id),
				INDEX idx_workflow (workflow_id),
				INDEX idx_handler (handler),
				INDEX idx_event (event),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$schedules} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				handler VARCHAR(255) NOT NULL,
				payload LONGTEXT NOT NULL,
				queue VARCHAR(64) NOT NULL DEFAULT 'default',
				expression VARCHAR(255) NOT NULL,
				expression_type ENUM('interval', 'cron') NOT NULL,
				last_run DATETIME DEFAULT NULL,
				next_run DATETIME NOT NULL,
				overlap_policy ENUM('allow', 'skip', 'buffer') NOT NULL DEFAULT 'allow',
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE INDEX idx_handler (handler),
				INDEX idx_next_run (enabled, next_run)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$queue_states} (
				queue VARCHAR(64) NOT NULL PRIMARY KEY,
				paused TINYINT(1) NOT NULL DEFAULT 0,
				paused_at DATETIME DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$webhooks} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				event VARCHAR(64) NOT NULL,
				url VARCHAR(2048) NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_event (event)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$signals} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				signal_name VARCHAR(255) NOT NULL,
				payload LONGTEXT NOT NULL,
				received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_workflow_signal (workflow_id, signal_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$workflow_dependencies} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				waiting_workflow_id BIGINT UNSIGNED NOT NULL,
				step_index TINYINT UNSIGNED NOT NULL,
				dependency_workflow_id BIGINT UNSIGNED NOT NULL,
				satisfied_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE INDEX idx_waiting_dependency (waiting_workflow_id, step_index, dependency_workflow_id),
				INDEX idx_dependency (dependency_workflow_id, satisfied_at),
				INDEX idx_waiting (waiting_workflow_id, step_index, satisfied_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$workflow_keys} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				dispatch_key VARCHAR(191) NOT NULL,
				workflow_id BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE INDEX idx_dispatch_key (dispatch_key),
				UNIQUE INDEX idx_workflow_id (workflow_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$locks} (
				lock_key VARCHAR(255) NOT NULL PRIMARY KEY,
				owner VARCHAR(64) NOT NULL,
				acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires_at DATETIME DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$batches} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) DEFAULT NULL,
				total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
				pending_jobs INT UNSIGNED NOT NULL DEFAULT 0,
				failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
				failed_job_ids LONGTEXT NOT NULL,
				options LONGTEXT NOT NULL,
				cancelled_at DATETIME DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				finished_at DATETIME DEFAULT NULL,
				INDEX idx_finished (finished_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$chunks} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				job_id BIGINT UNSIGNED NOT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				chunk_index INT UNSIGNED NOT NULL,
				claim_token VARCHAR(64) NOT NULL DEFAULT '',
				content LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_job (job_id, chunk_index),
				UNIQUE INDEX idx_attempt_chunk (job_id, claim_token, chunk_index),
				INDEX idx_workflow_step (workflow_id, step_index)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$workflow_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				job_id BIGINT UNSIGNED DEFAULT NULL,
				parent_event_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED NOT NULL,
				step_name VARCHAR(191) DEFAULT NULL,
				step_type VARCHAR(64) DEFAULT NULL,
				handler VARCHAR(255) NOT NULL,
				event VARCHAR(64) NOT NULL,
				queue VARCHAR(64) DEFAULT NULL,
				attempt TINYINT UNSIGNED DEFAULT NULL,
				input LONGTEXT DEFAULT NULL,
				output LONGTEXT DEFAULT NULL,
				state_before LONGTEXT DEFAULT NULL,
				state_after LONGTEXT DEFAULT NULL,
				context LONGTEXT DEFAULT NULL,
				artifacts LONGTEXT DEFAULT NULL,
				chunks LONGTEXT DEFAULT NULL,
				error LONGTEXT DEFAULT NULL,
				duration_ms INT UNSIGNED DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_workflow (workflow_id, step_index),
				INDEX idx_timeline (workflow_id, id),
				INDEX idx_job (job_id),
				INDEX idx_step_name (workflow_id, step_name),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$artifacts} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				artifact_key VARCHAR(191) NOT NULL,
				kind VARCHAR(32) NOT NULL DEFAULT 'json',
				content LONGTEXT NOT NULL,
				metadata JSON DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				UNIQUE INDEX idx_workflow_key (workflow_id, artifact_key),
				INDEX idx_workflow_step (workflow_id, step_index),
				INDEX idx_workflow_kind (workflow_id, kind)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$state_machines} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				status ENUM('running', 'waiting_event', 'completed', 'failed', 'paused', 'cancelled') NOT NULL DEFAULT 'running',
				current_state VARCHAR(191) NOT NULL,
				revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
				state LONGTEXT NOT NULL,
				definition LONGTEXT NOT NULL,
				definition_hash VARCHAR(64) DEFAULT NULL,
				definition_version VARCHAR(64) DEFAULT NULL,
				idempotency_key VARCHAR(191) DEFAULT NULL,
				started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				completed_at DATETIME DEFAULT NULL,
				failed_at DATETIME DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				UNIQUE INDEX idx_dispatch_key (idempotency_key),
				INDEX idx_status_state (status, current_state),
				INDEX idx_name_status (name, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		self::create_table(
			$conn,
			"CREATE TABLE IF NOT EXISTS {$state_machine_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				machine_id BIGINT UNSIGNED NOT NULL,
				job_id BIGINT UNSIGNED DEFAULT NULL,
				state_name VARCHAR(191) NOT NULL,
				handler VARCHAR(255) DEFAULT NULL,
				event VARCHAR(64) NOT NULL,
				event_name VARCHAR(191) DEFAULT NULL,
				transition_name VARCHAR(191) DEFAULT NULL,
				queue VARCHAR(64) DEFAULT NULL,
				attempt TINYINT UNSIGNED DEFAULT NULL,
				input LONGTEXT DEFAULT NULL,
				output LONGTEXT DEFAULT NULL,
				state_before LONGTEXT DEFAULT NULL,
				state_after LONGTEXT DEFAULT NULL,
				context LONGTEXT DEFAULT NULL,
				artifacts LONGTEXT DEFAULT NULL,
				chunks LONGTEXT DEFAULT NULL,
				error LONGTEXT DEFAULT NULL,
				duration_ms INT UNSIGNED DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_machine (machine_id, id),
				INDEX idx_machine_event (machine_id, event),
				INDEX idx_job (job_id),
				INDEX idx_state (machine_id, state_name),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
	}

	/**
	 * Drop all Queuety tables.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function uninstall( Connection $conn ): void {
		$pdo                   = $conn->pdo();
		$jobs                  = $conn->table( Config::table_jobs() );
		$wf                    = $conn->table( Config::table_workflows() );
		$logs                  = $conn->table( Config::table_logs() );
		$schedules             = $conn->table( Config::table_schedules() );
		$queue_states          = $conn->table( Config::table_queue_states() );
		$webhooks              = $conn->table( Config::table_webhooks() );
		$signals               = $conn->table( Config::table_signals() );
		$workflow_dependencies = $conn->table( Config::table_workflow_dependencies() );
		$workflow_keys         = $conn->table( Config::table_workflow_dispatch_keys() );
		$locks                 = $conn->table( Config::table_locks() );
		$batches               = $conn->table( Config::table_batches() );
		$chunks                = $conn->table( Config::table_chunks() );
		$workflow_events       = $conn->table( Config::table_workflow_events() );
		$artifacts             = $conn->table( Config::table_artifacts() );
		$state_machines        = $conn->table( Config::table_state_machines() );
		$state_machine_events  = $conn->table( Config::table_state_machine_events() );

		$pdo->exec( "DROP TABLE IF EXISTS {$state_machine_events}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$state_machines}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$artifacts}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$workflow_events}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$chunks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$batches}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$workflow_keys}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$locks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$workflow_dependencies}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$signals}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$webhooks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$logs}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$schedules}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$queue_states}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$jobs}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$wf}" );
		$metadata = $conn->table( Config::table_schema() );
		$pdo->exec( "DROP TABLE IF EXISTS {$metadata}" );
	}

	/**
	 * Check if a table exists.
	 *
	 * @param Connection $conn  Database connection.
	 * @param string     $table Full table name.
	 * @return bool
	 */
	public static function table_exists( Connection $conn, string $table ): bool {
		// SHOW TABLES LIKE does not support prepared statement placeholders.
		$escaped = str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $table );
		$stmt    = $conn->pdo()->query( "SHOW TABLES LIKE '" . addslashes( $escaped ) . "'" );
		return false !== $stmt && false !== $stmt->fetch();
	}
}
