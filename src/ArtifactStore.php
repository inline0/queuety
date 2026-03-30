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
		$artifacts = $this->list( $workflow_id, false );

		return array(
			'count' => count( $artifacts ),
			'keys'  => array_values(
				array_map(
					static fn( array $artifact ): string => (string) $artifact['key'],
					$artifacts
				)
			),
		);
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
}
