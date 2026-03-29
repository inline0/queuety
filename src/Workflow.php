<?php
/**
 * Workflow orchestration engine.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Compensation;
use Queuety\Contracts\Cache;
use Queuety\Contracts\JoinReducer;
use Queuety\Enums\JobStatus;
use Queuety\Enums\JoinMode;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;

/**
 * Workflow orchestration: step advancement, state accumulation, pause/resume/retry.
 */
class Workflow {

	/**
	 * Cache TTL for workflow state reads, in seconds.
	 *
	 * @var int
	 */
	private const STATE_CACHE_TTL = 2;

	/**
	 * Constructor.
	 *
	 * @param Connection            $conn      Database connection.
	 * @param Queue                 $queue     Queue operations.
	 * @param Logger                $logger    Logger instance.
	 * @param Cache|null            $cache     Optional cache backend for reducing DB reads.
	 * @param WorkflowEventLog|null $event_log Optional workflow event log for state snapshots.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
		private readonly ?Cache $cache = null,
		private readonly ?WorkflowEventLog $event_log = null,
	) {}

	/**
	 * Resolve the handler class from a step definition.
	 *
	 * Supports both the new array format and the legacy string format
	 * for backwards compatibility.
	 *
	 * @param array|string $step_def Step definition.
	 * @return string Handler class name.
	 */
	private function resolve_step_handler( array|string $step_def ): string {
		if ( is_string( $step_def ) ) {
			return $step_def;
		}
		return $step_def['class'] ?? '';
	}

	/**
	 * Resolve the step type from a step definition.
	 *
	 * @param array|string $step_def Step definition.
	 * @return string Step type: 'single', 'parallel', 'fan_out', 'sub_workflow', 'timer', or 'signal'.
	 */
	private function resolve_step_type( array|string $step_def ): string {
		if ( is_string( $step_def ) ) {
			return 'single';
		}
		return $step_def['type'] ?? 'single';
	}

	/**
	 * Find the step index by name.
	 *
	 * @param array  $steps     Array of step definitions.
	 * @param string $name      Step name to find.
	 * @return int|null Step index or null if not found.
	 */
	private function find_step_index_by_name( array $steps, string $name ): ?int {
		foreach ( $steps as $index => $step_def ) {
			if ( is_array( $step_def ) && isset( $step_def['name'] ) && $step_def['name'] === $name ) {
				return $index;
			}
			// Older persisted workflows used the step index itself as the step name.
			if ( is_string( $step_def ) && (string) $index === $name ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Strip reserved keys from workflow state for public consumption.
	 *
	 * @param array $state Full workflow state.
	 * @return array
	 */
	private function public_state( array $state ): array {
		return array_filter(
			$state,
			fn( string $key ) => ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Merge step output into workflow state and track keys for pruning.
	 *
	 * @param array $state        Workflow state.
	 * @param array $step_output  Step output.
	 * @param int   $current_step Current step index.
	 */
	private function merge_step_output_into_state( array &$state, array $step_output, int $current_step ): void {
		$output_keys = array();
		foreach ( $step_output as $key => $value ) {
			if ( ! str_starts_with( $key, '_' ) ) {
				$state[ $key ] = $value;
				$output_keys[] = $key;
			}
		}

		if ( isset( $state['_prune_state_after'] ) && is_array( $state['_step_outputs'] ?? null ) ) {
			$state['_step_outputs'][ $current_step ] = $output_keys;

			$prune_after = (int) $state['_prune_state_after'];
			if ( $current_step >= $prune_after ) {
				$cutoff = $current_step - $prune_after;
				foreach ( $state['_step_outputs'] as $step_idx => $keys ) {
					if ( (int) $step_idx <= $cutoff ) {
						foreach ( $keys as $key ) {
							if ( ! str_starts_with( $key, '_' ) && isset( $state[ $key ] ) ) {
								unset( $state[ $key ] );
							}
						}
						unset( $state['_step_outputs'][ $step_idx ] );
					}
				}
			}
		}
	}

	/**
	 * Record a completed compensatable step on the compensation stack.
	 *
	 * @param array        $state      Workflow state.
	 * @param array|string $step_def   Step definition.
	 * @param int          $step_index Step index.
	 */
	private function push_compensation_snapshot( array &$state, array|string $step_def, int $step_index ): void {
		if ( ! is_array( $step_def ) ) {
			return;
		}

		$handler_class = $step_def['compensation'] ?? null;
		if ( ! is_string( $handler_class ) || '' === trim( $handler_class ) ) {
			return;
		}

		$state['_compensation_stack'] ??= array();
		$state['_compensation_stack'][] = array(
			'step_index' => $step_index,
			'handler'    => $handler_class,
			'state'      => $this->public_state( $state ),
		);
	}

	/**
	 * Run stored compensations in reverse order.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param array  $state       Workflow state.
	 * @param string $reason      Reason for running compensation.
	 * @return array Updated workflow state.
	 */
	private function run_compensations( int $workflow_id, array $state, string $reason ): array {
		if ( ! empty( $state['_compensated'] ) ) {
			return $state;
		}

		$stack = $state['_compensation_stack'] ?? array();
		if ( ! is_array( $stack ) || empty( $stack ) ) {
			$state['_compensated']        = true;
			$state['_compensation_cause'] = $reason;
			return $state;
		}

		for ( $i = count( $stack ) - 1; $i >= 0; --$i ) {
			$entry         = $stack[ $i ];
			$handler_class = $entry['handler'] ?? null;
			$snapshot      = is_array( $entry['state'] ?? null ) ? $entry['state'] : array();

			if ( ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
				continue;
			}

			try {
				$instance = new $handler_class();

				if ( $instance instanceof Compensation || method_exists( $instance, 'handle' ) ) {
					$instance->handle( $snapshot );
				}
			} catch ( \Throwable $e ) {
				$this->logger->log(
					LogEvent::Debug,
					array(
						'workflow_id'   => $workflow_id,
						'handler'       => $handler_class,
						'error_message' => $e->getMessage(),
						'context'       => array(
							'type'   => 'compensation_failed',
							'reason' => $reason,
						),
					)
				);
			}
		}

		$state['_compensated']        = true;
		$state['_compensation_cause'] = $reason;

		return $state;
	}

	/**
	 * Persist updated internal workflow state without changing lifecycle status.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $state       Workflow state.
	 */
	private function persist_internal_state( int $workflow_id, array $state ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl} SET state = :state WHERE id = :id"
		);
		$stmt->execute(
			array(
				'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
				'id'    => $workflow_id,
			)
		);
	}

	/**
	 * Normalize persisted fan-out branch entries so sparse indexes survive JSON round-trips.
	 *
	 * @param array $entries Persisted result or failure entries.
	 * @return array<string,array>
	 */
	private function normalize_fan_out_entries( array $entries ): array {
		$normalized = array();

		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$index                         = array_key_exists( 'index', $entry ) ? (int) $entry['index'] : (int) $key;
			$normalized[ (string) $index ] = $entry;
		}

		ksort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	/**
	 * Normalize fan-out runtime state loaded from persisted workflow state.
	 *
	 * @param array $runtime Raw runtime state.
	 * @return array
	 */
	private function normalize_fan_out_runtime( array $runtime ): array {
		$runtime['items']        = array_values( is_array( $runtime['items'] ?? null ) ? $runtime['items'] : array() );
		$runtime['results']      = $this->normalize_fan_out_entries( is_array( $runtime['results'] ?? null ) ? $runtime['results'] : array() );
		$runtime['failures']     = $this->normalize_fan_out_entries( is_array( $runtime['failures'] ?? null ) ? $runtime['failures'] : array() );
		$runtime['winner_index'] = isset( $runtime['winner_index'] ) ? (int) $runtime['winner_index'] : null;
		$runtime['settled']      = ! empty( $runtime['settled'] );

		return $runtime;
	}

	/**
	 * Whether a workflow step job can be completed by the workflow runtime.
	 *
	 * @param string $status Current job status.
	 * @return bool
	 */
	private function is_completable_workflow_job_status( string $status ): bool {
		return in_array(
			$status,
			array( JobStatus::Pending->value, JobStatus::Processing->value ),
			true
		);
	}

	/**
	 * Mark a workflow job completed when it is still pending or processing.
	 *
	 * @param \PDO $pdo    Active PDO connection.
	 * @param int  $job_id Job ID.
	 */
	private function mark_workflow_job_completed( \PDO $pdo, int $job_id ): void {
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $pdo->prepare(
			"UPDATE {$jb_tbl}
			SET status = :status, completed_at = NOW()
			WHERE id = :id
				AND status IN (:pending, :processing)"
		);
		$stmt->execute(
			array(
				'status'     => JobStatus::Completed->value,
				'id'         => $job_id,
				'pending'    => JobStatus::Pending->value,
				'processing' => JobStatus::Processing->value,
			)
		);
	}

	/**
	 * Build the public aggregate payload for a settled fan-out step.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime fan-out state.
	 * @return array
	 */
	private function build_fan_out_aggregate( array $step_def, array $runtime ): array {
		$results  = array_values( $runtime['results'] ?? array() );
		$failures = array_values( $runtime['failures'] ?? array() );

		usort(
			$results,
			fn( array $a, array $b ) => (int) $a['index'] <=> (int) $b['index']
		);
		usort(
			$failures,
			fn( array $a, array $b ) => (int) $a['index'] <=> (int) $b['index']
		);

		$winner_index = $runtime['winner_index'] ?? null;
		$winner       = null;
		foreach ( $results as $entry ) {
			if ( null !== $winner_index && (int) $entry['index'] === (int) $winner_index ) {
				$winner = $entry;
				break;
			}
		}

		return array(
			'mode'      => $step_def['join_mode'] ?? JoinMode::All->value,
			'quorum'    => $step_def['quorum'] ?? null,
			'total'     => count( $runtime['items'] ?? array() ),
			'succeeded' => count( $results ),
			'failed'    => count( $failures ),
			'settled'   => true,
			'winner'    => $winner,
			'results'   => $results,
			'failures'  => $failures,
		);
	}

	/**
	 * Determine whether a fan-out step has satisfied its join condition.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime fan-out state.
	 * @return bool
	 */
	private function fan_out_join_satisfied( array $step_def, array $runtime ): bool {
		$mode      = JoinMode::from( $step_def['join_mode'] ?? JoinMode::All->value );
		$successes = count( $runtime['results'] ?? array() );
		$total     = count( $runtime['items'] ?? array() );

		return match ( $mode ) {
			JoinMode::All => $successes >= $total,
			JoinMode::FirstSuccess => $successes >= 1,
			JoinMode::Quorum => $successes >= max( 1, (int) ( $step_def['quorum'] ?? 1 ) ),
		};
	}

	/**
	 * Determine whether a fan-out step can no longer satisfy its join condition.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime fan-out state.
	 * @return bool
	 */
	private function fan_out_join_impossible( array $step_def, array $runtime ): bool {
		$mode      = JoinMode::from( $step_def['join_mode'] ?? JoinMode::All->value );
		$successes = count( $runtime['results'] ?? array() );
		$failures  = count( $runtime['failures'] ?? array() );
		$total     = count( $runtime['items'] ?? array() );
		$remaining = max( 0, $total - $successes - $failures );

		return match ( $mode ) {
			JoinMode::All => $failures > 0,
			JoinMode::FirstSuccess => 0 === $remaining && 0 === $successes,
			JoinMode::Quorum => $successes + $remaining < max( 1, (int) ( $step_def['quorum'] ?? 1 ) ),
		};
	}

	/**
	 * Resolve the public result key for a fan-out aggregate.
	 *
	 * @param array $step_def    Step definition.
	 * @param int   $step_index  Step index.
	 * @return string
	 */
	private function fan_out_result_key( array $step_def, int $step_index ): string {
		$result_key = $step_def['result_key'] ?? null;
		if ( is_string( $result_key ) && '' !== trim( $result_key ) ) {
			return trim( $result_key );
		}

		$name = $step_def['name'] ?? (string) $step_index;
		return $name . '_results';
	}

	/**
	 * Build final step output for a settled fan-out step.
	 *
	 * @param array $state      Workflow state.
	 * @param array $step_def   Step definition.
	 * @param array $runtime    Runtime fan-out state.
	 * @param int   $step_index Step index.
	 * @return array
	 * @throws \RuntimeException If the reducer class is invalid or returns invalid output.
	 */
	private function fan_out_step_output( array $state, array $step_def, array $runtime, int $step_index ): array {
		$result_key = $this->fan_out_result_key( $step_def, $step_index );
		$aggregate  = $this->build_fan_out_aggregate( $step_def, $runtime );
		$output     = array( $result_key => $aggregate );

		$reducer_class = $step_def['reducer_class'] ?? null;
		if ( ! is_string( $reducer_class ) || '' === trim( $reducer_class ) ) {
			return $output;
		}

		if ( ! class_exists( $reducer_class ) ) {
			throw new \RuntimeException( "Fan-out reducer class '{$reducer_class}' not found." );
		}

		$reducer = new $reducer_class();
		if ( ! $reducer instanceof JoinReducer && ! method_exists( $reducer, 'reduce' ) ) {
			throw new \RuntimeException( "Fan-out reducer '{$reducer_class}' must implement reduce()." );
		}

		$reducer_state                = $state;
		$reducer_state[ $result_key ] = $aggregate;
		$reducer_output               = $reducer->reduce( $this->public_state( $reducer_state ), $aggregate );

		if ( ! is_array( $reducer_output ) ) {
			throw new \RuntimeException( "Fan-out reducer '{$reducer_class}' must return an array." );
		}

		return array_merge( $output, $reducer_output );
	}

	/**
	 * Finalise a settled step and enqueue the next transition.
	 *
	 * @param \PDO  $pdo              Active PDO connection.
	 * @param array $wf_row           Locked workflow row.
	 * @param array $job_row          Locked job row.
	 * @param int   $workflow_id      Workflow ID.
	 * @param int   $completed_job_id Completed job ID.
	 * @param int   $current_step     Current step index.
	 * @param array $state            Workflow state.
	 * @param array $steps            All step definitions.
	 * @param int   $total_steps      Total step count.
	 * @param array $step_output      Step output to merge into state.
	 * @param int   $duration_ms      Step duration.
	 * @param bool  $log_job_completion Whether to emit the job completion log entry.
	 * @throws \RuntimeException If `_goto` references an unknown step name.
	 */
	private function finalize_step_completion(
		\PDO $pdo,
		array $wf_row,
		array $job_row,
		int $workflow_id,
		int $completed_job_id,
		int $current_step,
		array $state,
		array $steps,
		int $total_steps,
		array $step_output,
		int $duration_ms,
		bool $log_job_completion = true,
	): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$this->merge_step_output_into_state( $state, $step_output, $current_step );

		$current_step_def = $steps[ $current_step ] ?? null;
		$this->push_compensation_snapshot( $state, $current_step_def, $current_step );

		$next_step = $current_step + 1;
		if ( isset( $step_output['_goto'] ) ) {
			$goto_name  = $step_output['_goto'];
			$goto_index = $this->find_step_index_by_name( $steps, $goto_name );

			if ( null === $goto_index ) {
				throw new \RuntimeException(
					"Workflow {$workflow_id}: _goto target '{$goto_name}' not found."
				);
			}

			$next_step = $goto_index;
		}

		$is_last   = $next_step >= $total_steps;
		$is_paused = WorkflowStatus::Paused->value === $wf_row['status'];

		if ( $is_last ) {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET state = :state, current_step = :step, status = 'completed', completed_at = NOW()
				WHERE id = :id"
			);
		} else {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl} SET state = :state, current_step = :step WHERE id = :id"
			);
		}

		$stmt->execute(
			array(
				'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
				'step'  => $next_step,
				'id'    => $workflow_id,
			)
		);

		$this->mark_workflow_job_completed( $pdo, $completed_job_id );

		if ( $log_job_completion ) {
			$this->logger->log(
				LogEvent::Completed,
				array(
					'job_id'         => $completed_job_id,
					'workflow_id'    => $workflow_id,
					'step_index'     => $current_step,
					'handler'        => $job_row['handler'] ?? '',
					'queue'          => $job_row['queue'] ?? 'default',
					'duration_ms'    => $duration_ms,
					'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
				)
			);
		}

		if ( null !== $this->event_log ) {
			$this->event_log->record_step_completed(
				workflow_id: $workflow_id,
				step_index: $current_step,
				handler: $job_row['handler'] ?? '',
				state_snapshot: $this->public_state( $state ),
				step_output: $step_output,
				duration_ms: $duration_ms,
			);
		}

		if ( $is_last ) {
			$this->logger->log(
				LogEvent::WorkflowCompleted,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $wf_row['name'],
					'queue'       => $job_row['queue'] ?? 'default',
				)
			);
			$this->on_workflow_completed( $workflow_id, $state, $pdo );
			return;
		}

		if ( ! $is_paused && isset( $steps[ $next_step ] ) ) {
			$queue_name   = $state['_queue'] ?? 'default';
			$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
			$max_attempts = $state['_max_attempts'] ?? 3;

			$this->enqueue_step_def(
				$steps[ $next_step ],
				$workflow_id,
				$next_step,
				$queue_name,
				$priority,
				$max_attempts,
			);
		}
	}

	/**
	 * Enqueue a step definition as one or more jobs within a transaction.
	 *
	 * @param array|string $step_def    Step definition.
	 * @param int          $workflow_id Workflow ID.
	 * @param int          $step_index  Step index.
	 * @param string       $queue_name  Queue name.
	 * @param Priority     $priority    Priority level.
	 * @param int          $max_attempts Maximum attempts.
	 */
	private function enqueue_step_def(
		array|string $step_def,
		int $workflow_id,
		int $step_index,
		string $queue_name,
		Priority $priority,
		int $max_attempts,
	): void {
		$type = $this->resolve_step_type( $step_def );

		if ( 'parallel' === $type ) {
			$handlers = $step_def['handlers'] ?? array();
			foreach ( $handlers as $handler_class ) {
				$handler_defaults = HandlerMetadata::from_class( $handler_class );
				$this->queue->dispatch(
					handler: $handler_class,
					payload: array(),
					queue: $queue_name,
					priority: $priority,
					max_attempts: $handler_defaults['max_attempts'] ?? $max_attempts,
					workflow_id: $workflow_id,
					step_index: $step_index,
				);
			}
		} elseif ( 'fan_out' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_fan_out',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'sub_workflow' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_sub_workflow',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'timer' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_timer',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				delay: $step_def['delay_seconds'] ?? 0,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'signal' === $type ) {
			$this->enqueue_signal_step( $step_def, $workflow_id, $step_index );
		} else {
			$handler          = $this->resolve_step_handler( $step_def );
			$handler_defaults = HandlerMetadata::from_class( $handler );
			$this->queue->dispatch(
				handler: $handler,
				payload: array(),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $handler_defaults['max_attempts'] ?? $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		}
	}

	/**
	 * Handle a signal step: check for pre-existing signals or pause the workflow.
	 *
	 * When a workflow reaches a signal step, it checks whether the signal has
	 * already been sent. If so, the signal data is merged into state and the
	 * workflow advances. If not, the workflow is set to 'waiting_signal' status.
	 *
	 * @param array $step_def    Signal step definition.
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Step index.
	 */
	private function enqueue_signal_step( array $step_def, int $workflow_id, int $step_index ): void {
		$pdo         = $this->conn->pdo();
		$wf_tbl      = $this->conn->table( Config::table_workflows() );
		$sig_tbl     = $this->conn->table( Config::table_signals() );
		$signal_name = $step_def['signal_name'] ?? '';

		$stmt = $pdo->prepare(
			"SELECT payload FROM {$sig_tbl}
			WHERE workflow_id = :workflow_id AND signal_name = :signal_name
			ORDER BY id ASC LIMIT 1"
		);
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'signal_name' => $signal_name,
			)
		);
		$signal_row = $stmt->fetch();

		if ( $signal_row ) {
			$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			if ( ! $wf_row ) {
				return;
			}

				$state       = json_decode( $wf_row['state'], true ) ?: array();
				$signal_data = json_decode( $signal_row['payload'], true ) ?: array();
				$steps       = $state['_steps'] ?? array();
				$total_steps = (int) $wf_row['total_steps'];

			foreach ( $signal_data as $key => $value ) {
				if ( ! str_starts_with( $key, '_' ) ) {
					$state[ $key ] = $value;
				}
			}

				$current_step_def = $steps[ $step_index ] ?? null;
				$this->push_compensation_snapshot( $state, $current_step_def, $step_index );

				$next_step = $step_index + 1;
				$is_last   = $next_step >= $total_steps;

			if ( $is_last ) {
				$upd = $pdo->prepare(
					"UPDATE {$wf_tbl}
					SET state = :state, current_step = :step, status = 'completed', completed_at = NOW()
					WHERE id = :id"
				);
				$upd->execute(
					array(
						'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'  => $next_step,
						'id'    => $workflow_id,
					)
				);

				$this->logger->log(
					LogEvent::WorkflowCompleted,
					array(
						'workflow_id' => $workflow_id,
						'handler'     => $wf_row['name'],
						'queue'       => $state['_queue'] ?? 'default',
					)
				);

				$this->on_workflow_completed( $workflow_id, $state, $pdo );
			} else {
				$upd = $pdo->prepare(
					"UPDATE {$wf_tbl} SET state = :state, current_step = :step WHERE id = :id"
				);
				$upd->execute(
					array(
						'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'  => $next_step,
						'id'    => $workflow_id,
					)
				);

				if ( isset( $steps[ $next_step ] ) ) {
					$queue_name   = $state['_queue'] ?? 'default';
					$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
					$max_attempts = $state['_max_attempts'] ?? 3;

					$this->enqueue_step_def(
						$steps[ $next_step ],
						$workflow_id,
						$next_step,
						$queue_name,
						$priority,
						$max_attempts,
					);
				}
			}
		} else {
			$wf_stmt = $pdo->prepare( "SELECT state FROM {$wf_tbl} WHERE id = :id" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			$state                    = $wf_row ? ( json_decode( $wf_row['state'], true ) ?: array() ) : array();
			$state['_waiting_signal'] = $signal_name;

			$upd = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET status = :status, state = :state, current_step = :step
				WHERE id = :id"
			);
			$upd->execute(
				array(
					'status' => WorkflowStatus::WaitingSignal->value,
					'state'  => json_encode( $state, JSON_THROW_ON_ERROR ),
					'step'   => $step_index,
					'id'     => $workflow_id,
				)
			);
		}
	}

	/**
	 * Expand a fan-out placeholder into branch jobs for the current workflow step.
	 *
	 * @param int   $workflow_id    Workflow ID.
	 * @param int   $job_id         Placeholder job ID.
	 * @param int   $step_index     Step index.
	 * @param array $workflow_state Current workflow state.
	 * @return bool True when the placeholder job should be logged as completed.
	 * @throws \RuntimeException If the fan-out step definition or source state is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_fan_out_step( int $workflow_id, int $job_id, int $step_index, array $workflow_state ): bool {
		$pdo                   = $this->conn->pdo();
		$wf_tbl                = $this->conn->table( Config::table_workflows() );
		$jb_tbl                = $this->conn->table( Config::table_jobs() );
		$state                 = $workflow_state;
		$should_log_completion = false;
		$should_compensate     = false;

		$pdo->beginTransaction();
		try {
			$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				return false;
			}

			$state    = json_decode( $wf_row['state'], true ) ?: $workflow_state;
			$steps    = $state['_steps'] ?? array();
			$step_def = $steps[ $step_index ] ?? null;

			if ( ! is_array( $step_def ) || 'fan_out' !== ( $step_def['type'] ?? '' ) ) {
				throw new \RuntimeException( "Step {$step_index} is not a fan_out definition." );
			}

			$job_stmt = $pdo->prepare( "SELECT * FROM {$jb_tbl} WHERE id = :id FOR UPDATE" );
			$job_stmt->execute( array( 'id' => $job_id ) );
			$job_row = $job_stmt->fetch();

			if ( ! $job_row || JobStatus::Processing->value !== $job_row['status'] ) {
				$pdo->commit();
				return false;
			}

			$current_step = (int) $wf_row['current_step'];
			if ( $current_step !== $step_index || ! in_array( $wf_row['status'], array( WorkflowStatus::Running->value, WorkflowStatus::Paused->value ), true ) ) {
				$mark = $pdo->prepare(
					"UPDATE {$jb_tbl} SET status = :status, completed_at = NOW() WHERE id = :id"
				);
				$mark->execute(
					array(
						'status' => JobStatus::Completed->value,
						'id'     => $job_id,
					)
				);
				$pdo->commit();
				return true;
			}

			$runtime = $state['_fan_out_steps'][ $step_index ] ?? null;
			if ( ! is_array( $runtime ) || empty( $runtime['initialized'] ) ) {
				$items = $state[ $step_def['items_key'] ] ?? array();
				if ( ! is_array( $items ) ) {
					throw new \RuntimeException(
						"Fan-out step '{$step_def['name']}' expected state key '{$step_def['items_key']}' to contain an array."
					);
				}

				$runtime = array(
					'initialized'  => true,
					'items'        => array_values( $items ),
					'results'      => array(),
					'failures'     => array(),
					'winner_index' => null,
				);
			} else {
				$runtime = $this->normalize_fan_out_runtime( $runtime );
			}

			$all_indexes = array_keys( $runtime['items'] ?? array() );
			$done        = array_map( 'intval', array_merge( array_keys( $runtime['results'] ?? array() ), array_keys( $runtime['failures'] ?? array() ) ) );
			$missing     = array_values( array_diff( $all_indexes, $done ) );

			$state['_fan_out_steps'][ $step_index ] = $runtime;

			if ( empty( $missing ) ) {
				if ( $this->fan_out_join_satisfied( $step_def, $runtime ) ) {
					$step_output = $this->fan_out_step_output( $state, $step_def, $runtime, $step_index );
					$this->finalize_step_completion(
						$pdo,
						$wf_row,
						$job_row,
						$workflow_id,
						$job_id,
						$step_index,
						$state,
						$steps,
						(int) $wf_row['total_steps'],
						$step_output,
						0,
						false,
					);
					$should_log_completion = true;
				} elseif ( $this->fan_out_join_impossible( $step_def, $runtime ) ) {
					$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $workflow_id, $job_id, 'Fan-out join could not be satisfied.', $state );
					$should_compensate = ! empty( $state['_compensate_on_failure'] );
				} else {
					$this->mark_workflow_job_completed( $pdo, $job_id );
					$this->persist_internal_state( $workflow_id, $state );
					$should_log_completion = true;
				}

				$pdo->commit();
				if ( $should_compensate ) {
					$state = $this->run_compensations( $workflow_id, $state, 'failure' );
					$this->persist_internal_state( $workflow_id, $state );
				}
				$this->invalidate_workflow_cache( $workflow_id );
				return $should_log_completion;
			}

			$queue_name       = $state['_queue'] ?? 'default';
			$priority         = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
			$branch_handler   = $step_def['class'];
			$handler_defaults = HandlerMetadata::from_class( $branch_handler );
			$effective_max    = $handler_defaults['max_attempts'] ?? ( $state['_max_attempts'] ?? 3 );

			foreach ( $missing as $item_index ) {
				$this->queue->dispatch(
					handler: $branch_handler,
					payload: array(
						'__fan_out' => array(
							'item_index' => $item_index,
							'item'       => $runtime['items'][ $item_index ],
						),
					),
					queue: $queue_name,
					priority: $priority,
					max_attempts: $effective_max,
					workflow_id: $workflow_id,
					step_index: $step_index,
				);
			}

			$this->persist_internal_state( $workflow_id, $state );

			$this->mark_workflow_job_completed( $pdo, $job_id );
			$should_log_completion = true;

			$pdo->commit();
			$this->invalidate_workflow_cache( $workflow_id );
			return $should_log_completion;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Record a terminal fan-out branch failure and decide whether the workflow should fail.
	 *
	 * @param int    $workflow_id   Workflow ID.
	 * @param int    $failed_job_id Failed branch job ID.
	 * @param string $error_message Error message.
	 * @return bool True when the workflow failed as a result.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_fan_out_terminal_failure( int $workflow_id, int $failed_job_id, string $error_message ): bool {
		$pdo               = $this->conn->pdo();
		$wf_tbl            = $this->conn->table( Config::table_workflows() );
		$jb_tbl            = $this->conn->table( Config::table_jobs() );
		$state             = array();
		$should_compensate = false;
		$workflow_failed   = false;

		$pdo->beginTransaction();
		try {
			$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			$job_stmt = $pdo->prepare( "SELECT * FROM {$jb_tbl} WHERE id = :id FOR UPDATE" );
			$job_stmt->execute( array( 'id' => $failed_job_id ) );
			$job_row = $job_stmt->fetch();

			if ( ! $wf_row || ! $job_row ) {
				$pdo->commit();
				return false;
			}

			if ( ! in_array( $job_row['status'], array( JobStatus::Pending->value, JobStatus::Processing->value ), true ) ) {
				$pdo->commit();
				return false;
			}

			$bury_stmt = $pdo->prepare(
				"UPDATE {$jb_tbl}
				SET status = :status, failed_at = NOW(), error_message = :error
				WHERE id = :id"
			);
			$bury_stmt->execute(
				array(
					'status' => JobStatus::Buried->value,
					'error'  => $error_message,
					'id'     => $failed_job_id,
				)
			);

			$state        = json_decode( $wf_row['state'], true ) ?: array();
			$current_step = (int) $wf_row['current_step'];
			$step_index   = $job_row['step_index'] !== null ? (int) $job_row['step_index'] : null;

			if (
				null === $step_index
				|| $step_index !== $current_step
				|| ! in_array( $wf_row['status'], array( WorkflowStatus::Running->value, WorkflowStatus::Paused->value ), true )
			) {
				$pdo->commit();
				return false;
			}

			$steps    = $state['_steps'] ?? array();
			$step_def = $steps[ $step_index ] ?? null;
			if ( ! is_array( $step_def ) || 'fan_out' !== ( $step_def['type'] ?? '' ) ) {
				$pdo->commit();
				return false;
			}

				$runtime = $state['_fan_out_steps'][ $step_index ] ?? null;
			if ( ! is_array( $runtime ) ) {
				$runtime = array(
					'initialized'  => true,
					'items'        => array(),
					'results'      => array(),
					'failures'     => array(),
					'winner_index' => null,
				);
			} else {
				$runtime = $this->normalize_fan_out_runtime( $runtime );
			}

				$payload     = $job_row['payload'] ? ( json_decode( $job_row['payload'], true ) ?: array() ) : array();
				$branch_meta = $payload['__fan_out'] ?? array();
				$item_index  = $branch_meta['item_index'] ?? null;
				$item        = $branch_meta['item'] ?? null;

			if ( null !== $item_index ) {
				$runtime['failures'][ (string) $item_index ] = array(
					'index'         => (int) $item_index,
					'item'          => $item,
					'error_message' => $error_message,
					'job_id'        => $failed_job_id,
				);
				unset( $runtime['results'][ (string) $item_index ] );
			}

				$state['_fan_out_steps'][ $step_index ] = $runtime;

			if ( ! $this->fan_out_join_impossible( $step_def, $runtime ) ) {
				$this->persist_internal_state( $workflow_id, $state );
				$pdo->commit();
				$this->invalidate_workflow_cache( $workflow_id );
				return false;
			}

				$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $workflow_id, $failed_job_id, $error_message, $state );
				$should_compensate = ! empty( $state['_compensate_on_failure'] );
				$workflow_failed   = true;

				$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ( $should_compensate ) {
			$state = $this->run_compensations( $workflow_id, $state, 'failure' );
			$this->persist_internal_state( $workflow_id, $state );
		}

		$this->invalidate_workflow_cache( $workflow_id );
		return $workflow_failed;
	}

	/**
	 * Handle a signal step dispatched via the worker.
	 *
	 * Called by the Worker when it encounters a __queuety_signal placeholder.
	 * Delegates to the private enqueue_signal_step method.
	 *
	 * @param int   $workflow_id The workflow ID.
	 * @param array $step_def    The signal step definition.
	 * @param int   $step_index  The step index.
	 */
	public function handle_signal_step( int $workflow_id, array $step_def, int $step_index ): void {
		$this->enqueue_signal_step( $step_def, $workflow_id, $step_index );
	}

	/**
	 * Handle an external signal sent to a workflow.
	 *
	 * Inserts the signal into the queuety_signals table for audit purposes.
	 * If the workflow is currently waiting for this signal, it resumes the
	 * workflow by merging the signal data into state and advancing to the
	 * next step.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param string $signal_name The signal name.
	 * @param array  $data        Signal payload data.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_signal( int $workflow_id, string $signal_name, array $data = array() ): void {
		$pdo     = $this->conn->pdo();
		$wf_tbl  = $this->conn->table( Config::table_workflows() );
		$sig_tbl = $this->conn->table( Config::table_signals() );

		$pdo->beginTransaction();
		try {
			// Signals are persisted even when the workflow is not waiting yet.
			$ins = $pdo->prepare(
				"INSERT INTO {$sig_tbl} (workflow_id, signal_name, payload)
				VALUES (:workflow_id, :signal_name, :payload)"
			);
			$ins->execute(
				array(
					'workflow_id' => $workflow_id,
					'signal_name' => $signal_name,
					'payload'     => json_encode( $data, JSON_THROW_ON_ERROR ),
				)
			);

			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->commit();
				return;
			}

				$state          = json_decode( $wf_row['state'], true ) ?: array();
				$waiting_signal = $state['_waiting_signal'] ?? null;

			if (
				WorkflowStatus::WaitingSignal->value === $wf_row['status']
				&& $waiting_signal === $signal_name
			) {
				foreach ( $data as $key => $value ) {
					if ( ! str_starts_with( $key, '_' ) ) {
						$state[ $key ] = $value;
					}
				}

				unset( $state['_waiting_signal'] );

				$current_step     = (int) $wf_row['current_step'];
				$total_steps      = (int) $wf_row['total_steps'];
				$steps            = $state['_steps'] ?? array();
				$current_step_def = $steps[ $current_step ] ?? null;
				$this->push_compensation_snapshot( $state, $current_step_def, $current_step );
				$next_step = $current_step + 1;
				$is_last   = $next_step >= $total_steps;

				if ( $is_last ) {
					$upd = $pdo->prepare(
						"UPDATE {$wf_tbl}
						SET status = 'completed', state = :state, current_step = :step, completed_at = NOW()
						WHERE id = :id"
					);
					$upd->execute(
						array(
							'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
							'step'  => $next_step,
							'id'    => $workflow_id,
						)
					);

					$this->logger->log(
						LogEvent::WorkflowCompleted,
						array(
							'workflow_id' => $workflow_id,
							'handler'     => $wf_row['name'],
							'queue'       => $state['_queue'] ?? 'default',
						)
					);

					$this->on_workflow_completed( $workflow_id, $state, $pdo );
				} else {
					$upd = $pdo->prepare(
						"UPDATE {$wf_tbl}
						SET status = 'running', state = :state, current_step = :step
						WHERE id = :id"
					);
					$upd->execute(
						array(
							'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
							'step'  => $next_step,
							'id'    => $workflow_id,
						)
					);

					if ( isset( $steps[ $next_step ] ) ) {
						$queue_name   = $state['_queue'] ?? 'default';
						$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
						$max_attempts = $state['_max_attempts'] ?? 3;

						$this->enqueue_step_def(
							$steps[ $next_step ],
							$workflow_id,
							$next_step,
							$queue_name,
							$priority,
							$max_attempts,
						);
					}
				}
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Count completed jobs for a specific workflow step.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @param int $step_index  Step index.
	 * @return int Number of completed jobs.
	 */
	private function count_completed_jobs_for_step( int $workflow_id, int $step_index ): int {
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) AS cnt FROM {$jb_tbl}
			WHERE workflow_id = :workflow_id
				AND step_index = :step_index
				AND status = :status"
		);
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
				'status'      => JobStatus::Completed->value,
			)
		);
		$row = $stmt->fetch();
		return (int) ( $row['cnt'] ?? 0 );
	}

	/**
	 * Advance a workflow to its next step after the current step completes.
	 *
	 * This is the critical transactional boundary. All operations happen atomically:
	 * merge step output into state, advance current_step, complete the job,
	 * enqueue the next step (or mark workflow completed), and log.
	 *
	 * @param int   $workflow_id    The workflow ID.
	 * @param int   $completed_job_id The job ID that just completed.
	 * @param array $step_output    Data returned by the step handler.
	 * @param int   $duration_ms    Step execution duration in milliseconds.
	 * @throws \RuntimeException If the workflow is not found or if _goto target is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function advance_step( int $workflow_id, int $completed_job_id, array $step_output, int $duration_ms = 0 ): void {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$jb_tbl = $this->conn->table( Config::table_jobs() );

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				throw new \RuntimeException( "Workflow {$workflow_id} not found." );
			}

				$job_stmt = $pdo->prepare( "SELECT * FROM {$jb_tbl} WHERE id = :id FOR UPDATE" );
				$job_stmt->execute( array( 'id' => $completed_job_id ) );
				$job_row = $job_stmt->fetch();

			if ( ! $job_row ) {
				throw new \RuntimeException( "Job {$completed_job_id} not found." );
			}

			if ( ! in_array( $wf_row['status'], array( WorkflowStatus::Running->value, WorkflowStatus::Paused->value ), true ) ) {
				if ( $this->is_completable_workflow_job_status( $job_row['status'] ) ) {
					$stmt = $pdo->prepare(
						"UPDATE {$jb_tbl}
							SET status = :status, failed_at = NOW(), error_message = :error
							WHERE id = :id"
					);
					$stmt->execute(
						array(
							'status' => JobStatus::Buried->value,
							'error'  => 'Workflow advanced before job completion.',
							'id'     => $completed_job_id,
						)
					);
				}
				$pdo->commit();
				return;
			}

			if ( ! $this->is_completable_workflow_job_status( $job_row['status'] ) ) {
				$pdo->commit();
				return;
			}

				$state        = json_decode( $wf_row['state'], true ) ?: array();
				$current_step = (int) $wf_row['current_step'];
				$total_steps  = (int) $wf_row['total_steps'];
				$steps        = $state['_steps'] ?? array();

			if ( $job_row['step_index'] !== null && (int) $job_row['step_index'] !== $current_step ) {
				$stmt = $pdo->prepare(
					"UPDATE {$jb_tbl}
						SET status = :status, failed_at = NOW(), error_message = :error
						WHERE id = :id"
				);
				$stmt->execute(
					array(
						'status' => JobStatus::Buried->value,
						'error'  => 'Workflow advanced before job completion.',
						'id'     => $completed_job_id,
					)
				);
				$pdo->commit();
				return;
			}

				$current_step_def  = $steps[ $current_step ] ?? null;
				$current_step_type = $this->resolve_step_type( $current_step_def );

			if ( 'fan_out' === $current_step_type ) {
				$payload     = json_decode( $job_row['payload'], true ) ?: array();
				$branch_meta = $payload['__fan_out'] ?? null;

				if ( ! is_array( $current_step_def ) || ! is_array( $branch_meta ) || ! array_key_exists( 'item_index', $branch_meta ) ) {
					throw new \RuntimeException( "Workflow {$workflow_id}: invalid fan-out branch payload." );
				}

				$runtime = $state['_fan_out_steps'][ $current_step ] ?? null;
				if ( ! is_array( $runtime ) || empty( $runtime['initialized'] ) ) {
					throw new \RuntimeException( "Workflow {$workflow_id}: fan-out runtime state missing for step {$current_step}." );
				}
				$runtime = $this->normalize_fan_out_runtime( $runtime );

				if ( ! empty( $runtime['settled'] ) ) {
					$stmt = $pdo->prepare(
						"UPDATE {$jb_tbl}
							SET status = :status, failed_at = NOW(), error_message = :error
							WHERE id = :id"
					);
					$stmt->execute(
						array(
							'status' => JobStatus::Buried->value,
							'error'  => 'Fan-out step already settled.',
							'id'     => $completed_job_id,
						)
					);
					$pdo->commit();
					return;
				}

				$item_index = (int) $branch_meta['item_index'];
				$item       = $runtime['items'][ $item_index ] ?? $branch_meta['item'] ?? null;

				$runtime['results'][ (string) $item_index ] = array(
					'index'  => $item_index,
					'item'   => $item,
					'output' => $step_output,
					'job_id' => $completed_job_id,
				);
				unset( $runtime['failures'][ (string) $item_index ] );

				if ( null === $runtime['winner_index'] ) {
					$runtime['winner_index'] = $item_index;
				}

				$state['_fan_out_steps'][ $current_step ] = $runtime;

				if ( ! $this->fan_out_join_satisfied( $current_step_def, $runtime ) ) {
					$this->persist_internal_state( $workflow_id, $state );

					$this->mark_workflow_job_completed( $pdo, $completed_job_id );

					$this->logger->log(
						LogEvent::Completed,
						array(
							'job_id'         => $completed_job_id,
							'workflow_id'    => $workflow_id,
							'step_index'     => $current_step,
							'handler'        => $job_row['handler'] ?? '',
							'queue'          => $job_row['queue'] ?? 'default',
							'duration_ms'    => $duration_ms,
							'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
						)
					);

					$pdo->commit();
					$this->invalidate_workflow_cache( $workflow_id );
					return;
				}

				$runtime['settled']                       = true;
				$state['_fan_out_steps'][ $current_step ] = $runtime;
				$this->persist_internal_state( $workflow_id, $state );
				$this->bury_active_jobs_for_step( $workflow_id, $current_step, 'Fan-out join settled early.', $completed_job_id );

				$step_output = $this->fan_out_step_output( $state, $current_step_def, $runtime, $current_step );
				$this->finalize_step_completion(
					$pdo,
					$wf_row,
					$job_row,
					$workflow_id,
					$completed_job_id,
					$current_step,
					$state,
					$steps,
					$total_steps,
					$step_output,
					$duration_ms,
				);

				$pdo->commit();
				$this->invalidate_workflow_cache( $workflow_id );
				return;
			}

			if ( 'parallel' === $current_step_type ) {
				$this->merge_step_output_into_state( $state, $step_output, $current_step );
				$total_handlers = count( $current_step_def['handlers'] ?? array() );

				// Parallel branches can finish out of order, so count only after this branch is marked complete.
				$mark_stmt = $pdo->prepare(
					"UPDATE {$jb_tbl} SET status = :status, completed_at = NOW() WHERE id = :id"
				);
				$mark_stmt->execute(
					array(
						'status' => JobStatus::Completed->value,
						'id'     => $completed_job_id,
					)
				);

				$completed_count = $this->count_completed_jobs_for_step( $workflow_id, $current_step );

				$state_stmt = $pdo->prepare(
					"UPDATE {$wf_tbl} SET state = :state WHERE id = :id"
				);
				$state_stmt->execute(
					array(
						'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
						'id'    => $workflow_id,
					)
				);

				$this->logger->log(
					LogEvent::Completed,
					array(
						'job_id'         => $completed_job_id,
						'workflow_id'    => $workflow_id,
						'step_index'     => $current_step,
						'handler'        => $job_row['handler'] ?? '',
						'queue'          => $job_row['queue'] ?? 'default',
						'duration_ms'    => $duration_ms,
						'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
					)
				);

				if ( $completed_count < $total_handlers ) {
					$pdo->commit();
					return;
				}

				// Another branch may have merged fresher state before this one won the join race.
				$re_stmt = $pdo->prepare( "SELECT state FROM {$wf_tbl} WHERE id = :id" );
				$re_stmt->execute( array( 'id' => $workflow_id ) );
				$re_row = $re_stmt->fetch();
				$state  = json_decode( $re_row['state'], true ) ?: array();

				if ( null !== $this->event_log ) {
					$snapshot = array_filter(
						$state,
						fn( string $key ) => ! str_starts_with( $key, '_' ),
						ARRAY_FILTER_USE_KEY
					);

					$this->event_log->record_step_completed(
						workflow_id: $workflow_id,
						step_index: $current_step,
						handler: $job_row['handler'] ?? '',
						state_snapshot: $snapshot,
						step_output: $step_output,
						duration_ms: $duration_ms,
					);
				}

				$next_step = $current_step + 1;
				$is_last   = $next_step >= $total_steps;
				$is_paused = WorkflowStatus::Paused->value === $wf_row['status'];
				$this->push_compensation_snapshot( $state, $current_step_def, $current_step );

				if ( $is_last ) {
					$upd_stmt = $pdo->prepare(
						"UPDATE {$wf_tbl}
						SET state = :state, current_step = :step, status = 'completed', completed_at = NOW()
						WHERE id = :id"
					);
					$upd_stmt->execute(
						array(
							'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
							'step'  => $next_step,
							'id'    => $workflow_id,
						)
					);
				} else {
					$upd_stmt = $pdo->prepare(
						"UPDATE {$wf_tbl} SET state = :state, current_step = :step WHERE id = :id"
					);
					$upd_stmt->execute(
						array(
							'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
							'step'  => $next_step,
							'id'    => $workflow_id,
						)
					);
				}

				if ( $is_last ) {
					$this->logger->log(
						LogEvent::WorkflowCompleted,
						array(
							'workflow_id' => $workflow_id,
							'handler'     => $wf_row['name'],
							'queue'       => $job_row['queue'] ?? 'default',
						)
					);
					$this->on_workflow_completed( $workflow_id, $state, $pdo );
				} elseif ( ! $is_paused && isset( $steps[ $next_step ] ) ) {
					$queue_name   = $state['_queue'] ?? 'default';
					$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
					$max_attempts = $state['_max_attempts'] ?? 3;

					$this->enqueue_step_def(
						$steps[ $next_step ],
						$workflow_id,
						$next_step,
						$queue_name,
						$priority,
						$max_attempts,
					);
				}

				$pdo->commit();
				$this->invalidate_workflow_cache( $workflow_id );
				return;
			}

				$this->finalize_step_completion(
					$pdo,
					$wf_row,
					$job_row,
					$workflow_id,
					$completed_job_id,
					$current_step,
					$state,
					$steps,
					$total_steps,
					$step_output,
					$duration_ms,
				);

				$pdo->commit();
				$this->invalidate_workflow_cache( $workflow_id );
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Handle workflow completion side-effects.
	 *
	 * If this workflow has a parent workflow, advance the parent.
	 *
	 * @param int   $workflow_id The completed workflow ID.
	 * @param array $state       The completed workflow's state.
	 * @param \PDO  $pdo         Active PDO connection (may be in a transaction).
	 */
	private function on_workflow_completed( int $workflow_id, array $state, \PDO $pdo ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$stmt = $pdo->prepare(
			"SELECT parent_workflow_id, parent_step_index FROM {$wf_tbl} WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row || empty( $row['parent_workflow_id'] ) ) {
			return;
		}

		$parent_id   = (int) $row['parent_workflow_id'];
		$parent_step = (int) $row['parent_step_index'];

		$parent_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
		$parent_stmt->execute( array( 'id' => $parent_id ) );
		$parent_row = $parent_stmt->fetch();

		if ( ! $parent_row ) {
			return;
		}

		$parent_state = json_decode( $parent_row['state'], true ) ?: array();

		foreach ( $state as $key => $value ) {
			if ( ! str_starts_with( $key, '_' ) ) {
				$parent_state[ $key ] = $value;
			}
		}

			$parent_steps     = $parent_state['_steps'] ?? array();
			$current_step_def = $parent_steps[ $parent_step ] ?? null;
			$this->push_compensation_snapshot( $parent_state, $current_step_def, $parent_step );

			$parent_total_steps = (int) $parent_row['total_steps'];
			$next_step          = $parent_step + 1;
		$is_last                = $next_step >= $parent_total_steps;
		$is_paused              = WorkflowStatus::Paused->value === $parent_row['status'];

		if ( $is_last ) {
			$upd_stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET state = :state, current_step = :step, status = 'completed', completed_at = NOW()
				WHERE id = :id"
			);
			$upd_stmt->execute(
				array(
					'state' => json_encode( $parent_state, JSON_THROW_ON_ERROR ),
					'step'  => $next_step,
					'id'    => $parent_id,
				)
			);

			$this->logger->log(
				LogEvent::WorkflowCompleted,
				array(
					'workflow_id' => $parent_id,
					'handler'     => $parent_row['name'],
					'queue'       => $parent_state['_queue'] ?? 'default',
				)
			);

			// Nested sub-workflows resume upward one parent at a time.
			$this->on_workflow_completed( $parent_id, $parent_state, $pdo );
		} else {
			$upd_stmt = $pdo->prepare(
				"UPDATE {$wf_tbl} SET state = :state, current_step = :step WHERE id = :id"
			);
			$upd_stmt->execute(
				array(
					'state' => json_encode( $parent_state, JSON_THROW_ON_ERROR ),
					'step'  => $next_step,
					'id'    => $parent_id,
				)
			);

			if ( ! $is_paused && isset( $parent_steps[ $next_step ] ) ) {
				$queue_name   = $parent_state['_queue'] ?? 'default';
				$priority     = Priority::tryFrom( $parent_state['_priority'] ?? 0 ) ?? Priority::Low;
				$max_attempts = $parent_state['_max_attempts'] ?? 3;

				$this->enqueue_step_def(
					$parent_steps[ $next_step ],
					$parent_id,
					$next_step,
					$queue_name,
					$priority,
					$max_attempts,
				);
			}
		}
	}

	/**
	 * Dispatch a sub-workflow linked to a parent workflow.
	 *
	 * @param int    $parent_workflow_id The parent workflow ID.
	 * @param int    $parent_step_index  The step index in the parent.
	 * @param string $name               Sub-workflow name.
	 * @param array  $steps              Step definitions array (from build_steps()).
	 * @param array  $initial_state      Initial state for the sub-workflow.
	 * @param string $queue_name         Queue name.
	 * @param int    $priority_value     Priority value.
	 * @param int    $max_attempts       Max attempts.
	 * @return int The sub-workflow ID.
	 * @throws \Throwable If the database operation fails.
	 */
	public function dispatch_sub_workflow(
		int $parent_workflow_id,
		int $parent_step_index,
		string $name,
		array $steps,
		array $initial_state,
		string $queue_name = 'default',
		int $priority_value = 0,
		int $max_attempts = 3,
	): int {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$state                  = $initial_state;
		$state['_steps']        = $steps;
		$state['_queue']        = $queue_name;
		$state['_priority']     = $priority_value;
		$state['_max_attempts'] = $max_attempts;

		$stmt = $pdo->prepare(
			"INSERT INTO {$wf_tbl}
			(name, status, state, current_step, total_steps, parent_workflow_id, parent_step_index)
			VALUES (:name, 'running', :state, 0, :total_steps, :parent_id, :parent_step)"
		);
		$stmt->execute(
			array(
				'name'        => $name,
				'state'       => json_encode( $state, JSON_THROW_ON_ERROR ),
				'total_steps' => count( $steps ),
				'parent_id'   => $parent_workflow_id,
				'parent_step' => $parent_step_index,
			)
		);
		$sub_id = (int) $pdo->lastInsertId();

		$priority = Priority::tryFrom( $priority_value ) ?? Priority::Low;

		if ( ! empty( $steps ) ) {
			$this->enqueue_step_def(
				$steps[0],
				$sub_id,
				0,
				$queue_name,
				$priority,
				$max_attempts,
			);
		}

		$this->logger->log(
			LogEvent::WorkflowStarted,
			array(
				'workflow_id' => $sub_id,
				'handler'     => $name,
				'queue'       => $queue_name,
			)
		);

		return $sub_id;
	}

	/**
	 * Handle a sub-workflow step: dispatch the sub-workflow and mark the placeholder job.
	 *
	 * Called by the Worker when it encounters a __queuety_sub_workflow handler.
	 *
	 * @param int   $workflow_id    The parent workflow ID.
	 * @param int   $job_id         The placeholder job ID.
	 * @param int   $step_index     The step index.
	 * @param array $workflow_state The parent workflow's current state.
	 * @throws \RuntimeException If the step definition is not a sub_workflow.
	 */
	public function handle_sub_workflow_step( int $workflow_id, int $job_id, int $step_index, array $workflow_state ): void {
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$steps  = $workflow_state['_steps'] ?? array();

		$step_def = $steps[ $step_index ] ?? null;
		if ( ! $step_def || ! is_array( $step_def ) || 'sub_workflow' !== ( $step_def['type'] ?? '' ) ) {
			throw new \RuntimeException( "Step {$step_index} is not a sub_workflow definition." );
		}

		$sub_steps    = $step_def['sub_steps'] ?? array();
		$sub_name     = $step_def['sub_name'] ?? 'sub_workflow';
		$sub_queue    = $step_def['sub_queue'] ?? ( $workflow_state['_queue'] ?? 'default' );
		$sub_priority = $step_def['sub_priority'] ?? ( $workflow_state['_priority'] ?? 0 );
		$sub_max      = $step_def['sub_max_attempts'] ?? ( $workflow_state['_max_attempts'] ?? 3 );

		$initial_state = array();
		foreach ( $workflow_state as $key => $value ) {
			if ( ! str_starts_with( $key, '_' ) ) {
				$initial_state[ $key ] = $value;
			}
		}

		$this->dispatch_sub_workflow(
			parent_workflow_id: $workflow_id,
			parent_step_index: $step_index,
			name: $sub_name,
			steps: $sub_steps,
			initial_state: $initial_state,
			queue_name: $sub_queue,
			priority_value: $sub_priority,
			max_attempts: $sub_max,
		);

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$jb_tbl} SET status = :status, completed_at = NOW() WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Completed->value,
				'id'     => $job_id,
			)
		);

		// The parent must stay parked on this step until the child reports completion.
	}

	/**
	 * Cancel a workflow with optional cleanup handler execution.
	 *
	 * Loads the workflow state, runs the cleanup handler if defined,
	 * sets the status to cancelled, and buries any pending jobs.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @throws \RuntimeException If the workflow is not found or already completed/cancelled.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function cancel( int $workflow_id ): void {
		$pdo               = $this->conn->pdo();
		$wf_tbl            = $this->conn->table( Config::table_workflows() );
		$state             = array();
		$should_compensate = false;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				throw new \RuntimeException( "Workflow {$workflow_id} not found." );
			}

			$current_status = $wf_row['status'];

			if ( in_array( $current_status, array( 'completed', 'cancelled' ), true ) ) {
				$pdo->rollBack();
				throw new \RuntimeException(
					"Workflow {$workflow_id} is already {$current_status}."
				);
			}

			$state = json_decode( $wf_row['state'], true ) ?: array();

			$upd = $pdo->prepare(
					"UPDATE {$wf_tbl}
				SET status = :status, completed_at = NOW()
				WHERE id = :id"
				);
			$upd->execute(
				array(
					'status' => WorkflowStatus::Cancelled->value,
					'id'     => $workflow_id,
				)
			);

			$this->bury_active_jobs_for_workflow( $workflow_id, 'Workflow cancelled' );

			$this->logger->log(
				LogEvent::WorkflowCancelled,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $wf_row['name'],
					'queue'       => $state['_queue'] ?? 'default',
				)
			);

			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		$state = $this->run_compensations( $workflow_id, $state, 'cancel' );
		$this->persist_internal_state( $workflow_id, $state );

		$cancel_handler = $state['_on_cancel'] ?? null;
		if ( null !== $cancel_handler && class_exists( $cancel_handler ) ) {
			try {
				$handler_instance = new $cancel_handler();
				$handler_instance->handle( $this->public_state( $state ) );
			} catch ( \Throwable $e ) {
				$this->logger->log(
					LogEvent::Debug,
					array(
						'workflow_id'   => $workflow_id,
						'handler'       => $cancel_handler,
						'error_message' => $e->getMessage(),
						'context'       => array( 'type' => 'cancel_handler_failed' ),
					)
				);
			}
		}

		$this->invalidate_workflow_cache( $workflow_id );
	}

	/**
	 * Mark a workflow as failed.
	 *
	 * @param int    $workflow_id   The workflow ID.
	 * @param int    $failed_job_id The job that caused the failure.
	 * @param string $error_message Error description.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function fail( int $workflow_id, int $failed_job_id, string $error_message ): void {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$state  = array();

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				return;
			}

			$should_compensate = in_array( $wf_row['status'], array( WorkflowStatus::Running->value, WorkflowStatus::Paused->value ), true );
			$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $workflow_id, $failed_job_id, $error_message );
			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ( $should_compensate && ! empty( $state['_compensate_on_failure'] ) ) {
			$state = $this->run_compensations( $workflow_id, $state, 'failure' );
			$this->persist_internal_state( $workflow_id, $state );
		}

		$this->invalidate_workflow_cache( $workflow_id );
	}

	/**
	 * Get the current status of a workflow.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @return WorkflowState|null
	 */
	public function status( int $workflow_id ): ?WorkflowState {
		if ( null !== $this->cache ) {
			$cache_key = "queuety:wf_status:{$workflow_id}";
			$cached    = $this->cache->get( $cache_key );

			if ( $cached instanceof WorkflowState ) {
				return $cached;
			}
		}

		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return null;
		}

		$state = json_decode( $row['state'], true ) ?: array();

		$public_state = array_filter(
			$state,
			fn( string $key ) => ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		$result = new WorkflowState(
			workflow_id: (int) $row['id'],
			name: $row['name'],
			status: WorkflowStatus::from( $row['status'] ),
			current_step: (int) $row['current_step'],
			total_steps: (int) $row['total_steps'],
			state: $public_state,
			parent_workflow_id: $row['parent_workflow_id'] ? (int) $row['parent_workflow_id'] : null,
			parent_step_index: $row['parent_step_index'] !== null ? (int) $row['parent_step_index'] : null,
		);

		if ( null !== $this->cache ) {
			$this->cache->set( "queuety:wf_status:{$workflow_id}", $result, self::STATE_CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Retry a failed workflow from its failed step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @throws \RuntimeException If the workflow is not found or not in failed state.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function retry( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			throw new \RuntimeException( "Workflow {$workflow_id} not found." );
		}

		if ( WorkflowStatus::Failed->value !== $row['status'] ) {
			throw new \RuntimeException( "Workflow {$workflow_id} is not in failed state." );
		}

		$state        = json_decode( $row['state'], true ) ?: array();
		$current_step = (int) $row['current_step'];
		$steps        = $state['_steps'] ?? array();
		$queue_name   = $state['_queue'] ?? 'default';
		$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
		$max_attempts = $state['_max_attempts'] ?? 3;

		if ( ! empty( $state['_compensated'] ) ) {
			throw new \RuntimeException( "Workflow {$workflow_id} has already been compensated and cannot be retried." );
		}

		if ( ! isset( $steps[ $current_step ] ) ) {
			throw new \RuntimeException( "No step handler found for step {$current_step}." );
		}

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET status = 'running', failed_at = NULL, error_message = NULL
				WHERE id = :id"
			);
				$stmt->execute( array( 'id' => $workflow_id ) );

			if ( is_array( $steps[ $current_step ] ) && 'fan_out' === ( $steps[ $current_step ]['type'] ?? '' ) ) {
				$runtime = $state['_fan_out_steps'][ $current_step ] ?? null;
				if ( is_array( $runtime ) ) {
					$runtime['failures']                      = array();
					$runtime['settled']                       = false;
					$state['_fan_out_steps'][ $current_step ] = $runtime;
					$this->persist_internal_state( $workflow_id, $state );
				}
			}

			$this->enqueue_step_def(
					$steps[ $current_step ],
					$workflow_id,
					$current_step,
					$queue_name,
					$priority,
					$max_attempts,
				);

			$pdo->commit();
			$this->invalidate_workflow_cache( $workflow_id );
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Pause a running workflow. The current step finishes, but the next step is not enqueued.
	 *
	 * @param int $workflow_id The workflow ID.
	 */
	public function pause( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl} SET status = 'paused' WHERE id = :id AND status = 'running'"
		);
		$stmt->execute( array( 'id' => $workflow_id ) );

		$this->logger->log(
			LogEvent::WorkflowPaused,
			array(
				'workflow_id' => $workflow_id,
				'handler'     => '',
			)
		);

		$this->invalidate_workflow_cache( $workflow_id );
	}

	/**
	 * Resume a paused workflow by enqueuing its next step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @throws \RuntimeException If the workflow is not paused or has no more steps.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function resume( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row || WorkflowStatus::Paused->value !== $row['status'] ) {
			throw new \RuntimeException( "Workflow {$workflow_id} is not paused." );
		}

		$state        = json_decode( $row['state'], true ) ?: array();
		$current_step = (int) $row['current_step'];
		$total_steps  = (int) $row['total_steps'];
		$steps        = $state['_steps'] ?? array();

		if ( $current_step >= $total_steps ) {
			throw new \RuntimeException( "Workflow {$workflow_id} has no more steps." );
		}

		$queue_name   = $state['_queue'] ?? 'default';
		$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
		$max_attempts = $state['_max_attempts'] ?? 3;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl} SET status = 'running' WHERE id = :id"
			);
			$stmt->execute( array( 'id' => $workflow_id ) );

			$this->enqueue_step_def(
				$steps[ $current_step ],
				$workflow_id,
				$current_step,
				$queue_name,
				$priority,
				$max_attempts,
			);

			$this->logger->log(
				LogEvent::WorkflowResumed,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => '',
				)
			);

			$pdo->commit();
			$this->invalidate_workflow_cache( $workflow_id );
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * List workflows, optionally filtered by status.
	 *
	 * @param WorkflowStatus|null $status Optional status filter.
	 * @return WorkflowState[]
	 */
	public function list( ?WorkflowStatus $status = null ): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$sql    = "SELECT * FROM {$wf_tbl}";
		$params = array();

		if ( null !== $status ) {
			$sql             .= ' WHERE status = :status';
			$params['status'] = $status->value;
		}

		$sql .= ' ORDER BY id DESC';
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		$results = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$state        = json_decode( $row['state'], true ) ?: array();
			$public_state = array_filter(
				$state,
				fn( string $key ) => ! str_starts_with( $key, '_' ),
				ARRAY_FILTER_USE_KEY
			);

			$results[] = new WorkflowState(
				workflow_id: (int) $row['id'],
				name: $row['name'],
				status: WorkflowStatus::from( $row['status'] ),
				current_step: (int) $row['current_step'],
				total_steps: (int) $row['total_steps'],
				state: $public_state,
				parent_workflow_id: $row['parent_workflow_id'] ? (int) $row['parent_workflow_id'] : null,
				parent_step_index: $row['parent_step_index'] !== null ? (int) $row['parent_step_index'] : null,
			);
		}

		return $results;
	}

	/**
	 * Purge completed workflows older than N days.
	 *
	 * @param int $older_than_days Delete workflows older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function purge_completed( int $older_than_days ): int {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$wf_tbl} WHERE status = 'completed' AND completed_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}

	/**
	 * Get the full internal state of a workflow (including reserved keys).
	 * Used by the Worker to pass accumulated state to step handlers.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @return array|null Full state array, or null if not found.
	 */
	public function get_state( int $workflow_id ): ?array {
		if ( null !== $this->cache ) {
			$cache_key = "queuety:wf_state:{$workflow_id}";
			$cached    = $this->cache->get( $cache_key );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT state FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return null;
		}

		$state = json_decode( $row['state'], true ) ?: array();

		if ( null !== $this->cache ) {
			$this->cache->set( "queuety:wf_state:{$workflow_id}", $state, self::STATE_CACHE_TTL );
		}

		return $state;
	}

	/**
	 * Rewind a workflow to a previous step's state and re-run from there.
	 *
	 * Loads the state snapshot from the event log at the given step,
	 * restores internal state, sets current_step to $to_step + 1,
	 * and enqueues the next step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @param int $to_step     The step index to rewind to (must have a completed snapshot).
	 * @throws \RuntimeException If the workflow is not found, no snapshot exists, or event log is unavailable.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function rewind( int $workflow_id, int $to_step ): void {
		if ( null === $this->event_log ) {
			throw new \RuntimeException( 'Workflow event log is required for rewind.' );
		}

		$snapshot = $this->event_log->get_state_at_step( $workflow_id, $to_step );

		if ( null === $snapshot ) {
			throw new \RuntimeException(
				"No state snapshot found for workflow {$workflow_id} at step {$to_step}."
			);
		}

		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				throw new \RuntimeException( "Workflow {$workflow_id} not found." );
			}

			$full_state = json_decode( $wf_row['state'], true ) ?: array();

			$steps        = $full_state['_steps'] ?? array();
			$queue_name   = $full_state['_queue'] ?? 'default';
			$priority_val = $full_state['_priority'] ?? 0;
			$max_attempts = $full_state['_max_attempts'] ?? 3;

			// Event snapshots only contain public state, so rewind has to restore runtime metadata from the live row.
			$new_state                  = $snapshot;
			$new_state['_steps']        = $steps;
			$new_state['_queue']        = $queue_name;
			$new_state['_priority']     = $priority_val;
			$new_state['_max_attempts'] = $max_attempts;

			foreach ( $full_state as $key => $value ) {
				if ( str_starts_with( $key, '_' ) && ! isset( $new_state[ $key ] ) ) {
					$new_state[ $key ] = $value;
				}
			}

			$next_step = $to_step + 1;

			$upd = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET state = :state, current_step = :step, status = 'running',
					error_message = NULL, failed_at = NULL
				WHERE id = :id"
			);
			$upd->execute(
				array(
					'state' => json_encode( $new_state, JSON_THROW_ON_ERROR ),
					'step'  => $next_step,
					'id'    => $workflow_id,
				)
			);

			// Superseded jobs must be buried so an old worker cannot continue the abandoned path.
			$jb_tbl  = $this->conn->table( Config::table_jobs() );
			$cleanup = $pdo->prepare(
				"UPDATE {$jb_tbl}
				SET status = 'buried', error_message = 'Superseded by workflow rewind'
				WHERE workflow_id = :wf_id AND status IN ('pending', 'processing')"
			);
			$cleanup->execute( array( 'wf_id' => $workflow_id ) );

			if ( isset( $steps[ $next_step ] ) ) {
				$priority = Priority::tryFrom( $priority_val ) ?? Priority::Low;

				$this->enqueue_step_def(
					$steps[ $next_step ],
					$workflow_id,
					$next_step,
					$queue_name,
					$priority,
					$max_attempts,
				);
			}

			$this->logger->log(
				LogEvent::WorkflowRewound,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $wf_row['name'],
					'queue'       => $queue_name,
					'context'     => array( 'rewound_to_step' => $to_step ),
				)
			);

			$pdo->commit();
			$this->invalidate_workflow_cache( $workflow_id );
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Fork a running workflow into an independent copy at its current state.
	 *
	 * Creates a new workflow row with the same state and step definitions,
	 * enqueues the current step for the new workflow, and logs the fork
	 * event on both the original and the forked workflow.
	 *
	 * @param int $workflow_id The workflow ID to fork.
	 * @return int The new (forked) workflow ID.
	 * @throws \RuntimeException If the workflow is not found.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function fork( int $workflow_id ): int {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				throw new \RuntimeException( "Workflow {$workflow_id} not found." );
			}

			$state        = json_decode( $wf_row['state'], true ) ?: array();
			$current_step = (int) $wf_row['current_step'];
			$total_steps  = (int) $wf_row['total_steps'];
			$steps        = $state['_steps'] ?? array();
			$queue_name   = $state['_queue'] ?? 'default';
			$priority_val = $state['_priority'] ?? 0;
			$max_attempts = $state['_max_attempts'] ?? 3;
			$fork_name    = $wf_row['name'] . '_fork_' . time();

			$ins = $pdo->prepare(
				"INSERT INTO {$wf_tbl}
				(name, status, state, current_step, total_steps)
				VALUES (:name, 'running', :state, :step, :total)"
			);
			$ins->execute(
				array(
					'name'  => $fork_name,
					'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
					'step'  => $current_step,
					'total' => $total_steps,
				)
			);
			$forked_id = (int) $pdo->lastInsertId();

			if ( isset( $steps[ $current_step ] ) ) {
				$priority = Priority::tryFrom( $priority_val ) ?? Priority::Low;

				$this->enqueue_step_def(
					$steps[ $current_step ],
					$forked_id,
					$current_step,
					$queue_name,
					$priority,
					$max_attempts,
				);
			}

			$this->logger->log(
				LogEvent::WorkflowForked,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $wf_row['name'],
					'queue'       => $queue_name,
					'context'     => array( 'forked_workflow_id' => $forked_id ),
				)
			);

			$this->logger->log(
				LogEvent::WorkflowForked,
				array(
					'workflow_id' => $forked_id,
					'handler'     => $fork_name,
					'queue'       => $queue_name,
					'context'     => array( 'forked_from_workflow_id' => $workflow_id ),
				)
			);

			$pdo->commit();
			return $forked_id;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Check for workflows that have exceeded their deadline.
	 *
	 * Finds running workflows where deadline_at has passed, calls the
	 * deadline handler if one is defined, marks them as failed, and
	 * logs the WorkflowDeadlineExceeded event.
	 *
	 * @return int Number of workflows that exceeded their deadline.
	 * @throws \Throwable If a deadline workflow transaction fails.
	 */
	public function check_deadlines(): int {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$count  = 0;

		$stmt = $pdo->prepare(
			"SELECT * FROM {$wf_tbl}
			WHERE status = 'running'
				AND deadline_at IS NOT NULL
				AND deadline_at <= NOW()"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll();

		foreach ( $rows as $wf_row ) {
			$state         = json_decode( $wf_row['state'], true ) ?: array();
			$handler_class = $state['_on_deadline'] ?? null;

			if ( null !== $handler_class && class_exists( $handler_class ) ) {
				$public_state = array_filter(
					$state,
					fn( string $key ) => ! str_starts_with( $key, '_' ),
					ARRAY_FILTER_USE_KEY
				);

				try {
					$handler_instance = new $handler_class();
					$handler_instance->handle( $public_state );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Deadline handler failure should not prevent marking as failed.
					unset( $e );
				}
			}

			$workflow_id = (int) $wf_row['id'];
			$pdo->beginTransaction();
			try {
				$lock_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
				$lock_stmt->execute( array( 'id' => $workflow_id ) );
				$locked_row = $lock_stmt->fetch();

				if ( ! $locked_row || WorkflowStatus::Running->value !== $locked_row['status'] ) {
					$pdo->rollBack();
					continue;
				}

				$state = $this->mark_workflow_failed_locked( $pdo, $locked_row, $workflow_id, 0, 'Deadline exceeded' );

				$this->logger->log(
					LogEvent::WorkflowDeadlineExceeded,
					array(
						'workflow_id' => $workflow_id,
						'handler'     => $locked_row['name'],
						'queue'       => $state['_queue'] ?? 'default',
					)
				);

				$pdo->commit();
			} catch ( \Throwable $e ) {
				if ( $pdo->inTransaction() ) {
					$pdo->rollBack();
				}
				throw $e;
			}

			if ( ! empty( $state['_compensate_on_failure'] ) ) {
				$state = $this->run_compensations( $workflow_id, $state, 'deadline' );
				$this->persist_internal_state( $workflow_id, $state );
			}

			$this->invalidate_workflow_cache( $workflow_id );
			++$count;
		}

		return $count;
	}

	/**
	 * Bury any active jobs that belong to a terminal workflow.
	 *
	 * @param int      $workflow_id Workflow ID.
	 * @param string   $message     Error message to store on buried jobs.
	 * @param int|null $except_job  Optional job ID to leave untouched.
	 */
	private function bury_active_jobs_for_workflow( int $workflow_id, string $message, ?int $except_job = null ): void {
		$jb_tbl = $this->conn->table( Config::table_jobs() );

		$sql = "UPDATE {$jb_tbl}
			SET status = :status, failed_at = NOW(), error_message = :message
			WHERE workflow_id = :workflow_id
				AND status IN (:pending, :processing)";

		$params = array(
			'status'      => JobStatus::Buried->value,
			'message'     => $message,
			'workflow_id' => $workflow_id,
			'pending'     => JobStatus::Pending->value,
			'processing'  => JobStatus::Processing->value,
		);

		if ( null !== $except_job ) {
			$sql                 .= ' AND id != :except_job';
			$params['except_job'] = $except_job;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
	}

	/**
	 * Bury active jobs for a specific workflow step.
	 *
	 * @param int      $workflow_id Workflow ID.
	 * @param int      $step_index  Step index.
	 * @param string   $message     Error message.
	 * @param int|null $except_job  Optional job ID to leave untouched.
	 */
	private function bury_active_jobs_for_step( int $workflow_id, int $step_index, string $message, ?int $except_job = null ): void {
		$jb_tbl = $this->conn->table( Config::table_jobs() );

		$sql = "UPDATE {$jb_tbl}
			SET status = :status, failed_at = NOW(), error_message = :message
			WHERE workflow_id = :workflow_id
				AND step_index = :step_index
				AND status IN (:pending, :processing)";

		$params = array(
			'status'      => JobStatus::Buried->value,
			'message'     => $message,
			'workflow_id' => $workflow_id,
			'step_index'  => $step_index,
			'pending'     => JobStatus::Pending->value,
			'processing'  => JobStatus::Processing->value,
		);

		if ( null !== $except_job ) {
			$sql                 .= ' AND id != :except_job';
			$params['except_job'] = $except_job;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
	}

	/**
	 * Mark a locked workflow row as failed and bury active jobs.
	 *
	 * @param \PDO       $pdo          Active PDO connection.
	 * @param array      $wf_row       Locked workflow row.
	 * @param int        $workflow_id  Workflow ID.
	 * @param int        $failed_job_id Failed job ID.
	 * @param string     $error_message Error description.
	 * @param array|null $state_override Optional in-memory state to persist with the failure.
	 * @return array Decoded workflow state.
	 */
	private function mark_workflow_failed_locked( \PDO $pdo, array $wf_row, int $workflow_id, int $failed_job_id, string $error_message, ?array $state_override = null ): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$state  = $state_override ?? ( json_decode( $wf_row['state'], true ) ?: array() );

		$stmt = $pdo->prepare(
			"UPDATE {$wf_tbl}
			SET status = 'failed', failed_at = NOW(), error_message = :error, state = :state
			WHERE id = :id
				AND status IN (:running, :paused)"
		);
		$stmt->execute(
			array(
				'error'   => $error_message,
				'id'      => $workflow_id,
				'running' => WorkflowStatus::Running->value,
				'paused'  => WorkflowStatus::Paused->value,
				'state'   => json_encode( $state, JSON_THROW_ON_ERROR ),
			)
		);

		if ( 0 === $stmt->rowCount() ) {
			return $state;
		}

		$this->logger->log(
			LogEvent::WorkflowFailed,
			array(
				'workflow_id'   => $workflow_id,
				'job_id'        => $failed_job_id,
				'handler'       => '',
				'error_message' => $error_message,
			)
		);

		$this->bury_active_jobs_for_workflow( $workflow_id, $error_message, $failed_job_id );

		return $state;
	}

	/**
	 * Invalidate cached workflow state and status after a mutation.
	 *
	 * @param int $workflow_id The workflow ID whose cache entries should be cleared.
	 */
	private function invalidate_workflow_cache( int $workflow_id ): void {
		if ( null === $this->cache ) {
			return;
		}

		$this->cache->delete( "queuety:wf_state:{$workflow_id}" );
		$this->cache->delete( "queuety:wf_status:{$workflow_id}" );
	}
}
