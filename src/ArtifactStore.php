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
	 * @param int                  $workflow_id  Workflow ID.
	 * @param string               $artifact_key Artifact key.
	 * @param mixed                $content      Artifact content.
	 * @param string               $kind         Artifact kind, such as json, text, or markdown.
	 * @param int|null             $step_index   Related workflow step index, if any.
	 * @param array<string, mixed> $metadata     Optional metadata.
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
		if ( ! is_array( $row ) ) {
			return null;
		}

		$typed = array();
		foreach ( $row as $key => $value ) {
			if ( is_string( $key ) ) {
				$typed[ $key ] = $value;
			}
		}

		return $this->map_row( $typed, true );
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

		$rows = array();
		foreach ( $stmt->fetchAll() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$typed = array();
			foreach ( $row as $key => $value ) {
				if ( is_string( $key ) ) {
					$typed[ $key ] = $value;
				}
			}
			$rows[] = $this->map_row( $typed, $include_content );
		}

		return $rows;
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
			if ( ! is_array( $row ) ) {
				continue;
			}
			$workflow_id_value         = $row['workflow_id'] ?? 0;
			$workflow_id               = is_scalar( $workflow_id_value ) ? (int) $workflow_id_value : 0;
			$count_value               = $row['artifact_count'] ?? 0;
			$summaries[ $workflow_id ] = array(
				'count' => is_scalar( $count_value ) ? (int) $count_value : 0,
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
	 * @param array<string, mixed> $row             Raw database row.
	 * @param bool                 $include_content Whether to include decoded content.
	 * @return array<string,mixed>
	 */
	private function map_row( array $row, bool $include_content ): array {
		$id_raw         = $row['id'] ?? 0;
		$workflow_raw   = $row['workflow_id'] ?? 0;
		$step_index_raw = $row['step_index'] ?? null;
		$metadata_raw   = $row['metadata'] ?? null;
		$kind_raw       = $row['kind'] ?? 'json';
		$kind_str       = is_string( $kind_raw ) ? $kind_raw : 'json';

		$result = array(
			'id'          => is_numeric( $id_raw ) ? (int) $id_raw : 0,
			'workflow_id' => is_numeric( $workflow_raw ) ? (int) $workflow_raw : 0,
			'key'         => $row['artifact_key'] ?? null,
			'kind'        => $kind_str,
			'step_index'  => is_numeric( $step_index_raw ) ? (int) $step_index_raw : null,
			'metadata'    => is_string( $metadata_raw ) ? json_decode( $metadata_raw, true ) : array(),
			'created_at'  => $row['created_at'] ?? null,
			'updated_at'  => $row['updated_at'] ?? null,
		);

		if ( $include_content ) {
			$content_raw = $row['content'] ?? null;
			if ( 'json' === strtolower( $kind_str ) ) {
				$result['content'] = is_string( $content_raw ) ? json_decode( $content_raw, true ) : null;
			} else {
				$result['content'] = $content_raw;
			}
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
