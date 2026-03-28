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
		$pdo             = $conn->pdo();
		$jobs            = $conn->table( Config::table_jobs() );
		$wf              = $conn->table( Config::table_workflows() );
		$logs            = $conn->table( Config::table_logs() );
		$schedules       = $conn->table( Config::table_schedules() );
		$queue_states    = $conn->table( Config::table_queue_states() );
		$webhooks        = $conn->table( Config::table_webhooks() );
		$signals         = $conn->table( Config::table_signals() );
		$locks           = $conn->table( Config::table_locks() );
		$batches         = $conn->table( Config::table_batches() );
		$chunks          = $conn->table( Config::table_chunks() );
		$workflow_events = $conn->table( Config::table_workflow_events() );

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
				heartbeat_data JSON DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_queue_status_available (queue, status, available_at, priority),
				INDEX idx_status (status),
				INDEX idx_reserved (status, reserved_at),
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
				status ENUM('running', 'completed', 'failed', 'paused', 'waiting_signal', 'cancelled') NOT NULL DEFAULT 'running',
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
				finished_at DATETIME DEFAULT NULL
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

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$workflow_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				step_index TINYINT UNSIGNED NOT NULL,
				handler VARCHAR(255) NOT NULL,
				event ENUM('step_started', 'step_completed', 'step_failed', 'state_snapshot', 'workflow_rewound', 'workflow_forked', 'workflow_deadline_exceeded') NOT NULL,
				state_snapshot LONGTEXT DEFAULT NULL,
				step_output LONGTEXT DEFAULT NULL,
				duration_ms INT UNSIGNED DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_workflow (workflow_id, step_index),
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
		$pdo             = $conn->pdo();
		$jobs            = $conn->table( Config::table_jobs() );
		$wf              = $conn->table( Config::table_workflows() );
		$logs            = $conn->table( Config::table_logs() );
		$schedules       = $conn->table( Config::table_schedules() );
		$queue_states    = $conn->table( Config::table_queue_states() );
		$webhooks        = $conn->table( Config::table_webhooks() );
		$signals         = $conn->table( Config::table_signals() );
		$locks           = $conn->table( Config::table_locks() );
		$batches         = $conn->table( Config::table_batches() );
		$chunks          = $conn->table( Config::table_chunks() );
		$workflow_events = $conn->table( Config::table_workflow_events() );

		$pdo->exec( "DROP TABLE IF EXISTS {$workflow_events}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$chunks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$batches}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$locks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$signals}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$webhooks}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$logs}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$schedules}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$queue_states}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$jobs}" );
		$pdo->exec( "DROP TABLE IF EXISTS {$wf}" );
	}

	/**
	 * Migrate from v0.5.x to v0.6.0.
	 *
	 * Adds the 'waiting_signal' value to the workflows status ENUM and
	 * creates the queuety_signals table for workflow signal support.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_060( Connection $conn ): void {
		$pdo     = $conn->pdo();
		$wf      = $conn->table( Config::table_workflows() );
		$signals = $conn->table( Config::table_signals() );

		$pdo->exec(
			"ALTER TABLE {$wf} MODIFY COLUMN status
			ENUM('running', 'completed', 'failed', 'paused', 'waiting_signal')
			NOT NULL DEFAULT 'running'"
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
	}

	/**
	 * Migrate from v0.6.x to v0.7.0.
	 *
	 * Adds the batch_id column to the jobs table and creates the
	 * queuety_batches table for job batching support.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_070( Connection $conn ): void {
		$pdo     = $conn->pdo();
		$jobs    = $conn->table( Config::table_jobs() );
		$batches = $conn->table( Config::table_batches() );

		$pdo->exec(
			"ALTER TABLE {$jobs} ADD COLUMN batch_id BIGINT UNSIGNED DEFAULT NULL AFTER depends_on"
		);

		$pdo->exec(
			"ALTER TABLE {$jobs} ADD INDEX idx_batch (batch_id)"
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
				finished_at DATETIME DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
	}

	/**
	 * Migrate from v0.7.x to v0.8.0.
	 *
	 * Adds 'cancelled' to the workflows status ENUM, 'workflow_cancelled' to
	 * the logs event ENUM, heartbeat_data column to jobs table, and
	 * overlap_policy column to schedules table.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_080( Connection $conn ): void {
		$pdo       = $conn->pdo();
		$wf        = $conn->table( Config::table_workflows() );
		$logs      = $conn->table( Config::table_logs() );
		$jobs      = $conn->table( Config::table_jobs() );
		$schedules = $conn->table( Config::table_schedules() );

		$pdo->exec(
			"ALTER TABLE {$wf} MODIFY COLUMN status
			ENUM('running', 'completed', 'failed', 'paused', 'waiting_signal', 'cancelled')
			NOT NULL DEFAULT 'running'"
		);

		$pdo->exec(
			"ALTER TABLE {$logs} MODIFY COLUMN event
			ENUM('started', 'completed', 'failed', 'buried', 'retried', 'workflow_started', 'workflow_completed', 'workflow_failed', 'workflow_paused', 'workflow_resumed', 'workflow_cancelled', 'debug')
			NOT NULL"
		);

		$pdo->exec(
			"ALTER TABLE {$jobs} ADD COLUMN heartbeat_data JSON DEFAULT NULL AFTER batch_id"
		);

		$pdo->exec(
			"ALTER TABLE {$schedules} ADD COLUMN overlap_policy ENUM('allow', 'skip', 'buffer') NOT NULL DEFAULT 'allow' AFTER next_run"
		);
	}

	/**
	 * Migrate from v0.8.x to v0.9.0.
	 *
	 * Creates the queuety_chunks table for durable streaming step support.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_090( Connection $conn ): void {
		$pdo    = $conn->pdo();
		$chunks = $conn->table( Config::table_chunks() );

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
	}

	/**
	 * Migrate from v0.10.x to v0.11.0.
	 *
	 * Creates the queuety_workflow_events table for workflow event log support.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_0110( Connection $conn ): void {
		$pdo             = $conn->pdo();
		$workflow_events = $conn->table( Config::table_workflow_events() );

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$workflow_events} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				workflow_id BIGINT UNSIGNED NOT NULL,
				step_index TINYINT UNSIGNED NOT NULL,
				handler VARCHAR(255) NOT NULL,
				event ENUM('step_started', 'step_completed', 'step_failed', 'state_snapshot') NOT NULL,
				state_snapshot LONGTEXT DEFAULT NULL,
				step_output LONGTEXT DEFAULT NULL,
				duration_ms INT UNSIGNED DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_workflow (workflow_id, step_index),
				INDEX idx_created (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
	}

	/**
	 * Migrate from v0.11.x to v0.12.0.
	 *
	 * Adds deadline_at column to workflows table, new event types to the logs
	 * and workflow_events ENUM columns for rewind, fork, and deadline features.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function migrate_0120( Connection $conn ): void {
		$pdo             = $conn->pdo();
		$wf              = $conn->table( Config::table_workflows() );
		$logs            = $conn->table( Config::table_logs() );
		$workflow_events = $conn->table( Config::table_workflow_events() );

		$pdo->exec(
			"ALTER TABLE {$wf} ADD COLUMN deadline_at DATETIME DEFAULT NULL AFTER error_message"
		);

		$pdo->exec(
			"ALTER TABLE {$wf} ADD INDEX idx_deadline (status, deadline_at)"
		);

		$pdo->exec(
			"ALTER TABLE {$logs} MODIFY COLUMN event
			ENUM('started', 'completed', 'failed', 'buried', 'retried', 'workflow_started', 'workflow_completed', 'workflow_failed', 'workflow_paused', 'workflow_resumed', 'workflow_cancelled', 'workflow_rewound', 'workflow_forked', 'workflow_deadline_exceeded', 'debug')
			NOT NULL"
		);

		$pdo->exec(
			"ALTER TABLE {$workflow_events} MODIFY COLUMN event
			ENUM('step_started', 'step_completed', 'step_failed', 'state_snapshot', 'workflow_rewound', 'workflow_forked', 'workflow_deadline_exceeded')
			NOT NULL"
		);
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
