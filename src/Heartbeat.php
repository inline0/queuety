<?php
/**
 * Activity heartbeat for long-running steps.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Static helper for sending heartbeats from inside step handlers.
 *
 * Long-running steps can call Heartbeat::beat() to prove they are still
 * alive and optionally report progress. The stale detector respects
 * heartbeats by checking the reserved_at timestamp.
 *
 * @example
 * class LongRunningStep implements Step {
 *     public function handle( array $state ): array {
 *         foreach ( $items as $i => $item ) {
 *             process( $item );
 *             Heartbeat::beat( [ 'processed' => $i + 1 ] );
 *         }
 *         return [ 'done' => true ];
 *     }
 * }
 */
class Heartbeat {

	/**
	 * The current job ID being processed.
	 *
	 * @var int|null
	 */
	private static ?int $current_job_id = null;

	/**
	 * The database connection for heartbeat updates.
	 *
	 * @var Connection|null
	 */
	private static ?Connection $conn = null;

	/**
	 * Initialize the heartbeat context for a job.
	 *
	 * Called by the Worker before processing a job.
	 *
	 * @param int        $job_id Job ID.
	 * @param Connection $conn   Database connection.
	 */
	public static function init( int $job_id, Connection $conn ): void {
		self::$current_job_id = $job_id;
		self::$conn           = $conn;
	}

	/**
	 * Send a heartbeat, updating reserved_at and optionally storing progress data.
	 *
	 * @param array $progress Optional progress data to store in heartbeat_data.
	 */
	public static function beat( array $progress = array() ): void {
		if ( null === self::$current_job_id || null === self::$conn ) {
			return;
		}

		$table = self::$conn->table( Config::table_jobs() );

		if ( ! empty( $progress ) ) {
			$stmt = self::$conn->pdo()->prepare(
				"UPDATE {$table}
				SET reserved_at = NOW(), heartbeat_data = :heartbeat_data
				WHERE id = :id"
			);
			$stmt->execute(
				array(
					'heartbeat_data' => json_encode( $progress, JSON_THROW_ON_ERROR ),
					'id'             => self::$current_job_id,
				)
			);
		} else {
			$stmt = self::$conn->pdo()->prepare(
				"UPDATE {$table} SET reserved_at = NOW() WHERE id = :id"
			);
			$stmt->execute(
				array(
					'id' => self::$current_job_id,
				)
			);
		}
	}

	/**
	 * Clear the heartbeat context after a job completes.
	 *
	 * Called by the Worker after processing a job.
	 */
	public static function clear(): void {
		self::$current_job_id = null;
		self::$conn           = null;
	}

	/**
	 * Get the current job ID (for testing).
	 *
	 * @return int|null
	 */
	public static function current_job_id(): ?int {
		return self::$current_job_id;
	}
}
