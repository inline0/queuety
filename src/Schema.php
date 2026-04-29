<?php
/**
 * Database schema management.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Creates and drops the Queuety database tables.
 */
class Schema {

	/**
	 * Create all Queuety tables.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function install( Connection $conn ): void {
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

		$pdo->exec(
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
				available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				reserved_at DATETIME DEFAULT NULL,
				completed_at DATETIME DEFAULT NULL,
				failed_at DATETIME DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
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
				INDEX idx_handler_status (handler, status),
				INDEX idx_group_status (concurrency_group, status),
				INDEX idx_workflow (workflow_id, step_index),
				INDEX idx_unique (handler, payload_hash, status),
				INDEX idx_depends (depends_on),
				INDEX idx_batch (batch_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$wf} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				status ENUM('running', 'completed', 'failed', 'paused', 'waiting_for_signal', 'waiting_for_workflows', 'cancelled') NOT NULL DEFAULT 'running',
				state LONGTEXT NOT NULL,
				current_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$logs} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				job_id BIGINT UNSIGNED DEFAULT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				handler VARCHAR(255) NOT NULL,
				queue VARCHAR(64) NOT NULL DEFAULT 'default',
				event ENUM('started', 'completed', 'failed', 'buried', 'retried', 'workflow_started', 'workflow_completed', 'workflow_failed', 'workflow_paused', 'workflow_resumed', 'workflow_cancelled', 'workflow_rewound', 'workflow_forked', 'workflow_deadline_exceeded', 'debug') NOT NULL,
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

		$pdo->exec(
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$queue_states} (
				queue VARCHAR(64) NOT NULL PRIMARY KEY,
				paused TINYINT(1) NOT NULL DEFAULT 0,
				paused_at DATETIME DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$webhooks} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				event VARCHAR(64) NOT NULL,
				url VARCHAR(2048) NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_event (event)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$signals} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				signal_name VARCHAR(255) NOT NULL,
				payload LONGTEXT NOT NULL,
				received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_workflow_signal (workflow_id, signal_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$workflow_keys} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				dispatch_key VARCHAR(191) NOT NULL,
				workflow_id BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE INDEX idx_dispatch_key (dispatch_key),
				UNIQUE INDEX idx_workflow_id (workflow_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$locks} (
				lock_key VARCHAR(255) NOT NULL PRIMARY KEY,
				owner VARCHAR(64) NOT NULL,
				acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires_at DATETIME DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$chunks} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				job_id BIGINT UNSIGNED NOT NULL,
				workflow_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED DEFAULT NULL,
				chunk_index INT UNSIGNED NOT NULL,
				content LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_job (job_id, chunk_index),
				INDEX idx_workflow_step (workflow_id, step_index)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		if ( self::table_exists( $conn, $workflow_events ) ) {
			$columns = $pdo->query( "SHOW COLUMNS FROM {$workflow_events}" )->fetchAll();
			$names   = array_map( static fn( array $column ): string => (string) $column['Field'], $columns );
			if ( ! in_array( 'state_after', $names, true ) ) {
				$pdo->exec( "DROP TABLE IF EXISTS {$workflow_events}" );
			}
		}

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$workflow_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				job_id BIGINT UNSIGNED DEFAULT NULL,
				parent_event_id BIGINT UNSIGNED DEFAULT NULL,
				step_index TINYINT UNSIGNED NOT NULL,
				step_name VARCHAR(191) DEFAULT NULL,
				step_type VARCHAR(64) DEFAULT NULL,
				handler VARCHAR(255) NOT NULL,
				event ENUM('step_started', 'step_completed', 'step_failed', 'step_branch_completed', 'step_item_completed', 'step_item_failed', 'workflow_rewound', 'workflow_forked', 'workflow_deadline_exceeded', 'workflow_waiting', 'workflow_resumed', 'workflow_replayed') NOT NULL,
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

		$pdo->exec(
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$state_machines} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				status ENUM('running', 'waiting_event', 'completed', 'failed', 'paused', 'cancelled') NOT NULL DEFAULT 'running',
				current_state VARCHAR(191) NOT NULL,
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$state_machine_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				machine_id BIGINT UNSIGNED NOT NULL,
				state_name VARCHAR(191) NOT NULL,
				event ENUM('machine_started', 'machine_waiting', 'event_received', 'transitioned', 'action_started', 'action_completed', 'action_failed', 'machine_completed', 'machine_failed', 'machine_paused', 'machine_resumed', 'machine_cancelled') NOT NULL,
				event_name VARCHAR(191) DEFAULT NULL,
				state_snapshot LONGTEXT DEFAULT NULL,
				payload LONGTEXT DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_machine (machine_id, id),
				INDEX idx_machine_event (machine_id, event),
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
		return (bool) $stmt->fetch();
	}
}
