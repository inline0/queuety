<?php
/**
 * Workflow execution history exporter.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Exports a workflow's full execution history into a JSON-serializable array.
 *
 * The export includes the workflow row, all associated jobs, workflow events,
 * and log entries. This data can be imported into another environment using
 * WorkflowReplayer.
 */
class WorkflowExporter {

	/**
	 * Narrow a mixed value to int via scalar guard.
	 *
	 * @param mixed $value Value to narrow.
	 * @return int Narrowed integer, or 0 when not scalar.
	 */
	private static function row_int( mixed $value ): int {
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Narrow a mixed value to nullable int via scalar guard.
	 *
	 * @param mixed $value Value to narrow.
	 * @return int|null Narrowed integer, or null when not scalar or empty string.
	 */
	private static function row_nullable_int( mixed $value ): ?int {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$string = (string) $value;
		return '' === $string ? null : (int) $string;
	}

	/**
	 * Narrow a mixed value to nullable string via scalar guard.
	 *
	 * @param mixed $value Value to narrow.
	 * @return string|null Narrowed string, or null when not scalar.
	 */
	private static function row_nullable_string( mixed $value ): ?string {
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Decode a mixed value as a JSON array.
	 *
	 * @param mixed $value Value to decode.
	 * @return array<int|string, mixed> Decoded array, or empty array when invalid.
	 */
	private static function row_json_array( mixed $value ): array {
		if ( ! is_scalar( $value ) ) {
			return array();
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode a mixed value as a JSON array, returning null when the source is null.
	 *
	 * @param mixed $value Value to decode.
	 * @return array<int|string, mixed>|null Decoded array, or null when source is null.
	 */
	private static function row_nullable_json_array( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		return self::row_json_array( $value );
	}

	/**
	 * Export a workflow's full execution history.
	 *
	 * @param int        $workflow_id The workflow ID to export.
	 * @param Connection $conn        Database connection.
	 * @return array<string, mixed> JSON-serializable export data.
	 * @throws \RuntimeException If the workflow is not found.
	 */
	public static function export( int $workflow_id, Connection $conn ): array {
		$pdo     = $conn->pdo();
		$wf_tbl  = $conn->table( Config::table_workflows() );
		$jb_tbl  = $conn->table( Config::table_jobs() );
		$lg_tbl  = $conn->table( Config::table_logs() );
		$ev_tbl  = $conn->table( Config::table_workflow_events() );
		$sig_tbl = $conn->table( Config::table_signals() );
		$dep_tbl = $conn->table( Config::table_workflow_dependencies() );
		$art_tbl = $conn->table( Config::table_artifacts() );
		$chk_tbl = $conn->table( Config::table_chunks() );

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$wf_row = $stmt->fetch();

		if ( ! is_array( $wf_row ) ) {
			throw new \RuntimeException( "Workflow {$workflow_id} not found." );
		}

		$state_raw         = $wf_row['state'] ?? '';
		$workflow_state    = is_string( $state_raw ) ? json_decode( $state_raw, true ) : array();
		$workflow_state    = is_array( $workflow_state ) ? $workflow_state : array();
		$parent_workflow   = $wf_row['parent_workflow_id'] ?? null;
		$parent_step_index = $wf_row['parent_step_index'] ?? null;

		$wf_data = array(
			'id'                 => self::row_int( $wf_row['id'] ?? 0 ),
			'name'               => $wf_row['name'] ?? null,
			'status'             => $wf_row['status'] ?? null,
			'state'              => $workflow_state,
			'current_step'       => self::row_int( $wf_row['current_step'] ?? 0 ),
			'total_steps'        => self::row_int( $wf_row['total_steps'] ?? 0 ),
			'parent_workflow_id' => is_scalar( $parent_workflow ) && $parent_workflow ? (int) $parent_workflow : null,
			'parent_step_index'  => is_scalar( $parent_step_index ) ? (int) $parent_step_index : null,
			'started_at'         => $wf_row['started_at'] ?? null,
			'completed_at'       => $wf_row['completed_at'] ?? null,
			'failed_at'          => $wf_row['failed_at'] ?? null,
			'error_message'      => $wf_row['error_message'] ?? null,
			'deadline_at'        => $wf_row['deadline_at'] ?? null,
			'definition_version' => $workflow_state['_definition_version'] ?? null,
			'definition_hash'    => $workflow_state['_definition_hash'] ?? null,
			'idempotency_key'    => $workflow_state['_idempotency_key'] ?? null,
		);

		$stmt = $pdo->prepare(
			"SELECT * FROM {$jb_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$job_rows = $stmt->fetchAll();

		$jobs = array();
		foreach ( $job_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$jobs[] = array(
				'id'            => self::row_int( $row['id'] ?? 0 ),
				'queue'         => self::row_nullable_string( $row['queue'] ?? null ),
				'handler'       => self::row_nullable_string( $row['handler'] ?? null ),
				'payload'       => self::row_json_array( $row['payload'] ?? '' ),
				'priority'      => self::row_int( $row['priority'] ?? 0 ),
				'status'        => self::row_nullable_string( $row['status'] ?? null ),
				'attempts'      => self::row_int( $row['attempts'] ?? 0 ),
				'max_attempts'  => self::row_int( $row['max_attempts'] ?? 0 ),
				'step_index'    => self::row_nullable_int( $row['step_index'] ?? null ),
				'available_at'  => self::row_nullable_string( $row['available_at'] ?? null ),
				'reserved_at'   => self::row_nullable_string( $row['reserved_at'] ?? null ),
				'completed_at'  => self::row_nullable_string( $row['completed_at'] ?? null ),
				'failed_at'     => self::row_nullable_string( $row['failed_at'] ?? null ),
				'error_message' => self::row_nullable_string( $row['error_message'] ?? null ),
				'created_at'    => self::row_nullable_string( $row['created_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$ev_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$event_rows = $stmt->fetchAll();

		$events = array();
		foreach ( $event_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$events[] = array(
				'id'              => self::row_int( $row['id'] ?? 0 ),
				'job_id'          => self::row_nullable_int( $row['job_id'] ?? null ),
				'parent_event_id' => self::row_nullable_int( $row['parent_event_id'] ?? null ),
				'step_index'      => self::row_int( $row['step_index'] ?? 0 ),
				'step_name'       => self::row_nullable_string( $row['step_name'] ?? null ),
				'step_type'       => self::row_nullable_string( $row['step_type'] ?? null ),
				'handler'         => self::row_nullable_string( $row['handler'] ?? null ),
				'event'           => self::row_nullable_string( $row['event'] ?? null ),
				'queue'           => self::row_nullable_string( $row['queue'] ?? null ),
				'attempt'         => self::row_nullable_int( $row['attempt'] ?? null ),
				'input'           => self::row_nullable_json_array( $row['input'] ?? null ),
				'output'          => self::row_nullable_json_array( $row['output'] ?? null ),
				'state_before'    => self::row_nullable_json_array( $row['state_before'] ?? null ),
				'state_after'     => self::row_nullable_json_array( $row['state_after'] ?? null ),
				'context'         => self::row_nullable_json_array( $row['context'] ?? null ),
				'artifacts'       => self::row_nullable_json_array( $row['artifacts'] ?? null ),
				'chunks'          => self::row_nullable_json_array( $row['chunks'] ?? null ),
				'error'           => self::row_nullable_json_array( $row['error'] ?? null ),
				'duration_ms'     => self::row_nullable_int( $row['duration_ms'] ?? null ),
				'created_at'      => self::row_nullable_string( $row['created_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$lg_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$log_rows = $stmt->fetchAll();

		$logs = array();
		foreach ( $log_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$logs[] = array(
				'id'            => self::row_int( $row['id'] ?? 0 ),
				'job_id'        => self::row_nullable_int( $row['job_id'] ?? null ),
				'step_index'    => self::row_nullable_int( $row['step_index'] ?? null ),
				'handler'       => self::row_nullable_string( $row['handler'] ?? null ),
				'queue'         => self::row_nullable_string( $row['queue'] ?? null ),
				'event'         => self::row_nullable_string( $row['event'] ?? null ),
				'attempt'       => self::row_nullable_int( $row['attempt'] ?? null ),
				'duration_ms'   => self::row_nullable_int( $row['duration_ms'] ?? null ),
				'error_message' => self::row_nullable_string( $row['error_message'] ?? null ),
				'context'       => self::row_nullable_json_array( $row['context'] ?? null ),
				'created_at'    => self::row_nullable_string( $row['created_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$sig_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$signal_rows = $stmt->fetchAll();

		$signals = array();
		foreach ( $signal_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$signals[] = array(
				'id'          => self::row_int( $row['id'] ?? 0 ),
				'workflow_id' => self::row_int( $row['workflow_id'] ?? 0 ),
				'signal_name' => self::row_nullable_string( $row['signal_name'] ?? null ),
				'payload'     => self::row_json_array( $row['payload'] ?? '' ),
				'received_at' => self::row_nullable_string( $row['received_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$dep_tbl} WHERE waiting_workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$dependency_rows = $stmt->fetchAll();

		$wait_dependencies = array();
		foreach ( $dependency_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$wait_dependencies[] = array(
				'id'                     => self::row_int( $row['id'] ?? 0 ),
				'waiting_workflow_id'    => self::row_int( $row['waiting_workflow_id'] ?? 0 ),
				'step_index'             => self::row_int( $row['step_index'] ?? 0 ),
				'dependency_workflow_id' => self::row_int( $row['dependency_workflow_id'] ?? 0 ),
				'satisfied_at'           => self::row_nullable_string( $row['satisfied_at'] ?? null ),
				'created_at'             => self::row_nullable_string( $row['created_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$art_tbl} WHERE workflow_id = :workflow_id ORDER BY artifact_key ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$artifact_rows = $stmt->fetchAll();

		$artifacts = array();
		foreach ( $artifact_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind        = self::row_nullable_string( $row['kind'] ?? null );
			$raw_content = $row['content'] ?? null;
			$content     = 'json' === strtolower( (string) $kind )
				? self::row_nullable_json_array( $raw_content )
				: $raw_content;

			$artifacts[] = array(
				'id'          => self::row_int( $row['id'] ?? 0 ),
				'workflow_id' => self::row_int( $row['workflow_id'] ?? 0 ),
				'key'         => self::row_nullable_string( $row['artifact_key'] ?? null ),
				'kind'        => $kind,
				'content'     => $content,
				'metadata'    => self::row_json_array( $row['metadata'] ?? '' ),
				'step_index'  => self::row_nullable_int( $row['step_index'] ?? null ),
				'created_at'  => self::row_nullable_string( $row['created_at'] ?? null ),
				'updated_at'  => self::row_nullable_string( $row['updated_at'] ?? null ),
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$chk_tbl} WHERE workflow_id = :workflow_id ORDER BY step_index ASC, chunk_index ASC, id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$chunk_rows = $stmt->fetchAll();

		$chunks = array();
		foreach ( $chunk_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$chunks[] = array(
				'id'          => self::row_int( $row['id'] ?? 0 ),
				'job_id'      => self::row_int( $row['job_id'] ?? 0 ),
				'workflow_id' => self::row_int( $row['workflow_id'] ?? 0 ),
				'step_index'  => self::row_nullable_int( $row['step_index'] ?? null ),
				'chunk_index' => self::row_int( $row['chunk_index'] ?? 0 ),
				'content'     => $row['content'] ?? null,
				'created_at'  => self::row_nullable_string( $row['created_at'] ?? null ),
			);
		}

		return array(
			'workflow'          => $wf_data,
			'jobs'              => $jobs,
			'events'            => $events,
			'logs'              => $logs,
			'signals'           => $signals,
			'artifacts'         => $artifacts,
			'chunks'            => $chunks,
			'wait_dependencies' => $wait_dependencies,
			'exported_at'       => gmdate( 'c' ),
			'queuety_version'   => defined( 'QUEUETY_VERSION' ) ? QUEUETY_VERSION : 'dev',
		);
	}

	/**
	 * Export a workflow as pretty-printed JSON.
	 *
	 * @param int        $workflow_id The workflow ID to export.
	 * @param Connection $conn        Database connection.
	 * @return string Pretty-printed JSON string.
	 */
	public static function export_json( int $workflow_id, Connection $conn ): string {
		$data = self::export( $workflow_id, $conn );
		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}
}
