<?php
/**
 * Logger for queue events.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\LogEvent;

/**
 * Writes log entries to the queuety_logs database table.
 */
class Logger {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Write a log entry.
	 *
	 * @param LogEvent $event Log event type.
	 * @param array    $data  Log data. Supported keys: job_id, workflow_id, step_index,
	 *                        handler, queue, attempt, duration_ms, memory_peak_kb,
	 *                        error_message, error_class, error_trace, context.
	 * @return int The new log entry ID.
	 */
	public function log( LogEvent $event, array $data = array() ): int {
		$table = $this->conn->table( Config::table_logs() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(job_id, workflow_id, step_index, handler, queue, event, attempt,
				 duration_ms, memory_peak_kb, error_message, error_class, error_trace, context)
			VALUES
				(:job_id, :workflow_id, :step_index, :handler, :queue, :event, :attempt,
				 :duration_ms, :memory_peak_kb, :error_message, :error_class, :error_trace, :context)"
		);

		$context = $data['context'] ?? null;
		if ( is_array( $context ) ) {
			$context = json_encode( $context, JSON_THROW_ON_ERROR );
		}

		$stmt->execute(
			array(
				'job_id'         => $data['job_id'] ?? null,
				'workflow_id'    => $data['workflow_id'] ?? null,
				'step_index'     => $data['step_index'] ?? null,
				'handler'        => $data['handler'] ?? '',
				'queue'          => $data['queue'] ?? 'default',
				'event'          => $event->value,
				'attempt'        => $data['attempt'] ?? null,
				'duration_ms'    => $data['duration_ms'] ?? null,
				'memory_peak_kb' => $data['memory_peak_kb'] ?? null,
				'error_message'  => $data['error_message'] ?? null,
				'error_class'    => $data['error_class'] ?? null,
				'error_trace'    => $data['error_trace'] ?? null,
				'context'        => $context,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Get log entries for a specific job.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public function for_job( int $job_id ): array {
		$table = $this->conn->table( Config::table_logs() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE job_id = :job_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'job_id' => $job_id ) );
		return $stmt->fetchAll();
	}

	/**
	 * Get log entries for a specific workflow.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	public function for_workflow( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_logs() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		return $stmt->fetchAll();
	}

	/**
	 * Get log entries by handler name.
	 *
	 * @param string   $handler Handler name.
	 * @param int|null $limit   Max entries to return.
	 * @return array
	 */
	public function for_handler( string $handler, ?int $limit = null ): array {
		$table = $this->conn->table( Config::table_logs() );
		$sql   = "SELECT * FROM {$table} WHERE handler = :handler ORDER BY id DESC";
		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit}";
		}
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'handler' => $handler ) );
		return $stmt->fetchAll();
	}

	/**
	 * Get log entries by event type.
	 *
	 * @param LogEvent $event Event type.
	 * @param int|null $limit Max entries to return.
	 * @return array
	 */
	public function for_event( LogEvent $event, ?int $limit = null ): array {
		$table = $this->conn->table( Config::table_logs() );
		$sql   = "SELECT * FROM {$table} WHERE event = :event ORDER BY id DESC";
		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit}";
		}
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'event' => $event->value ) );
		return $stmt->fetchAll();
	}

	/**
	 * Get log entries since a given timestamp.
	 *
	 * @param \DateTimeImmutable $since Start time.
	 * @param int|null           $limit Max entries to return.
	 * @return array
	 */
	public function since( \DateTimeImmutable $since, ?int $limit = null ): array {
		$table = $this->conn->table( Config::table_logs() );
		$sql   = "SELECT * FROM {$table} WHERE created_at >= :since ORDER BY id ASC";
		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit}";
		}
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'since' => $since->format( 'Y-m-d H:i:s' ) ) );
		return $stmt->fetchAll();
	}

	/**
	 * Purge log entries older than N days.
	 *
	 * @param int $older_than_days Delete entries older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function purge( int $older_than_days ): int {
		$table  = $this->conn->table( Config::table_logs() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE created_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}
}
