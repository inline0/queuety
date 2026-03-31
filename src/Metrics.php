<?php
/**
 * Metrics API for query throughput, latency, and error rates.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Provides methods to query throughput, latency, and error rates from the logs table.
 */
class Metrics {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Calculate jobs completed per minute in the given time window.
	 *
	 * @param string|null $handler Optional handler filter.
	 * @param int         $minutes Time window in minutes.
	 * @return float Jobs completed per minute.
	 */
	public function throughput( ?string $handler = null, int $minutes = 60 ): float {
		$table  = $this->conn->table( Config::table_logs() );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
		$params = array(
			'event' => 'completed',
			'since' => $since,
		);

		$sql = "SELECT COUNT(*) as cnt FROM {$table} WHERE event = :event AND created_at >= :since";

		if ( null !== $handler ) {
			$sql              .= ' AND handler = :handler';
			$params['handler'] = $handler;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$row = $stmt->fetch();

		$count = $row ? (int) $row['cnt'] : 0;

		if ( 0 === $minutes ) {
			return 0.0;
		}

		return $count / $minutes;
	}

	/**
	 * Calculate average duration in milliseconds for completed jobs.
	 *
	 * @param string|null $handler Optional handler filter.
	 * @param int         $minutes Time window in minutes.
	 * @return float Average duration in milliseconds.
	 */
	public function average_duration( ?string $handler = null, int $minutes = 60 ): float {
		$table  = $this->conn->table( Config::table_logs() );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
		$params = array(
			'event' => 'completed',
			'since' => $since,
		);

		$sql = "SELECT AVG(duration_ms) as avg_ms FROM {$table}
			WHERE event = :event AND created_at >= :since AND duration_ms IS NOT NULL";

		if ( null !== $handler ) {
			$sql              .= ' AND handler = :handler';
			$params['handler'] = $handler;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$row = $stmt->fetch();

		return $row && null !== $row['avg_ms'] ? (float) $row['avg_ms'] : 0.0;
	}

	/**
	 * Calculate the 95th percentile duration in milliseconds for completed jobs.
	 *
	 * @param string|null $handler Optional handler filter.
	 * @param int         $minutes Time window in minutes.
	 * @return float 95th percentile duration in milliseconds.
	 */
	public function p95_duration( ?string $handler = null, int $minutes = 60 ): float {
		$table  = $this->conn->table( Config::table_logs() );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
		$params = array(
			'event' => 'completed',
			'since' => $since,
		);

		$sql = "SELECT COUNT(*) AS cnt FROM {$table}
			WHERE event = :event AND created_at >= :since AND duration_ms IS NOT NULL";

		if ( null !== $handler ) {
			$sql              .= ' AND handler = :handler';
			$params['handler'] = $handler;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$row   = $stmt->fetch();
		$count = (int) ( $row['cnt'] ?? 0 );
		if ( 0 === $count ) {
			return 0.0;
		}

		$index = (int) ceil( 0.95 * $count ) - 1;
		$index = max( 0, min( $index, $count - 1 ) );

		$sql = "SELECT duration_ms FROM {$table}
			WHERE event = :event AND created_at >= :since AND duration_ms IS NOT NULL";

		if ( null !== $handler ) {
			$sql .= ' AND handler = :handler';
		}

		$sql .= " ORDER BY duration_ms ASC LIMIT 1 OFFSET {$index}";

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$row = $stmt->fetch();

		return null !== ( $row['duration_ms'] ?? null ) ? (float) $row['duration_ms'] : 0.0;
	}

	/**
	 * Calculate the ratio of failed jobs to total jobs.
	 *
	 * @param string|null $handler Optional handler filter.
	 * @param int         $minutes Time window in minutes.
	 * @return float Error rate from 0.0 to 1.0.
	 */
	public function error_rate( ?string $handler = null, int $minutes = 60 ): float {
		$table  = $this->conn->table( Config::table_logs() );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );
		$params = array( 'since' => $since );

		$sql = "SELECT event, COUNT(*) as cnt FROM {$table}
			WHERE event IN ('completed', 'failed', 'buried') AND created_at >= :since";

		if ( null !== $handler ) {
			$sql              .= ' AND handler = :handler';
			$params['handler'] = $handler;
		}

		$sql .= ' GROUP BY event';

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		$completed = 0;
		$failed    = 0;
		while ( true ) {
			$row = $stmt->fetch();
			if ( false === $row ) {
				break;
			}

			if ( 'completed' === $row['event'] ) {
				$completed = (int) $row['cnt'];
			} elseif ( 'failed' === $row['event'] || 'buried' === $row['event'] ) {
				$failed += (int) $row['cnt'];
			}
		}

		$total = $completed + $failed;
		if ( 0 === $total ) {
			return 0.0;
		}

		return $failed / $total;
	}

	/**
	 * Get per-handler breakdown of stats.
	 *
	 * @param int $minutes Time window in minutes.
	 * @return array Array of associative arrays with keys: handler, completed, failed,
	 *               avg_ms, p95_ms, error_rate.
	 */
	public function handler_stats( int $minutes = 60 ): array {
		$table = $this->conn->table( Config::table_logs() );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT handler, event, COUNT(*) as cnt, AVG(duration_ms) as avg_ms
			FROM {$table}
			WHERE event IN ('completed', 'failed', 'buried')
				AND created_at >= :since
				AND handler != ''
			GROUP BY handler, event"
		);
		$stmt->execute( array( 'since' => $since ) );

		$handlers = array();
		while ( true ) {
			$row = $stmt->fetch();
			if ( false === $row ) {
				break;
			}

			$h = $row['handler'];
			if ( ! isset( $handlers[ $h ] ) ) {
				$handlers[ $h ] = array(
					'handler'   => $h,
					'completed' => 0,
					'failed'    => 0,
					'avg_ms'    => 0.0,
				);
			}

			if ( 'completed' === $row['event'] ) {
				$handlers[ $h ]['completed'] = (int) $row['cnt'];
				$handlers[ $h ]['avg_ms']    = round( (float) $row['avg_ms'], 2 );
			} elseif ( 'failed' === $row['event'] || 'buried' === $row['event'] ) {
				$handlers[ $h ]['failed'] += (int) $row['cnt'];
			}
		}

		$result = array();
		foreach ( $handlers as $h => $data ) {
			$total      = $data['completed'] + $data['failed'];
			$error_rate = $total > 0 ? round( $data['failed'] / $total, 4 ) : 0.0;

			$result[] = array(
				'handler'    => $data['handler'],
				'completed'  => $data['completed'],
				'failed'     => $data['failed'],
				'avg_ms'     => $data['avg_ms'],
				'p95_ms'     => $this->p95_duration( $h, $minutes ),
				'error_rate' => $error_rate,
			);
		}

		return $result;
	}
}
