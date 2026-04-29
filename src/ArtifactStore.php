<?php
/**
 * Durable workflow artifact storage.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Stores workflow artifacts outside the main workflow state payload.
 */
class ArtifactStore {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Store or replace one artifact for a workflow.
	 *
	 * @param int      $workflow_id  Workflow ID.
	 * @param string   $artifact_key Artifact key.
	 * @param mixed    $content      Artifact content.
	 * @param string   $kind         Artifact kind, such as json, text, or markdown.
	 * @param int|null $step_index   Related workflow step index, if any.
	 * @param array    $metadata     Optional metadata.
	 * @throws \InvalidArgumentException If the workflow ID, key, or kind is invalid.
	 */
	public function put(
		int $workflow_id,
		string $artifact_key,
		mixed $content,
		string $kind = 'json',
		?int $step_index = null,
		array $metadata = array(),
	): void {
		if ( $workflow_id < 1 ) {
			throw new \InvalidArgumentException( 'Artifacts require a valid workflow ID.' );
		}

		$artifact_key = trim( $artifact_key );
		$kind         = trim( $kind );

		if ( '' === $artifact_key ) {
			throw new \InvalidArgumentException( 'Artifacts require a non-empty key.' );
		}

		if ( '' === $kind ) {
			throw new \InvalidArgumentException( 'Artifacts require a non-empty kind.' );
		}

		$table = $this->conn->table( Config::table_artifacts() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, artifact_key, kind, content, metadata, step_index)
			VALUES
				(:workflow_id, :artifact_key, :kind, :content, :metadata, :step_index)
			ON DUPLICATE KEY UPDATE
				kind = VALUES(kind),
				content = VALUES(content),
				metadata = VALUES(metadata),
				step_index = VALUES(step_index),
				updated_at = CURRENT_TIMESTAMP"
		);

		$stmt->execute(
			array(
				'workflow_id'  => $workflow_id,
				'artifact_key' => $artifact_key,
				'kind'         => $kind,
				'content'      => $this->encode_content( $content, $kind ),
				'metadata'     => json_encode( $metadata, JSON_THROW_ON_ERROR ),
				'step_index'   => $step_index,
			)
		);

		if ( ExecutionContext::workflow_id() === $workflow_id ) {
			ExecutionContext::add_trace_artifact(
				array(
					'key'        => $artifact_key,
					'kind'       => $kind,
					'step_index' => $step_index,
					'metadata'   => $metadata,
				)
			);
		}
	}

	/**
	 * Get one artifact for a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param string $artifact_key Artifact key.
	 * @return array<string,mixed>|null
	 */
	public function get( int $workflow_id, string $artifact_key ): ?array {
		$table = $this->conn->table( Config::table_artifacts() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table}
			WHERE workflow_id = :workflow_id AND artifact_key = :artifact_key
			LIMIT 1"
		);
		$stmt->execute(
			array(
				'workflow_id'  => $workflow_id,
				'artifact_key' => trim( $artifact_key ),
			)
		);

		$row = $stmt->fetch();
		return $row ? $this->map_row( $row, true ) : null;
	}

	/**
	 * List artifacts for one workflow.
	 *
	 * @param int  $workflow_id    Workflow ID.
	 * @param bool $include_content Whether to include decoded content.
	 * @return array<int,array<string,mixed>>
	 */
	public function list( int $workflow_id, bool $include_content = false ): array {
		$table = $this->conn->table( Config::table_artifacts() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table}
			WHERE workflow_id = :workflow_id
			ORDER BY artifact_key ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map(
			fn( array $row ): array => $this->map_row( $row, $include_content ),
			$stmt->fetchAll()
		);
	}

	/**
	 * Delete one artifact.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param string $artifact_key Artifact key.
	 */
	public function delete( int $workflow_id, string $artifact_key ): void {
		$table = $this->conn->table( Config::table_artifacts() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table}
			WHERE workflow_id = :workflow_id AND artifact_key = :artifact_key"
		);
		$stmt->execute(
			array(
				'workflow_id'  => $workflow_id,
				'artifact_key' => trim( $artifact_key ),
			)
		);
	}

	/**
	 * Summarize one workflow's artifacts.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array{count:int,keys:string[]}
	 */
	public function summary( int $workflow_id ): array {
		return $this->summaries( array( $workflow_id ) )[ $workflow_id ] ?? array(
			'count' => 0,
			'keys'  => array(),
		);
	}

	/**
	 * Summarize artifacts for multiple workflows in one query.
	 *
	 * @param int[] $workflow_ids Workflow IDs.
	 * @return array<int, array{count:int,keys:string[]}>
	 */
	public function summaries( array $workflow_ids ): array {
		$workflow_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $workflow_ids ),
					static fn( int $workflow_id ): bool => $workflow_id > 0
				)
			)
		);

		if ( empty( $workflow_ids ) ) {
			return array();
		}

		$table        = $this->conn->table( Config::table_artifacts() );
		$placeholders = array();
		$params       = array();

		foreach ( $workflow_ids as $index => $workflow_id ) {
			$key            = 'workflow_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $workflow_id;
		}

		$stmt = $this->conn->pdo()->prepare(
			"SELECT workflow_id,
				COUNT(*) AS artifact_count,
				GROUP_CONCAT(artifact_key ORDER BY artifact_key ASC SEPARATOR '\n') AS artifact_keys
			FROM {$table}
			WHERE workflow_id IN (" . implode( ', ', $placeholders ) . ')
			GROUP BY workflow_id'
		);
		$stmt->execute( $params );

		$summaries = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$workflow_id               = (int) $row['workflow_id'];
			$summaries[ $workflow_id ] = array(
				'count' => (int) $row['artifact_count'],
				'keys'  => $this->decode_summary_keys( $row['artifact_keys'] ?? null ),
			);
		}

		return $summaries;
	}

	/**
	 * Encode artifact content for storage.
	 *
	 * @param mixed  $content Artifact content.
	 * @param string $kind    Artifact kind.
	 * @return string
	 */
	private function encode_content( mixed $content, string $kind ): string {
		if ( 'json' === strtolower( $kind ) ) {
			return json_encode( $content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		}

		return is_string( $content ) ? $content : json_encode( $content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Decode one artifact row for public consumption.
	 *
	 * @param array $row             Raw database row.
	 * @param bool  $include_content Whether to include decoded content.
	 * @return array<string,mixed>
	 */
	private function map_row( array $row, bool $include_content ): array {
		$result = array(
			'id'          => (int) $row['id'],
			'workflow_id' => (int) $row['workflow_id'],
			'key'         => $row['artifact_key'],
			'kind'        => $row['kind'],
			'step_index'  => null !== $row['step_index'] ? (int) $row['step_index'] : null,
			'metadata'    => null !== $row['metadata'] ? json_decode( $row['metadata'], true ) : array(),
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);

		if ( $include_content ) {
			$result['content'] = 'json' === strtolower( (string) $row['kind'] )
				? json_decode( $row['content'], true )
				: $row['content'];
		}

		return $result;
	}

	/**
	 * Decode the compact key list used by summary queries.
	 *
	 * @param mixed $value Raw GROUP_CONCAT value.
	 * @return string[]
	 */
	private function decode_summary_keys( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		return array_values(
			array_filter(
				explode( "\n", $value ),
				static fn( string $key ): bool => '' !== $key
			)
		);
	}
}
