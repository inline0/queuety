<?php
/**
 * Attempt-scoped persistence for resumable streaming steps.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Exceptions\LostClaimException;

/** Stores stream chunks without allowing superseded attempts to overwrite them. */
class ChunkStore {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct( private readonly Connection $conn ) {}

	/**
	 * Resolve the active attempt when the caller is executing this job.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $token  Explicit attempt token.
	 */
	private function token( int $job_id, ?string $token ): ?string {
		$job = ExecutionContext::job();
		return $token ?? ( null !== $job && $job->id === $job_id ? $job->claim_token : null );
	}

	/**
	 * Read one attempt's chunks, or all historical chunks outside execution.
	 *
	 * @param int         $job_id      Job ID.
	 * @param string|null $claim_token Optional attempt to inspect.
	 * @return string[] Persisted contents in chunk order.
	 */
	public function get_chunks( int $job_id, ?string $claim_token = null ): array {
		$token = $this->token( $job_id, $claim_token );
		$table = $this->conn->table( Config::table_chunks() );
		$sql = "SELECT content FROM {$table} WHERE job_id = :job_id";
		$params = array( 'job_id' => $job_id );
		if ( null !== $token ) {
			$sql .= ' AND claim_token = :token';
			$params['token'] = $token;
		}
		$stmt = $this->conn->pdo()->prepare( $sql . ' ORDER BY chunk_index ASC, id ASC' );
		$stmt->execute( $params );
		$chunks = array();
		foreach ( $stmt->fetchAll() as $row ) {
			if ( is_array( $row ) ) {
				$content = $row['content'] ?? '';
				$chunks[] = is_scalar( $content ) ? (string) $content : '';
			}
		}
		return $chunks;
	}

	/**
	 * Copy the last durable attempt into the current claim before resuming.
	 *
	 * The claim lock prevents a previous owner from appending while the new
	 * owner takes its resumable snapshot. Copies retain independent ownership.
	 *
	 * @param Job $job Current claimed job.
	 * @return string[] Resumable chunk contents for this attempt.
	 * @throws LostClaimException When the job has no active claim.
	 */
	public function resume_chunks( Job $job ): array {
		if ( null === $job->claim_token ) {
			throw new LostClaimException( $job->id );
		}
		$queue = new Queue( $this->conn );
		$queue->with_claim(
			$job->id,
			$job->claim_token,
			function () use ( $job ): void {
				$table = $this->conn->table( Config::table_chunks() );
				$pdo = $this->conn->pdo();
				$stmt = $pdo->prepare( "SELECT id FROM {$table} WHERE job_id = :id AND claim_token = :token LIMIT 1" );
				$stmt->execute(
					array(
						'id' => $job->id,
						'token' => $job->claim_token,
					)
				);
				if ( false !== $stmt->fetch() ) {
					return;
				}
				$stmt = $pdo->prepare( "SELECT claim_token FROM {$table} WHERE job_id = :id AND claim_token NOT LIKE 'legacy:%' ORDER BY id DESC LIMIT 1" );
				$stmt->execute( array( 'id' => $job->id ) );
				$row = $stmt->fetch();
				if ( ! is_array( $row ) || ! is_string( $row['claim_token'] ?? null ) ) {
					return;
				}
				$stmt = $pdo->prepare( "INSERT INTO {$table} (job_id, workflow_id, step_index, claim_token, chunk_index, content) SELECT job_id, workflow_id, step_index, :token, chunk_index, content FROM {$table} WHERE job_id = :id AND claim_token = :previous" );
				$stmt->execute(
					array(
						'token' => $job->claim_token,
						'id' => $job->id,
						'previous' => $row['claim_token'],
					)
				);
			}
		);
		return $this->get_chunks( $job->id, $job->claim_token );
	}

	/**
	 * Append a chunk while atomically validating the producing claim.
	 *
	 * @param int         $job_id      Job ID.
	 * @param int         $chunk_index Zero-based chunk offset.
	 * @param string      $content     Chunk content.
	 * @param int|null    $workflow_id Optional workflow ID.
	 * @param int|null    $step_index  Optional workflow step.
	 * @param string|null $claim_token Explicit attempt token outside ExecutionContext.
	 * @throws LostClaimException When a leased job is written without its token.
	 */
	public function append_chunk( int $job_id, int $chunk_index, string $content, ?int $workflow_id = null, ?int $step_index = null, ?string $claim_token = null ): void {
		$token = $this->token( $job_id, $claim_token );
		$write = function () use ( $job_id, $chunk_index, $content, $workflow_id, $step_index, $token ): void {
			$table = $this->conn->table( Config::table_chunks() );
			$stmt = $this->conn->pdo()->prepare( "INSERT INTO {$table} (job_id, workflow_id, step_index, claim_token, chunk_index, content) VALUES (:job_id, :workflow_id, :step_index, :token, :chunk_index, :content)" );
			$stmt->execute(
				array(
					'job_id' => $job_id,
					'workflow_id' => $workflow_id,
					'step_index' => $step_index,
					'token' => $token ?? '',
					'chunk_index' => $chunk_index,
					'content' => $content,
				)
			);
		};
		$queue = new Queue( $this->conn );
		if ( null !== $token ) {
			$queue->with_claim( $job_id, $token, $write );
			return;
		}
		$job = $queue->find( $job_id );
		if ( null !== $job && null !== $job->claim_token ) {
			throw new LostClaimException( $job_id );
		}
		$write();
	}

	/**
	 * Remove committed stream history without touching a newer attempt.
	 *
	 * Outside execution, omitting the token explicitly removes all job chunks.
	 *
	 * @param int         $job_id      Job ID.
	 * @param string|null $claim_token Attempt to remove.
	 */
	public function clear_chunks( int $job_id, ?string $claim_token = null ): void {
		$token = $this->token( $job_id, $claim_token );
		$table = $this->conn->table( Config::table_chunks() );
		$sql = "DELETE FROM {$table} WHERE job_id = :id";
		$params = array( 'id' => $job_id );
		if ( null !== $token ) {
			$jobs = $this->conn->table( Config::table_jobs() );
			$cutoff = $this->conn->pdo()->prepare( "SELECT MAX(c.id) FROM {$table} c INNER JOIN {$jobs} j ON j.id = c.job_id WHERE c.job_id = :id AND c.claim_token = :token AND j.status = 'completed'" );
			$cutoff->execute(
				array(
					'id' => $job_id,
					'token' => $token,
				)
			);
			$last_id = $cutoff->fetchColumn();
			if ( is_numeric( $last_id ) ) {
				$sql .= ' AND id <= :last_id';
				$params['last_id'] = (int) $last_id;
			} else {
				$sql .= ' AND claim_token = :token';
				$params['token'] = $token;
			}
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
	}

	/**
	 * Count persisted chunks for a job or attempt.
	 *
	 * @param int         $job_id      Job ID.
	 * @param string|null $claim_token Optional attempt token.
	 */
	public function chunk_count( int $job_id, ?string $claim_token = null ): int {
		return count( $this->get_chunks( $job_id, $claim_token ) );
	}

	/**
	 * Concatenate a job or attempt's persisted stream.
	 *
	 * @param int         $job_id      Job ID.
	 * @param string|null $claim_token Optional attempt token.
	 */
	public function get_accumulated( int $job_id, ?string $claim_token = null ): string {
		return implode( '', $this->get_chunks( $job_id, $claim_token ) );
	}
}
