<?php
/**
 * Workflow execution replay from exported data.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Replays an exported workflow in a new environment.
 *
 * Creates a new workflow with the exported state, records event log entries
 * for all completed steps (without actually executing them), and enqueues
 * the step where the original workflow was at.
 */
class WorkflowReplayer {

	/**
	 * Replay an exported workflow.
	 *
	 * Creates a new workflow from the export data, records historical event
	 * log entries for completed steps, and enqueues the current step.
	 *
	 * @param array      $export_data The export data from WorkflowExporter::export().
	 * @param Connection $conn        Database connection.
	 * @return int The new workflow ID.
	 * @throws \RuntimeException If the export data is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public static function replay( array $export_data, Connection $conn ): int {
		if ( ! isset( $export_data['workflow'] ) ) {
			throw new \RuntimeException( 'Invalid export data: missing workflow key.' );
		}

		$wf_data = $export_data['workflow'];
		$events  = $export_data['events'] ?? array();

		$pdo    = $conn->pdo();
		$wf_tbl = $conn->table( Config::table_workflows() );
		$ev_tbl = $conn->table( Config::table_workflow_events() );

		$state        = $wf_data['state'] ?? array();
		$current_step = (int) ( $wf_data['current_step'] ?? 0 );
		$total_steps  = (int) ( $wf_data['total_steps'] ?? 0 );
		$steps        = $state['_steps'] ?? array();
		$queue_name   = $state['_queue'] ?? 'default';
		$priority_val = $state['_priority'] ?? 0;
		$max_attempts = $state['_max_attempts'] ?? 3;

		$replay_status = 'running';
		$replay_step   = $current_step;

		if ( 'completed' === $wf_data['status'] ) {
			$replay_step   = $total_steps;
			$replay_status = 'completed';
		}

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"INSERT INTO {$wf_tbl}
				(name, status, state, current_step, total_steps)
				VALUES (:name, :status, :state, :step, :total)"
			);
			$stmt->execute(
				array(
					'name'   => $wf_data['name'] . '_replay_' . time(),
					'status' => $replay_status,
					'state'  => json_encode( $state, JSON_THROW_ON_ERROR ),
					'step'   => $replay_step,
					'total'  => $total_steps,
				)
			);
			$new_id = (int) $pdo->lastInsertId();

			foreach ( $events as $event ) {
				if ( 'step_completed' !== ( $event['event'] ?? '' ) ) {
					continue;
				}

				$ins = $pdo->prepare(
					"INSERT INTO {$ev_tbl}
					(workflow_id, step_index, handler, event, state_snapshot, step_output, duration_ms)
					VALUES
					(:workflow_id, :step_index, :handler, 'step_completed', :state_snapshot, :step_output, :duration_ms)"
				);
				$ins->execute(
					array(
						'workflow_id'    => $new_id,
						'step_index'     => (int) $event['step_index'],
						'handler'        => $event['handler'] ?? '',
						'state_snapshot' => null !== $event['state_snapshot']
							? json_encode( $event['state_snapshot'], JSON_THROW_ON_ERROR )
							: null,
						'step_output'    => null !== $event['step_output']
							? json_encode( $event['step_output'], JSON_THROW_ON_ERROR )
							: null,
						'duration_ms'    => $event['duration_ms'] ?? null,
					)
				);
			}

			if ( 'running' === $replay_status && isset( $steps[ $replay_step ] ) ) {
				$queue    = new Queue( $conn );
				$priority = \Queuety\Enums\Priority::tryFrom( $priority_val ) ?? \Queuety\Enums\Priority::Low;
				$step_def = $steps[ $replay_step ];

				self::enqueue_replay_step( $queue, $step_def, $new_id, $replay_step, $queue_name, $priority, $max_attempts );
			}

			$pdo->commit();
			return $new_id;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Replay from a JSON string.
	 *
	 * @param string     $json JSON string from WorkflowExporter::export_json().
	 * @param Connection $conn Database connection.
	 * @return int The new workflow ID.
	 * @throws \JsonException If the JSON is invalid.
	 */
	public static function replay_json( string $json, Connection $conn ): int {
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		return self::replay( $data, $conn );
	}

	/**
	 * Enqueue a step for the replayed workflow.
	 *
	 * @param Queue                   $queue       Queue operations.
	 * @param array                   $step_def    Step definition.
	 * @param int                     $workflow_id Workflow ID.
	 * @param int                     $step_index  Step index.
	 * @param string                  $queue_name  Queue name.
	 * @param \Queuety\Enums\Priority $priority    Priority level.
	 * @param int                     $max_attempts Maximum attempts.
	 */
	private static function enqueue_replay_step(
		Queue $queue,
		array $step_def,
		int $workflow_id,
		int $step_index,
		string $queue_name,
		\Queuety\Enums\Priority $priority,
		int $max_attempts,
	): void {
		$type = $step_def['type'] ?? 'single';

		if ( 'parallel' === $type ) {
			$handlers = $step_def['handlers'] ?? array();
			foreach ( $handlers as $handler_class ) {
				$queue->dispatch(
					handler: $handler_class,
					payload: array(),
					queue: $queue_name,
					priority: $priority,
					max_attempts: $max_attempts,
					workflow_id: $workflow_id,
					step_index: $step_index,
				);
			}
		} elseif ( 'single' === $type ) {
			$handler = $step_def['class'] ?? '';
			$queue->dispatch(
				handler: $handler,
				payload: array(),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'timer' === $type ) {
			$queue->dispatch(
				handler: '__queuety_timer',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				delay: $step_def['delay_seconds'] ?? 0,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'sub_workflow' === $type ) {
			$queue->dispatch(
				handler: '__queuety_sub_workflow',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'fan_out' === $type ) {
			$queue->dispatch(
				handler: '__queuety_fan_out',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'signal' === $type ) {
			$queue->dispatch(
				handler: '__queuety_signal',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		}
	}
}
