<?php
/**
 * Rate limiter for job handlers.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\LogEvent;

/**
 * Tracks per-handler execution counts in a sliding time window.
 *
 * Uses in-memory counters refreshed from the queuety_logs DB table every 5 seconds.
 */
class RateLimiter {

	/**
	 * Registered rate limits per handler.
	 *
	 * @var array<string, array{max: int, window: int}>
	 */
	private array $limits = array();

	/**
	 * In-memory execution counters per handler.
	 *
	 * @var array<string, int>
	 */
	private array $counters = array();

	/**
	 * Window start timestamps per handler.
	 *
	 * @var array<string, int>
	 */
	private array $window_starts = array();

	/**
	 * Last DB refresh timestamps per handler.
	 *
	 * @var array<string, float>
	 */
	private array $last_refresh = array();

	/**
	 * How often to refresh from DB, in seconds.
	 *
	 * @var int
	 */
	private const REFRESH_INTERVAL = 5;

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Set rate limit for a handler.
	 *
	 * @param string $handler        Handler name or class.
	 * @param int    $max_executions Maximum executions allowed in the window.
	 * @param int    $window_seconds Duration of the sliding window in seconds.
	 */
	public function register( string $handler, int $max_executions, int $window_seconds ): void {
		$this->limits[ $handler ] = array(
			'max'    => $max_executions,
			'window' => $window_seconds,
		);

		if ( ! isset( $this->counters[ $handler ] ) ) {
			$this->counters[ $handler ]      = 0;
			$this->window_starts[ $handler ] = time();
			$this->last_refresh[ $handler ]  = 0.0;
		}
	}

	/**
	 * Check if handler is at its rate limit.
	 *
	 * @param string $handler Handler name or class.
	 * @return bool True if the handler is currently rate-limited.
	 */
	public function is_limited( string $handler ): bool {
		if ( ! isset( $this->limits[ $handler ] ) ) {
			return false;
		}

		$limit = $this->limits[ $handler ];

		// Reset counter if the window has expired.
		$elapsed = time() - $this->window_starts[ $handler ];
		if ( $elapsed >= $limit['window'] ) {
			$this->counters[ $handler ]      = 0;
			$this->window_starts[ $handler ] = time();
			$this->last_refresh[ $handler ]  = 0.0;
		}

		// Refresh from DB if stale.
		$now = microtime( true );
		if ( ( $now - $this->last_refresh[ $handler ] ) >= self::REFRESH_INTERVAL ) {
			$this->refresh_from_db( $handler );
		}

		return $this->counters[ $handler ] >= $limit['max'];
	}

	/**
	 * Increment counter after successful execution.
	 *
	 * @param string $handler Handler name or class.
	 */
	public function record( string $handler ): void {
		if ( ! isset( $this->limits[ $handler ] ) ) {
			return;
		}

		++$this->counters[ $handler ];
	}

	/**
	 * Get seconds until the current window resets for a handler.
	 *
	 * @param string $handler Handler name or class.
	 * @return int Seconds until window resets. Returns 0 if handler is not registered.
	 */
	public function time_until_available( string $handler ): int {
		if ( ! isset( $this->limits[ $handler ] ) ) {
			return 0;
		}

		$limit   = $this->limits[ $handler ];
		$elapsed = time() - $this->window_starts[ $handler ];

		if ( $elapsed >= $limit['window'] ) {
			return 0;
		}

		return $limit['window'] - $elapsed;
	}

	/**
	 * Query completed events from logs table within the window.
	 *
	 * @param string $handler Handler name or class.
	 */
	private function refresh_from_db( string $handler ): void {
		$limit = $this->limits[ $handler ];
		$table = $this->conn->table( Config::table_logs() );
		$since = gmdate( 'Y-m-d H:i:s', $this->window_starts[ $handler ] );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) as cnt FROM {$table}
			WHERE handler = :handler
				AND event = :event
				AND created_at >= :since"
		);
		$stmt->execute(
			array(
				'handler' => $handler,
				'event'   => LogEvent::Completed->value,
				'since'   => $since,
			)
		);

		$row = $stmt->fetch();

		$this->counters[ $handler ]     = $row ? (int) $row['cnt'] : 0;
		$this->last_refresh[ $handler ] = microtime( true );
	}
}
