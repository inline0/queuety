<?php
/**
 * Batch manager for tracking batch lifecycle.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Manages batch state: creation, completion tracking, failure tracking, cancellation.
 */
class BatchManager {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Create a new batch row.
	 *
	 * @param int         $total_jobs Total number of jobs in the batch.
	 * @param string|null $name       Optional batch name.
	 * @param array       $options    Batch options (callback classes, etc.).
	 * @return int The new batch ID.
	 */
	public function create( int $total_jobs, ?string $name = null, array $options = array() ): int {
		$table = $this->conn->table( Config::table_batches() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options)
			VALUES
				(:name, :total_jobs, :pending_jobs, :failed_jobs, :failed_job_ids, :options)"
		);

		$stmt->execute(
			array(
				'name'           => $name,
				'total_jobs'     => $total_jobs,
				'pending_jobs'   => $total_jobs,
				'failed_jobs'    => 0,
				'failed_job_ids' => '[]',
				'options'        => json_encode( $options, JSON_THROW_ON_ERROR ),
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Find a batch by ID.
	 *
	 * @param int $id Batch ID.
	 * @return Batch|null
	 */
	public function find( int $id ): ?Batch {
		$table = $this->conn->table( Config::table_batches() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $id ) );
		$row = $stmt->fetch();

		return $row ? Batch::from_row( $row ) : null;
	}

	/**
	 * Record a job completion for a batch.
	 *
	 * Decrements pending_jobs. If all jobs are done, sets finished_at
	 * and fires the then/finally callbacks.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function record_completion( int $batch_id ): void {
		$table = $this->conn->table( Config::table_batches() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET pending_jobs = GREATEST(pending_jobs - 1, 0)
			WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $batch_id ) );

		$this->check_finished( $batch_id );
	}

	/**
	 * Record a job failure for a batch.
	 *
	 * Decrements pending_jobs, increments failed_jobs, appends
	 * the job ID to failed_job_ids. Fires catch callback on first failure.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $job_id   The failed job ID.
	 */
	public function record_failure( int $batch_id, int $job_id ): void {
		$table = $this->conn->table( Config::table_batches() );

		// Fetch current state for failed_job_ids update.
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$failed_ids   = $batch->failed_job_ids;
		$failed_ids[] = $job_id;

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET pending_jobs = GREATEST(pending_jobs - 1, 0),
				failed_jobs = failed_jobs + 1,
				failed_job_ids = :failed_job_ids
			WHERE id = :id"
		);
		$stmt->execute(
			array(
				'id'             => $batch_id,
				'failed_job_ids' => json_encode( $failed_ids, JSON_THROW_ON_ERROR ),
			)
		);

		// Fire catch callback on first failure.
		if ( 0 === $batch->failed_jobs ) {
			$this->fire_callback( $batch_id, 'catch' );
		}

		$this->check_finished( $batch_id );
	}

	/**
	 * Cancel a batch.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function cancel( int $batch_id ): void {
		$table = $this->conn->table( Config::table_batches() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET cancelled_at = NOW(), finished_at = NOW()
			WHERE id = :id AND cancelled_at IS NULL"
		);
		$stmt->execute( array( 'id' => $batch_id ) );

		$this->fire_callback( $batch_id, 'finally' );
	}

	/**
	 * Prune finished batches older than N days.
	 *
	 * @param int $days Delete batches older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days ): int {
		$table  = $this->conn->table( Config::table_batches() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE finished_at IS NOT NULL AND finished_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}

	/**
	 * Check if a batch is finished and fire appropriate callbacks.
	 *
	 * @param int $batch_id Batch ID.
	 */
	private function check_finished( int $batch_id ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch || $batch->finished() ) {
			return;
		}

		if ( $batch->pending_jobs > 0 ) {
			return;
		}

		// Check allow_failures option.
		$allow_failures = ! empty( $batch->options['allow_failures'] );

		// All jobs are done. Mark as finished.
		$table = $this->conn->table( Config::table_batches() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET finished_at = NOW() WHERE id = :id AND finished_at IS NULL"
		);
		$stmt->execute( array( 'id' => $batch_id ) );

		// Fire then callback only if no failures (or allow_failures is set).
		if ( ! $batch->has_failures() || $allow_failures ) {
			$this->fire_callback( $batch_id, 'then' );
		}

		// Always fire finally callback.
		$this->fire_callback( $batch_id, 'finally' );
	}

	/**
	 * Fire a batch callback by type.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $type     Callback type: 'then', 'catch', or 'finally'.
	 */
	private function fire_callback( int $batch_id, string $type ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$handler_class = $batch->options[ $type ] ?? null;
		if ( null === $handler_class || ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
			return;
		}

		try {
			$handler = new $handler_class();
			$handler->handle( $batch );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Callback failures are non-fatal.
			unset( $e );
		}
	}
}
