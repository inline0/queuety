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
 * alive and optionally report progress. Every beat checks current database
 * ownership and refuses expired claims; the action deadline never moves.
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
	 * Opaque owner token for the current job.
	 *
	 * @var string|null
	 */
	private static ?string $claim_token = null;

	/**
	 * Requested heartbeat lease duration.
	 *
	 * @var int
	 */
	private static int $lease_seconds = 600;

	/**
	 * Initialize the heartbeat context for a job.
	 *
	 * Called by the Worker before processing a job.
	 *
	 * @param int         $job_id Job ID.
	 * @param Connection  $conn   Database connection.
	 * @param string|null $claim_token Current owner token, required before beating.
	 * @param int         $lease_seconds Requested lease extension in seconds.
	 */
	public static function init( int $job_id, Connection $conn, ?string $claim_token = null, int $lease_seconds = 600 ): void {
		self::$current_job_id = $job_id;
		self::$conn           = $conn;
		self::$claim_token    = $claim_token;
		self::$lease_seconds  = $lease_seconds;
	}

	/**
	 * Send a heartbeat, updating reserved_at and optionally storing progress data.
	 *
	 * @param array<string, mixed> $progress Optional progress data to store in heartbeat_data.
	 * @throws \Queuety\Exceptions\LostClaimException If no current owner token exists.
	 */
	public static function beat( array $progress = array() ): void {
		if ( null === self::$current_job_id || null === self::$conn ) {
			return;
		}

		if ( null === self::$claim_token ) {
			throw new \Queuety\Exceptions\LostClaimException( self::$current_job_id );
		}
		$queue = new Queue( self::$conn );
		$queue->heartbeat( self::$current_job_id, self::$claim_token, self::$lease_seconds, $progress );
	}

	/**
	 * Refuse handler effects after the current claim expires or changes owner.
	 *
	 * @throws \Queuety\Exceptions\LostClaimException If no current owner token exists.
	 */
	public static function assert_active(): void {
		if ( null === self::$current_job_id || null === self::$conn ) {
			return;
		}
		if ( null === self::$claim_token ) {
			throw new \Queuety\Exceptions\LostClaimException( self::$current_job_id );
		}
		$queue = new Queue( self::$conn );
		$queue->assert_claim( self::$current_job_id, self::$claim_token );
	}

	/**
	 * Clear the heartbeat context after a job completes.
	 *
	 * Called by the Worker after processing a job.
	 */
	public static function clear(): void {
		self::$current_job_id = null;
		self::$conn           = null;
		self::$claim_token    = null;
		self::$lease_seconds  = 600;
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
