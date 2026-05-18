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
	 * Strip reserved keys from workflow state for public event snapshots.
	 *
	 * @param array<string, mixed> $state Internal workflow state.
	 * @return array<string, mixed>
	 */
	private static function public_state( array $state ): array {
		return array_filter(
			$state,
			static fn( string $key ) => ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * JSON encode nullable replay values.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|null
	 */
	private static function json_or_null( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		return json_encode( $value, JSON_THROW_ON_ERROR );
	}

	/**
	 * Replay an exported workflow.
	 *
	 * Creates a new workflow from the export data, records historical event
	 * log entries for completed steps, and enqueues the current step.
	 *
	 * @param array<string, mixed> $export_data The export data from WorkflowExporter::export().
	 * @param Connection           $conn        Database connection.
	 * @return int The new workflow ID.
	 * @throws \RuntimeException If the export data is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public static function replay( array $export_data, Connection $conn ): int {
		if ( ! isset( $export_data['workflow'] ) || ! is_array( $export_data['workflow'] ) ) {
			throw new \RuntimeException( 'Invalid export data: missing workflow key.' );
		}

		$wf_data   = $export_data['workflow'];
		$events    = is_array( $export_data['events'] ?? null ) ? $export_data['events'] : array();
		$logs      = is_array( $export_data['logs'] ?? null ) ? $export_data['logs'] : array();
		$signals   = is_array( $export_data['signals'] ?? null ) ? $export_data['signals'] : array();
		$waits     = is_array( $export_data['wait_dependencies'] ?? null ) ? $export_data['wait_dependencies'] : array();
		$artifacts = is_array( $export_data['artifacts'] ?? null ) ? $export_data['artifacts'] : array();
		$chunks    = is_array( $export_data['chunks'] ?? null ) ? $export_data['chunks'] : array();

		$pdo     = $conn->pdo();
		$wf_tbl  = $conn->table( Config::table_workflows() );
		$ev_tbl  = $conn->table( Config::table_workflow_events() );
		$lg_tbl  = $conn->table( Config::table_logs() );
		$sig_tbl = $conn->table( Config::table_signals() );
		$dep_tbl = $conn->table( Config::table_workflow_dependencies() );
		$art_tbl = $conn->table( Config::table_artifacts() );
		$chk_tbl = $conn->table( Config::table_chunks() );

		$state            = is_array( $wf_data['state'] ?? null ) ? $wf_data['state'] : array();
		$current_step_raw = $wf_data['current_step'] ?? 0;
		$total_steps_raw  = $wf_data['total_steps'] ?? 0;
		$current_step     = is_numeric( $current_step_raw ) ? (int) $current_step_raw : 0;
		$total_steps      = is_numeric( $total_steps_raw ) ? (int) $total_steps_raw : 0;
		$steps            = is_array( $state['_steps'] ?? null ) ? $state['_steps'] : array();
		$queue_name_raw   = $state['_queue'] ?? 'default';
		$queue_name       = is_string( $queue_name_raw ) ? $queue_name_raw : 'default';
		$priority_val     = $state['_priority'] ?? 0;
		$max_attempts_raw = $state['_max_attempts'] ?? 3;
		$max_attempts     = is_numeric( $max_attempts_raw ) ? (int) $max_attempts_raw : 3;
		$status_raw       = $wf_data['status'] ?? 'running';
		$source_status    = is_scalar( $status_raw ) ? (string) $status_raw : 'running';
		$name_raw         = $wf_data['name'] ?? '';
		$wf_name          = is_scalar( $name_raw ) ? (string) $name_raw : '';
		$replay_status = in_array(
			$source_status,
			array( 'running', 'completed', 'failed', 'paused', 'waiting_for_signal', 'waiting_for_workflows', 'cancelled' ),
			true
		) ? $source_status : 'running';
		$replay_step   = 'completed' === $replay_status ? $total_steps : $current_step;
		$failed_at     = 'failed' === $replay_status ? gmdate( 'Y-m-d H:i:s' ) : null;
		$completed_at  = 'completed' === $replay_status ? gmdate( 'Y-m-d H:i:s' ) : null;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"INSERT INTO {$wf_tbl}
				(name, status, state, current_step, total_steps, completed_at, failed_at, error_message)
				VALUES (:name, :status, :state, :step, :total, :completed_at, :failed_at, :error_message)"
			);
			$error_message_raw = $wf_data['error_message'] ?? null;
			$stmt->execute(
				array(
					'name'          => $wf_name . '_replay_' . time(),
					'status'        => $replay_status,
					'state'         => json_encode( $state, JSON_THROW_ON_ERROR ),
					'step'          => $replay_step,
					'total'         => $total_steps,
					'completed_at'  => $completed_at,
					'failed_at'     => $failed_at,
					'error_message' => 'failed' === $replay_status && is_string( $error_message_raw )
						? $error_message_raw
						: null,
				)
			);
			$new_id = (int) $pdo->lastInsertId();

			foreach ( $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$ins = $pdo->prepare(
					"INSERT INTO {$ev_tbl}
					(workflow_id, job_id, parent_event_id, step_index, step_name, step_type, handler, event, queue, attempt, input, output, state_before, state_after, context, artifacts, chunks, error, duration_ms, created_at)
					VALUES
					(:workflow_id, :job_id, :parent_event_id, :step_index, :step_name, :step_type, :handler, :event, :queue, :attempt, :input, :output, :state_before, :state_after, :context, :artifacts, :chunks, :error, :duration_ms, :created_at)"
				);
				$step_index_raw = $event['step_index'] ?? 0;
				$ins->execute(
					array(
						'workflow_id'     => $new_id,
						'job_id'          => null,
						'parent_event_id' => null,
						'step_index'      => is_numeric( $step_index_raw ) ? (int) $step_index_raw : 0,
						'step_name'       => $event['step_name'] ?? null,
						'step_type'       => $event['step_type'] ?? null,
						'handler'         => $event['handler'] ?? '',
						'event'           => $event['event'] ?? 'step_completed',
						'queue'           => $event['queue'] ?? null,
						'attempt'         => $event['attempt'] ?? null,
						'input'           => self::json_or_null( $event['input'] ?? null ),
						'output'          => self::json_or_null( $event['output'] ?? null ),
						'state_before'    => self::json_or_null( $event['state_before'] ?? null ),
						'state_after'     => self::json_or_null( $event['state_after'] ?? null ),
						'context'         => self::json_or_null( $event['context'] ?? null ),
						'artifacts'       => self::json_or_null( $event['artifacts'] ?? null ),
						'chunks'          => self::json_or_null( $event['chunks'] ?? null ),
						'error'           => self::json_or_null( $event['error'] ?? null ),
						'duration_ms'     => $event['duration_ms'] ?? null,
						'created_at'      => $event['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			foreach ( $logs as $log ) {
				if ( ! is_array( $log ) ) {
					continue;
				}
				$ins = $pdo->prepare(
					"INSERT INTO {$lg_tbl}
					(job_id, workflow_id, step_index, handler, queue, event, attempt, duration_ms, error_message, context, created_at)
					VALUES
					(:job_id, :workflow_id, :step_index, :handler, :queue, :event, :attempt, :duration_ms, :error_message, :context, :created_at)"
				);
				$ins->execute(
					array(
						'job_id'        => null,
						'workflow_id'   => $new_id,
						'step_index'    => $log['step_index'] ?? null,
						'handler'       => $log['handler'] ?? '',
						'queue'         => $log['queue'] ?? 'default',
						'event'         => $log['event'] ?? 'debug',
						'attempt'       => $log['attempt'] ?? null,
						'duration_ms'   => $log['duration_ms'] ?? null,
						'error_message' => $log['error_message'] ?? null,
						'context'       => self::json_or_null( $log['context'] ?? null ),
						'created_at'    => $log['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			foreach ( $signals as $signal ) {
				if ( ! is_array( $signal ) ) {
					continue;
				}
				$ins = $pdo->prepare(
					"INSERT INTO {$sig_tbl} (workflow_id, signal_name, payload, received_at)
					VALUES (:workflow_id, :signal_name, :payload, :received_at)"
				);
				$ins->execute(
					array(
						'workflow_id' => $new_id,
						'signal_name' => $signal['signal_name'] ?? '',
						'payload'     => json_encode( $signal['payload'] ?? array(), JSON_THROW_ON_ERROR ),
						'received_at' => $signal['received_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			foreach ( $waits as $wait ) {
				if ( ! is_array( $wait ) ) {
					continue;
				}
				$dependency_raw         = $wait['dependency_workflow_id'] ?? 0;
				$dependency_workflow_id = is_numeric( $dependency_raw ) ? (int) $dependency_raw : 0;
				if ( $dependency_workflow_id < 1 ) {
					continue;
				}

				$ins = $pdo->prepare(
					"INSERT INTO {$dep_tbl} (waiting_workflow_id, step_index, dependency_workflow_id, satisfied_at, created_at)
					VALUES (:waiting_workflow_id, :step_index, :dependency_workflow_id, :satisfied_at, :created_at)"
				);
				$wait_step_raw = $wait['step_index'] ?? 0;
				$ins->execute(
					array(
						'waiting_workflow_id'    => $new_id,
						'step_index'             => is_numeric( $wait_step_raw ) ? (int) $wait_step_raw : 0,
						'dependency_workflow_id' => $dependency_workflow_id,
						'satisfied_at'           => $wait['satisfied_at'] ?? null,
						'created_at'             => $wait['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			foreach ( $artifacts as $artifact ) {
				if ( ! is_array( $artifact ) ) {
					continue;
				}
				$ins = $pdo->prepare(
					"INSERT INTO {$art_tbl}
						(workflow_id, artifact_key, kind, content, metadata, step_index, created_at, updated_at)
					VALUES
						(:workflow_id, :artifact_key, :kind, :content, :metadata, :step_index, :created_at, :updated_at)"
				);
				$kind_raw    = $artifact['kind'] ?? 'json';
				$kind_str    = is_scalar( $kind_raw ) ? (string) $kind_raw : 'json';
				$content_raw = $artifact['content'] ?? null;
				$content_val = 'json' === strtolower( $kind_str )
					? json_encode( $content_raw, JSON_THROW_ON_ERROR )
					: ( is_scalar( $content_raw ) ? (string) $content_raw : '' );
				$ins->execute(
					array(
						'workflow_id'  => $new_id,
						'artifact_key' => $artifact['key'] ?? '',
						'kind'         => $kind_str,
						'content'      => $content_val,
						'metadata'     => json_encode( $artifact['metadata'] ?? array(), JSON_THROW_ON_ERROR ),
						'step_index'   => $artifact['step_index'] ?? null,
						'created_at'   => $artifact['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
						'updated_at'   => $artifact['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			foreach ( $chunks as $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$ins = $pdo->prepare(
					"INSERT INTO {$chk_tbl}
						(job_id, workflow_id, step_index, chunk_index, content, created_at)
					VALUES
						(:job_id, :workflow_id, :step_index, :chunk_index, :content, :created_at)"
				);
				$chunk_job_raw   = $chunk['job_id'] ?? 0;
				$chunk_index_raw = $chunk['chunk_index'] ?? 0;
				$chunk_content   = $chunk['content'] ?? '';
				$ins->execute(
					array(
						'job_id'      => is_numeric( $chunk_job_raw ) ? (int) $chunk_job_raw : 0,
						'workflow_id' => $new_id,
						'step_index'  => $chunk['step_index'] ?? null,
						'chunk_index' => is_numeric( $chunk_index_raw ) ? (int) $chunk_index_raw : 0,
						'content'     => is_scalar( $chunk_content ) ? (string) $chunk_content : '',
						'created_at'  => $chunk['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					)
				);
			}

			$replay_event   = $pdo->prepare(
				"INSERT INTO {$ev_tbl}
				(workflow_id, step_index, handler, event, output, state_after, context)
				VALUES
				(:workflow_id, :step_index, :handler, 'workflow_replayed', :output, :state_after, :context)"
			);
			$source_id_raw  = $wf_data['id'] ?? 0;
			$replay_context = array(
				'source_workflow_id' => is_numeric( $source_id_raw ) ? (int) $source_id_raw : 0,
				'source_status'      => $source_status,
				'definition_version' => $wf_data['definition_version'] ?? null,
				'definition_hash'    => $wf_data['definition_hash'] ?? null,
			);
			$replay_event->execute(
				array(
					'workflow_id' => $new_id,
					'step_index'  => $replay_step,
					'handler'     => '__queuety_replay',
					'output'      => json_encode( $replay_context, JSON_THROW_ON_ERROR ),
					'state_after' => json_encode( self::public_state( $state ), JSON_THROW_ON_ERROR ),
					'context'     => json_encode( $replay_context, JSON_THROW_ON_ERROR ),
				)
			);

			if ( 'running' === $replay_status && isset( $steps[ $replay_step ] ) && is_array( $steps[ $replay_step ] ) ) {
				$queue        = new Queue( $conn );
				$priority_key = is_int( $priority_val ) || is_string( $priority_val ) ? $priority_val : 0;
				$priority     = \Queuety\Enums\Priority::tryFrom( $priority_key ) ?? \Queuety\Enums\Priority::Low;
				$step_def     = self::string_keyed_array( $steps[ $replay_step ] );

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
	 * @throws \JsonException|\RuntimeException If the JSON is invalid or the decoded payload is not an object.
	 */
	public static function replay_json( string $json, Connection $conn ): int {
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid workflow export JSON: expected an object.' );
		}
		return self::replay( self::string_keyed_array( $data ), $conn );
	}

	/**
	 * Filter an array to retain only string-keyed entries.
	 *
	 * @param array<mixed, mixed> $value Source array.
	 * @return array<string, mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $entry ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $entry;
			}
		}

		return $result;
	}

	/**
	 * Dispatch one replayed workflow step job with serialized runtime metadata.
	 *
	 * @param Queue                       $queue        Queue operations.
	 * @param array<string, mixed>|string $step_def     Step definition.
	 * @param string                      $handler      Handler class or alias.
	 * @param array<string, mixed>        $payload      Job payload.
	 * @param int                         $workflow_id  Workflow ID.
	 * @param int                         $step_index   Step index.
	 * @param string                      $queue_name   Workflow queue name.
	 * @param \Queuety\Enums\Priority     $priority     Workflow priority.
	 * @param int                         $max_attempts Workflow max attempts.
	 * @param int                         $default_cost Default cost units when no metadata exists.
	 * @param int|null                    $delay        Explicit dispatch delay override.
	 */
	private static function dispatch_replay_job(
		Queue $queue,
		array|string $step_def,
		string $handler,
		array $payload,
		int $workflow_id,
		int $step_index,
		string $queue_name,
		\Queuety\Enums\Priority $priority,
		int $max_attempts,
		int $default_cost = 1,
		?int $delay = null,
	): void {
		$options = StepDispatchOptions::resolve(
			definition: $step_def,
			handler: $handler,
			workflow_queue: $queue_name,
			workflow_priority: $priority,
			workflow_attempts: $max_attempts,
			payload: $payload,
			default_cost_units: $default_cost,
		);

		$queue->dispatch(
			handler: $handler,
			payload: $options['payload'],
			queue: $options['queue'],
			priority: $options['priority'],
			delay: null === $delay ? $options['delay'] : $delay,
			max_attempts: $options['max_attempts'],
			workflow_id: $workflow_id,
			step_index: $step_index,
			concurrency_group: $options['concurrency_group'],
			concurrency_limit: $options['concurrency_limit'],
			cost_units: $options['cost_units'],
		);
	}

	/**
	 * Enqueue a step for the replayed workflow.
	 *
	 * @param Queue                   $queue       Queue operations.
	 * @param array<string, mixed>    $step_def    Step definition.
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
			foreach ( StepDispatchOptions::parallel_branches( $step_def ) as $branch ) {
				$branch_def = StepDispatchOptions::merge_parallel_branch( $step_def, $branch );
				$handler    = StepDispatchOptions::branch_handler( $branch_def );
				self::dispatch_replay_job(
					$queue,
					$branch_def,
					$handler,
					StepDispatchOptions::payload( $branch_def ),
					$workflow_id,
					$step_index,
					$queue_name,
					$priority,
					$max_attempts,
				);
			}
		} elseif ( 'single' === $type ) {
			$handler_raw = $step_def['class'] ?? '';
			$handler     = is_string( $handler_raw ) ? $handler_raw : '';
			self::dispatch_replay_job(
				$queue,
				$step_def,
				$handler,
				StepDispatchOptions::payload( $step_def ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
			);
		} elseif ( 'delay' === $type ) {
			self::dispatch_replay_job(
				$queue,
				$step_def,
				'__queuety_delay',
				array( 'step_index' => $step_index ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
				0,
				is_numeric( $step_def['delay_seconds'] ?? null ) ? (int) $step_def['delay_seconds'] : 0,
			);
		} elseif ( 'run_workflow' === $type ) {
			self::dispatch_replay_job(
				$queue,
				$step_def,
				'__queuety_run_workflow',
				array( 'step_index' => $step_index ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
				0,
			);
		} elseif ( 'for_each' === $type ) {
			self::dispatch_replay_job(
				$queue,
				$step_def,
				'__queuety_for_each',
				array( 'step_index' => $step_index ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
				0,
			);
		} elseif ( 'wait_for_signal' === $type ) {
			self::dispatch_replay_job(
				$queue,
				$step_def,
				'__queuety_wait_for_signal',
				array( 'step_index' => $step_index ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
				0,
			);
		} elseif ( 'wait_for_workflows' === $type ) {
			self::dispatch_replay_job(
				$queue,
				$step_def,
				'__queuety_wait_for_workflows',
				array( 'step_index' => $step_index ),
				$workflow_id,
				$step_index,
				$queue_name,
				$priority,
				$max_attempts,
				0,
			);
		}
	}
}
