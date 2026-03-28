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
		$pdo          = $conn->pdo();
		$jobs         = $conn->table( Config::table_jobs() );
		$wf           = $conn->table( Config::table_workflows() );
		$logs         = $conn->table( Config::table_logs() );
		$schedules    = $conn->table( Config::table_schedules() );
		$queue_states = $conn->table( Config::table_queue_states() );
		$webhooks     = $conn->table( Config::table_webhooks() );

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
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_queue_status_available (queue, status, available_at, priority),
				INDEX idx_status (status),
				INDEX idx_reserved (status, reserved_at),
				INDEX idx_workflow (workflow_id, step_index),
				INDEX idx_unique (handler, payload_hash, status),
				INDEX idx_depends (depends_on)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS {$wf} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				status ENUM('running', 'completed', 'failed', 'paused') NOT NULL DEFAULT 'running',
				state LONGTEXT NOT NULL,
				current_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
				total_steps TINYINT UNSIGNED NOT NULL,
				parent_workflow_id BIGINT UNSIGNED DEFAULT NULL,
				parent_step_index TINYINT UNSIGNED DEFAULT NULL,
				started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				completed_at DATETIME DEFAULT NULL,
				failed_at DATETIME DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				INDEX idx_status (status),
				INDEX idx_parent (parent_workflow_id)
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
				event ENUM('started', 'completed', 'failed', 'buried', 'retried', 'workflow_started', 'workflow_completed', 'workflow_failed', 'workflow_paused', 'workflow_resumed', 'debug') NOT NULL,
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
	}

	/**
	 * Drop all Queuety tables.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function uninstall( Connection $conn ): void {
		$pdo          = $conn->pdo();
		$jobs         = $conn->table( Config::table_jobs() );
		$wf           = $conn->table( Config::table_workflows() );
		$logs         = $conn->table( Config::table_logs() );
		$schedules    = $conn->table( Config::table_schedules() );
		$queue_states = $conn->table( Config::table_queue_states() );
		$webhooks     = $conn->table( Config::table_webhooks() );

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
