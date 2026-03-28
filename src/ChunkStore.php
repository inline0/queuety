<?php
/**
 * Chunk persistence store for streaming steps.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Manages chunk persistence for streaming step handlers.
 *
 * Chunks are stored in the queuety_chunks table and are keyed by job_id
 * and chunk_index. This enables durable streaming: if a step fails
 * mid-stream, already-persisted chunks survive and are available on retry.
 */
class ChunkStore {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Fetch all chunks for a job, ordered by chunk_index.
	 *
	 * @param int $job_id Job ID.
	 * @return string[] Array of chunk content strings.
	 */
	public function get_chunks( int $job_id ): array {
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT content FROM {$table}
			WHERE job_id = :job_id
			ORDER BY chunk_index ASC"
		);
		$stmt->execute( array( 'job_id' => $job_id ) );

		return array_column( $stmt->fetchAll(), 'content' );
	}

	/**
	 * Append a single chunk for a job.
	 *
	 * @param int      $job_id      Job ID.
	 * @param int      $chunk_index Zero-based chunk index.
	 * @param string   $content     Chunk content.
	 * @param int|null $workflow_id  Optional workflow ID.
	 * @param int|null $step_index   Optional step index within the workflow.
	 */
	public function append_chunk(
		int $job_id,
		int $chunk_index,
		string $content,
		?int $workflow_id = null,
		?int $step_index = null,
	): void {
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(job_id, workflow_id, step_index, chunk_index, content)
			VALUES
				(:job_id, :workflow_id, :step_index, :chunk_index, :content)"
		);
		$stmt->execute(
			array(
				'job_id'      => $job_id,
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
				'chunk_index' => $chunk_index,
				'content'     => $content,
			)
		);
	}

	/**
	 * Delete all chunks for a job.
	 *
	 * @param int $job_id Job ID.
	 */
	public function clear_chunks( int $job_id ): void {
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE job_id = :job_id"
		);
		$stmt->execute( array( 'job_id' => $job_id ) );
	}

	/**
	 * Count the number of chunks for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return int Number of chunks.
	 */
	public function chunk_count( int $job_id ): int {
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) AS cnt FROM {$table} WHERE job_id = :job_id"
		);
		$stmt->execute( array( 'job_id' => $job_id ) );
		$row = $stmt->fetch();

		return (int) ( $row['cnt'] ?? 0 );
	}

	/**
	 * Concatenate all chunks for a job into one string.
	 *
	 * @param int $job_id Job ID.
	 * @return string Concatenated chunk content.
	 */
	public function get_accumulated( int $job_id ): string {
		return implode( '', $this->get_chunks( $job_id ) );
	}
}
