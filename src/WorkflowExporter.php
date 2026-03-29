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
	 * Export a workflow's full execution history.
	 *
	 * @param int        $workflow_id The workflow ID to export.
	 * @param Connection $conn        Database connection.
	 * @return array JSON-serializable export data.
	 * @throws \RuntimeException If the workflow is not found.
	 */
	public static function export( int $workflow_id, Connection $conn ): array {
		$pdo    = $conn->pdo();
		$wf_tbl = $conn->table( Config::table_workflows() );
		$jb_tbl = $conn->table( Config::table_jobs() );
		$lg_tbl = $conn->table( Config::table_logs() );
		$ev_tbl = $conn->table( Config::table_workflow_events() );
		$sig_tbl = $conn->table( Config::table_signals() );
		$dep_tbl = $conn->table( Config::table_workflow_dependencies() );

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$wf_row = $stmt->fetch();

		if ( ! $wf_row ) {
			throw new \RuntimeException( "Workflow {$workflow_id} not found." );
		}

		$workflow_state = json_decode( $wf_row['state'], true ) ?: array();

		$wf_data = array(
			'id'                 => (int) $wf_row['id'],
			'name'               => $wf_row['name'],
			'status'             => $wf_row['status'],
			'state'              => $workflow_state,
			'current_step'       => (int) $wf_row['current_step'],
			'total_steps'        => (int) $wf_row['total_steps'],
			'parent_workflow_id' => $wf_row['parent_workflow_id'] ? (int) $wf_row['parent_workflow_id'] : null,
			'parent_step_index'  => $wf_row['parent_step_index'] !== null ? (int) $wf_row['parent_step_index'] : null,
			'started_at'         => $wf_row['started_at'],
			'completed_at'       => $wf_row['completed_at'],
			'failed_at'          => $wf_row['failed_at'],
			'error_message'      => $wf_row['error_message'],
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
			$jobs[] = array(
				'id'            => (int) $row['id'],
				'queue'         => $row['queue'],
				'handler'       => $row['handler'],
				'payload'       => json_decode( $row['payload'], true ) ?: array(),
				'priority'      => (int) $row['priority'],
				'status'        => $row['status'],
				'attempts'      => (int) $row['attempts'],
				'max_attempts'  => (int) $row['max_attempts'],
				'step_index'    => $row['step_index'] !== null ? (int) $row['step_index'] : null,
				'available_at'  => $row['available_at'],
				'reserved_at'   => $row['reserved_at'],
				'completed_at'  => $row['completed_at'],
				'failed_at'     => $row['failed_at'],
				'error_message' => $row['error_message'],
				'created_at'    => $row['created_at'],
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$ev_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$event_rows = $stmt->fetchAll();

		$events = array();
		foreach ( $event_rows as $row ) {
			$events[] = array(
				'id'             => (int) $row['id'],
				'step_index'     => (int) $row['step_index'],
				'handler'        => $row['handler'],
				'event'          => $row['event'],
				'state_snapshot' => null !== $row['state_snapshot']
					? json_decode( $row['state_snapshot'], true )
					: null,
				'step_output'    => null !== $row['step_output']
					? json_decode( $row['step_output'], true )
					: null,
				'duration_ms'    => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
				'error_message'  => $row['error_message'],
				'created_at'     => $row['created_at'],
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$lg_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$log_rows = $stmt->fetchAll();

		$logs = array();
		foreach ( $log_rows as $row ) {
			$logs[] = array(
				'id'            => (int) $row['id'],
				'job_id'        => $row['job_id'] !== null ? (int) $row['job_id'] : null,
				'step_index'    => $row['step_index'] !== null ? (int) $row['step_index'] : null,
				'handler'       => $row['handler'],
				'queue'         => $row['queue'],
				'event'         => $row['event'],
				'attempt'       => $row['attempt'] !== null ? (int) $row['attempt'] : null,
				'duration_ms'   => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
				'error_message' => $row['error_message'],
				'created_at'    => $row['created_at'],
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$sig_tbl} WHERE workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$signal_rows = $stmt->fetchAll();

		$signals = array();
		foreach ( $signal_rows as $row ) {
			$signals[] = array(
				'id'          => (int) $row['id'],
				'workflow_id' => (int) $row['workflow_id'],
				'signal_name' => $row['signal_name'],
				'payload'     => json_decode( $row['payload'], true ) ?: array(),
				'received_at' => $row['received_at'],
			);
		}

		$stmt = $pdo->prepare(
			"SELECT * FROM {$dep_tbl} WHERE waiting_workflow_id = :workflow_id ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		$dependency_rows = $stmt->fetchAll();

		$wait_dependencies = array();
		foreach ( $dependency_rows as $row ) {
			$wait_dependencies[] = array(
				'id'                     => (int) $row['id'],
				'waiting_workflow_id'    => (int) $row['waiting_workflow_id'],
				'step_index'             => (int) $row['step_index'],
				'dependency_workflow_id' => (int) $row['dependency_workflow_id'],
				'satisfied_at'           => $row['satisfied_at'],
				'created_at'             => $row['created_at'],
			);
		}

		return array(
			'workflow'        => $wf_data,
			'jobs'            => $jobs,
			'events'          => $events,
			'logs'            => $logs,
			'signals'         => $signals,
			'wait_dependencies' => $wait_dependencies,
			'exported_at'     => gmdate( 'c' ),
			'queuety_version' => Schema::CURRENT_VERSION,
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
