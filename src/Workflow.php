<?php
/**
 * Workflow orchestration engine.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Compensation;
use Queuety\Contracts\Cache;
use Queuety\Contracts\ForEachReducer;
use Queuety\Contracts\RepeatCondition;
use Queuety\Enums\JobStatus;
use Queuety\Enums\ForEachMode;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WaitMode;
use Queuety\Enums\WorkflowStatus;
use Queuety\Exceptions\WorkflowConstraintViolationException;

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
	 * @param ArtifactStore|null    $artifacts Optional workflow artifact store.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
		private readonly ?Cache $cache = null,
		private readonly ?WorkflowEventLog $event_log = null,
		private readonly ?ArtifactStore $artifacts = null,
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
	 * @return string Step type: 'single', 'parallel', 'for_each', 'run_workflow', 'start_workflows', 'delay', 'wait_for_signal', 'wait_for_workflows', or 'repeat'.
	 */
	private function resolve_step_type( array|string $step_def ): string {
		if ( is_string( $step_def ) ) {
			return 'single';
		}
		return $step_def['type'] ?? 'single';
	}

	/**
	 * Resolve the workflow-event handler label for a step definition.
	 *
	 * Wait and orchestration placeholders are logged under their internal
	 * placeholder handlers so the timeline shows how the engine moved the run.
	 *
	 * @param array|string|null $step_def Step definition.
	 * @return string
	 */
	private function event_handler_for_step( array|string|null $step_def ): string {
		if ( null === $step_def ) {
			return '';
		}

		return match ( $this->resolve_step_type( $step_def ) ) {
			'wait_for_signal' => '__queuety_wait_for_signal',
			'wait_for_workflows' => '__queuety_wait_for_workflows',
			'for_each' => '__queuety_for_each',
			'run_workflow' => '__queuety_run_workflow',
			'start_workflows' => '__queuety_start_workflows',
			'repeat' => '__queuety_repeat',
			'delay' => '__queuety_delay',
			default => $this->resolve_step_handler( $step_def ),
		};
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
	 * Resolve public wait context from persisted workflow state.
	 *
	 * @param array $state Full workflow state.
	 * @return array<string,mixed>|null
	 */
	private function wait_context_from_state( array $state ): ?array {
		$context = $state['_wait'] ?? null;
		if ( is_array( $context ) && isset( $context['type'] ) ) {
			return $context;
		}

		$waiting_for_signal = $state['_waiting_for_signal'] ?? null;
		if ( is_string( $waiting_for_signal ) && '' !== $waiting_for_signal ) {
			return array(
				'type'         => 'wait_for_signal',
				'signal_names' => array( $waiting_for_signal ),
				'wait_mode'    => WaitMode::All->value,
			);
		}

		return null;
	}

	/**
	 * Resolve the configured workflow definition version from persisted state.
	 *
	 * @param array $state Workflow state.
	 * @return string|null
	 */
	private function definition_version_from_state( array $state ): ?string {
		$version = $state['_definition_version'] ?? null;
		if ( ! is_string( $version ) ) {
			return null;
		}

		$version = trim( $version );
		return '' === $version ? null : $version;
	}

	/**
	 * Resolve the deterministic workflow definition hash from persisted state.
	 *
	 * @param array $state Workflow state.
	 * @return string|null
	 */
	private function definition_hash_from_state( array $state ): ?string {
		$hash = $state['_definition_hash'] ?? null;
		if ( ! is_string( $hash ) ) {
			return null;
		}

		$hash = trim( $hash );
		return '' === $hash ? null : $hash;
	}

	/**
	 * Resolve the configured workflow idempotency key from persisted state.
	 *
	 * @param array $state Workflow state.
	 * @return string|null
	 */
	private function idempotency_key_from_state( array $state ): ?string {
		$key = $state['_idempotency_key'] ?? null;
		if ( ! is_string( $key ) ) {
			return null;
		}

		$key = trim( $key );
		return '' === $key ? null : $key;
	}

	/**
	 * Normalize the per-dispatch idempotency key for definition-based workflows.
	 *
	 * @param array $dispatch_options Dispatch options.
	 * @return string|null
	 * @throws \InvalidArgumentException If the dispatch key is not a non-empty string.
	 */
	private function normalize_workflow_dispatch_idempotency_key( array $dispatch_options ): ?string {
		$key = $dispatch_options['idempotency_key'] ?? null;
		if ( null === $key ) {
			return null;
		}

		if ( ! is_string( $key ) ) {
			throw new \InvalidArgumentException( 'Workflow idempotency_key must be a string.' );
		}

		$key = trim( $key );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Workflow idempotency_key cannot be empty.' );
		}

		return $key;
	}

	/**
	 * Find an existing workflow ID for a durable idempotency key.
	 *
	 * @param string    $key Workflow idempotency key.
	 * @param \PDO|null $pdo Optional PDO handle to reuse.
	 * @return int|null
	 */
	private function find_existing_workflow_id_for_key( string $key, ?\PDO $pdo = null ): ?int {
		$pdo     = $pdo ?? $this->conn->pdo();
		$key_tbl = $this->conn->table( Config::table_workflow_dispatch_keys() );
		$stmt    = $pdo->prepare(
			"SELECT workflow_id FROM {$key_tbl} WHERE dispatch_key = :dispatch_key LIMIT 1"
		);
		$stmt->execute( array( 'dispatch_key' => $key ) );
		$workflow_id = $stmt->fetchColumn();

		return false === $workflow_id ? null : (int) $workflow_id;
	}

	/**
	 * Whether the given database error represents a duplicate key violation.
	 *
	 * @param \PDOException $e Database exception.
	 * @return bool
	 */
	private function is_duplicate_key_error( \PDOException $e ): bool {
		$sql_state  = (string) $e->getCode();
		$error_info = $e->errorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO exposes this property with a fixed name.
		$driver     = $error_info[1] ?? null;

		return '23000' === $sql_state || 1062 === $driver;
	}

	/**
	 * Compute the size of the public workflow state.
	 *
	 * @param array $state Workflow state.
	 * @return int
	 */
	private function public_state_size_bytes( array $state ): int {
		return strlen( json_encode( $this->public_state( $state ), JSON_THROW_ON_ERROR ) );
	}

	/**
	 * Resolve the configured workflow budget from persisted state.
	 *
	 * @param array $state Workflow state.
	 * @return array<string,int>
	 */
	private function workflow_budget_limits( array $state ): array {
		$limits = $state['_workflow_budget'] ?? null;
		if ( ! is_array( $limits ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array( 'max_transitions', 'max_for_each_items', 'max_state_bytes', 'max_cost_units', 'max_started_workflows' ) as $key ) {
			if ( isset( $limits[ $key ] ) && (int) $limits[ $key ] > 0 ) {
				$normalized[ $key ] = (int) $limits[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Build public budget metadata for workflow inspection.
	 *
	 * @param array $state Workflow state.
	 * @return array<string,int>|null
	 */
	private function budget_summary_from_state( array $state ): ?array {
		$limits = $this->workflow_budget_limits( $state );
		if ( empty( $limits ) ) {
			return null;
		}

		$counters                      = $state['_workflow_counters'] ?? array();
		$summary                       = $limits;
		$summary['transitions']        = (int) ( $counters['transitions'] ?? 0 );
		$summary['cost_units']         = (int) ( $counters['cost_units'] ?? 0 );
		$summary['started_workflows']  = (int) ( $counters['started_workflows'] ?? 0 );
		$summary['public_state_bytes'] = $this->public_state_size_bytes( $state );

		return $summary;
	}

	/**
	 * Increment the completed transition counter when budgets are enabled.
	 *
	 * @param array $state Workflow state.
	 */
	private function increment_transition_counter( array &$state ): void {
		if ( empty( $this->workflow_budget_limits( $state ) ) ) {
			return;
		}

		$state['_workflow_counters']              ??= array();
		$state['_workflow_counters']['transitions'] = (int) ( $state['_workflow_counters']['transitions'] ?? 0 ) + 1;
	}

	/**
	 * Increment consumed workflow cost units.
	 *
	 * @param array $state Workflow state.
	 * @param int   $cost_units Cost units to add.
	 */
	private function increment_cost_units( array &$state, int $cost_units ): void {
		if ( $cost_units < 1 || empty( $this->workflow_budget_limits( $state ) ) ) {
			return;
		}

		$state['_workflow_counters']             ??= array();
		$state['_workflow_counters']['cost_units'] = (int) ( $state['_workflow_counters']['cost_units'] ?? 0 ) + $cost_units;
	}

	/**
	 * Increment the started workflow counter when budgets are enabled.
	 *
	 * @param array $state Workflow state.
	 * @param int   $count Started workflow count to add.
	 */
	private function increment_started_workflow_counter( array &$state, int $count ): void {
		if ( $count < 1 || empty( $this->workflow_budget_limits( $state ) ) ) {
			return;
		}

		$state['_workflow_counters']                    ??= array();
		$state['_workflow_counters']['started_workflows'] = (int) ( $state['_workflow_counters']['started_workflows'] ?? 0 ) + $count;
	}

	/**
	 * Extract internal workflow budget deltas emitted by orchestration steps.
	 *
	 * @param array $step_output Step output.
	 * @return array{started_workflows: int}
	 */
	private function workflow_budget_delta_from_step_output( array $step_output ): array {
		$delta = $step_output['_workflow_budget_delta'] ?? null;
		if ( ! is_array( $delta ) ) {
			return array(
				'started_workflows' => 0,
			);
		}

		return array(
			'started_workflows' => max( 0, (int) ( $delta['started_workflows'] ?? 0 ) ),
		);
	}

	/**
	 * Throw when the workflow has exceeded a configured guardrail.
	 *
	 * @param array $state Workflow state.
	 * @throws WorkflowConstraintViolationException If a configured guardrail is exceeded.
	 */
	private function assert_workflow_budget( array $state ): void {
		$limits = $this->workflow_budget_limits( $state );
		if ( empty( $limits ) ) {
			return;
		}

		$transitions = (int) ( $state['_workflow_counters']['transitions'] ?? 0 );
		if ( isset( $limits['max_transitions'] ) && $transitions > $limits['max_transitions'] ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					'Workflow exceeded max_transitions budget of %d.',
					$limits['max_transitions']
				)
			);
		}

		$state_size = $this->public_state_size_bytes( $state );
		if ( isset( $limits['max_state_bytes'] ) && $state_size > $limits['max_state_bytes'] ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					'Workflow exceeded max_state_bytes budget of %d.',
					$limits['max_state_bytes']
				)
			);
		}

		$cost_units = (int) ( $state['_workflow_counters']['cost_units'] ?? 0 );
		if ( isset( $limits['max_cost_units'] ) && $cost_units > $limits['max_cost_units'] ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					'Workflow exceeded max_cost_units budget of %d.',
					$limits['max_cost_units']
				)
			);
		}

		$started_workflows = (int) ( $state['_workflow_counters']['started_workflows'] ?? 0 );
		if ( isset( $limits['max_started_workflows'] ) && $started_workflows > $limits['max_started_workflows'] ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					'Workflow exceeded max_started_workflows budget of %d.',
					$limits['max_started_workflows']
				)
			);
		}
	}

	/**
	 * Throw when a workflow is about to enter a for-each step that exceeds its cap.
	 *
	 * @param array        $state    Workflow state.
	 * @param array|string $step_def Next step definition.
	 * @throws WorkflowConstraintViolationException If the next for-each step exceeds its configured cap.
	 */
	private function assert_for_each_budget_for_step( array $state, array|string $step_def ): void {
		if ( ! is_array( $step_def ) || 'for_each' !== $this->resolve_step_type( $step_def ) ) {
			return;
		}

		$max_for_each_items = $this->workflow_budget_limits( $state )['max_for_each_items'] ?? null;
		if ( null === $max_for_each_items ) {
			return;
		}

		$items_key = $step_def['items_key'] ?? null;
		if ( ! is_string( $items_key ) || '' === $items_key ) {
			return;
		}

		$items = $state[ $items_key ] ?? null;
		if ( ! is_array( $items ) ) {
			return;
		}

		if ( count( $items ) > $max_for_each_items ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					"For-each step '%s' planned %d items, exceeding max_for_each_items budget of %d.",
					$step_def['name'] ?? $items_key,
					count( $items ),
					$max_for_each_items
				)
			);
		}
	}

	/**
	 * Store runtime wait metadata in workflow state.
	 *
	 * @param array       $state       Workflow state.
	 * @param string      $type        Wait type.
	 * @param int         $step_index  Step index.
	 * @param array       $waiting_for Wait targets.
	 * @param WaitMode    $mode        Wait mode.
	 * @param string|null $result_key  Optional result key.
	 * @param array       $details     Additional persisted wait metadata.
	 */
	private function set_wait_context(
		array &$state,
		string $type,
		int $step_index,
		array $waiting_for,
		WaitMode $mode,
		?string $result_key = null,
		array $details = array(),
	): void {
		$state['_wait'] = array_merge(
			array(
				'type'        => $type,
				'step_index'  => $step_index,
				'wait_mode'   => $mode->value,
				'result_key'  => $result_key,
				'waiting_for' => array_values( $waiting_for ),
			),
			array_filter(
				$details,
				static fn( mixed $value ): bool => null !== $value
			)
		);

		if ( 'wait_for_signal' === $type && WaitMode::All === $mode && 1 === count( $waiting_for ) ) {
			$state['_waiting_for_signal'] = $waiting_for[0];
			return;
		}

		unset( $state['_waiting_for_signal'] );
	}

	/**
	 * Remove runtime wait metadata from workflow state.
	 *
	 * @param array $state Workflow state.
	 */
	private function clear_wait_context( array &$state ): void {
		unset( $state['_wait'], $state['_waiting_for_signal'] );
	}

	/**
	 * Resolve the current step name from persisted workflow state.
	 *
	 * @param array $state        Workflow state.
	 * @param int   $current_step Current step index.
	 * @return string|null
	 */
	private function current_step_name_from_state( array $state, int $current_step ): ?string {
		$steps    = $state['_steps'] ?? array();
		$step_def = $steps[ $current_step ] ?? null;

		if ( is_array( $step_def ) && isset( $step_def['name'] ) && is_string( $step_def['name'] ) ) {
			return $step_def['name'];
		}

		return null;
	}

	/**
	 * Resolve signal names from a signal step definition.
	 *
	 * @param array $step_def Step definition.
	 * @return string[]
	 */
	private function signal_names_for_step( array $step_def ): array {
		$signal_names = $step_def['signal_names'] ?? null;
		if ( is_array( $signal_names ) ) {
			return array_values(
				array_filter(
					array_map(
						static fn( mixed $signal_name ): string => trim( (string) $signal_name ),
						$signal_names
					),
					static fn( string $signal_name ): bool => '' !== $signal_name
				)
			);
		}

		$signal_name = trim( (string) ( $step_def['signal_name'] ?? '' ) );
		return '' === $signal_name ? array() : array( $signal_name );
	}

	/**
	 * Resolve the wait mode for a signal or workflow wait step.
	 *
	 * @param array $step_def Step definition.
	 * @return WaitMode
	 */
	private function wait_mode_for_step( array $step_def ): WaitMode {
		return WaitMode::tryFrom( (string) ( $step_def['wait_mode'] ?? WaitMode::All->value ) ) ?? WaitMode::All;
	}

	/**
	 * Resolve the required quorum for a workflow wait step.
	 *
	 * @param array $step_def Step definition.
	 * @return int|null
	 */
	private function wait_for_workflows_quorum_for_step( array $step_def ): ?int {
		$quorum = isset( $step_def['quorum'] ) ? (int) $step_def['quorum'] : null;
		return null !== $quorum && $quorum > 0 ? $quorum : null;
	}

	/**
	 * Resolve an internal started-workflow group key for a workflow wait step.
	 *
	 * @param array $step_def Step definition.
	 * @return string|null
	 */
	private function wait_for_workflows_group_key_for_step( array $step_def ): ?string {
		$group_key = $step_def['workflow_group_key'] ?? null;
		if ( ! is_string( $group_key ) ) {
			return null;
		}

		$group_key = trim( $group_key );
		return '' === $group_key ? null : $group_key;
	}

	/**
	 * Resolve the public result key for a wait step.
	 *
	 * @param array $step_def Step definition.
	 * @return string|null
	 */
	private function wait_result_key_for_step( array $step_def ): ?string {
		$result_key = $step_def['result_key'] ?? null;
		if ( ! is_string( $result_key ) ) {
			return null;
		}

		$result_key = trim( $result_key );
		return '' === $result_key ? null : $result_key;
	}

	/**
	 * Normalize one workflow ID source into a de-duplicated list of IDs.
	 *
	 * @param mixed $value Workflow ID or list source.
	 * @return int[]
	 */
	private function normalize_workflow_ids( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( mixed $workflow_id ): int => (int) $workflow_id,
							$value
						),
						static fn( int $workflow_id ): bool => $workflow_id > 0
					)
				)
			);
		}

		$workflow_id = (int) $value;
		return $workflow_id > 0 ? array( $workflow_id ) : array();
	}

	/**
	 * Resolve the configured match payload subset for a signal step.
	 *
	 * @param array $step_def Step definition.
	 * @return array
	 */
	private function signal_match_payload_for_step( array $step_def ): array {
		$match_payload = $step_def['match_payload'] ?? null;
		return is_array( $match_payload ) ? $match_payload : array();
	}

	/**
	 * Resolve the configured correlation key for a signal step.
	 *
	 * @param array $step_def Step definition.
	 * @return string|null
	 */
	private function signal_correlation_key_for_step( array $step_def ): ?string {
		$correlation_key = $step_def['correlation_key'] ?? null;
		if ( ! is_string( $correlation_key ) ) {
			return null;
		}

		$correlation_key = trim( $correlation_key );
		return '' === $correlation_key ? null : $correlation_key;
	}

	/**
	 * Canonicalize nested values for deterministic equality checks.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function canonicalize_comparable_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( fn( mixed $item ): mixed => $this->canonicalize_comparable_value( $item ), $value );
		}

		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize_comparable_value( $item );
		}

		return $value;
	}

	/**
	 * Compare two values using a stable canonical representation.
	 *
	 * @param mixed $left  Left-hand value.
	 * @param mixed $right Right-hand value.
	 * @return bool
	 * @throws \JsonException If the comparable values cannot be encoded.
	 */
	private function values_equal( mixed $left, mixed $right ): bool {
		return json_encode( $this->canonicalize_comparable_value( $left ), JSON_THROW_ON_ERROR )
			=== json_encode( $this->canonicalize_comparable_value( $right ), JSON_THROW_ON_ERROR );
	}

	/**
	 * Resolve the persisted iteration count for a repeat step.
	 *
	 * @param array $state      Workflow state.
	 * @param int   $step_index Step index.
	 * @return int
	 */
	private function repeat_iteration_count( array $state, int $step_index ): int {
		$repeat_steps = $state['_repeat_steps'] ?? array();
		if ( ! is_array( $repeat_steps ) ) {
			return 0;
		}

		$entry = $repeat_steps[ (string) $step_index ] ?? $repeat_steps[ $step_index ] ?? null;
		if ( ! is_array( $entry ) ) {
			return 0;
		}

		return max( 0, (int) ( $entry['iterations'] ?? 0 ) );
	}

	/**
	 * Build updated internal repeat runtime state for a step.
	 *
	 * @param array $state       Workflow state.
	 * @param int   $step_index  Step index.
	 * @param int   $iterations  Persisted iteration count.
	 * @return array
	 */
	private function repeat_steps_state( array $state, int $step_index, int $iterations ): array {
		$repeat_steps = $state['_repeat_steps'] ?? array();
		if ( ! is_array( $repeat_steps ) ) {
			$repeat_steps = array();
		}

		$repeat_steps[ (string) $step_index ] = array(
			'iterations' => max( 0, $iterations ),
		);

		return $repeat_steps;
	}

	/**
	 * Decide whether a repeat step should jump back to its target.
	 *
	 * @param array $step_def Step definition.
	 * @param array $state    Current workflow state.
	 * @return bool
	 * @throws \JsonException|\RuntimeException If the comparable values cannot be encoded or the repeat mode is invalid.
	 */
	private function repeat_should_continue( array $step_def, array $state ): bool {
		$mode            = (string) ( $step_def['repeat_mode'] ?? 'while' );
		$condition_class = trim( (string) ( $step_def['condition_class'] ?? '' ) );

		if ( '' !== $condition_class ) {
			if ( ! class_exists( $condition_class ) ) {
				throw new \RuntimeException( "Repeat condition class '{$condition_class}' not found." );
			}

			$condition = new $condition_class();
			if ( ! $condition instanceof RepeatCondition ) {
				throw new \RuntimeException( "Repeat condition '{$condition_class}' must implement Queuety\\Contracts\\RepeatCondition." );
			}

			$matches = $condition->matches( $this->public_state( $state ) );
		} else {
			$state_key = trim( (string) ( $step_def['state_key'] ?? '' ) );
			if ( '' === $state_key ) {
				throw new \RuntimeException( 'Repeat step is missing both a state key and a condition class.' );
			}

			$expected = $step_def['expected'] ?? true;
			$actual   = $state[ $state_key ] ?? null;
			$matches  = $this->values_equal( $actual, $expected );
		}

		return match ( $mode ) {
			'while' => $matches,
			'until' => ! $matches,
			default => throw new \RuntimeException( "Unsupported repeat mode '{$mode}'." ),
		};
	}

	/**
	 * Check whether a payload contains the configured nested match subset.
	 *
	 * @param array $payload Full payload.
	 * @param array $subset  Required subset.
	 * @return bool
	 */
	private function payload_contains_subset( array $payload, array $subset ): bool {
		foreach ( $subset as $key => $expected ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				return false;
			}

			$actual = $payload[ $key ];
			if ( is_array( $expected ) ) {
				if ( ! is_array( $actual ) || ! $this->payload_contains_subset( $actual, $expected ) ) {
					return false;
				}
				continue;
			}

			if ( ! $this->values_equal( $actual, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decide whether a signal payload satisfies the step's matching rules.
	 *
	 * @param array $payload Signal payload.
	 * @param array $state   Current workflow state.
	 * @param array $step_def Signal step definition.
	 * @return bool
	 */
	private function signal_payload_matches( array $payload, array $state, array $step_def ): bool {
		$match_payload = $this->signal_match_payload_for_step( $step_def );
		if ( ! empty( $match_payload ) && ! $this->payload_contains_subset( $payload, $match_payload ) ) {
			return false;
		}

		$correlation_key = $this->signal_correlation_key_for_step( $step_def );
		if ( null === $correlation_key ) {
			return true;
		}

		return array_key_exists( $correlation_key, $payload )
			&& array_key_exists( $correlation_key, $state )
			&& $this->values_equal( $payload[ $correlation_key ], $state[ $correlation_key ] );
	}

	/**
	 * Build the public step output for a signal wait that has been satisfied.
	 *
	 * @param array $step_def         Step definition.
	 * @param array $matched_payloads Matched signal payloads keyed by signal name.
	 * @return array
	 */
	private function signal_step_output( array $step_def, array $matched_payloads ): array {
		$result_key   = $this->wait_result_key_for_step( $step_def );
		$signal_names = $this->signal_names_for_step( $step_def );
		$decision_map = is_array( $step_def['decision_map'] ?? null ) ? $step_def['decision_map'] : array();

		$matched_payloads = array_intersect_key( $matched_payloads, array_flip( $signal_names ) );

		if ( ! empty( $decision_map ) ) {
			$selected_signal  = array_key_first( $matched_payloads );
			$selected_payload = null !== $selected_signal ? ( $matched_payloads[ $selected_signal ] ?? array() ) : array();
			$outcome          = null !== $selected_signal ? ( $decision_map[ $selected_signal ] ?? $selected_signal ) : null;

			$decision = array(
				'outcome' => $outcome,
				'signal'  => $selected_signal,
				'data'    => $selected_payload,
			);

			if ( null !== $result_key ) {
				return array( $result_key => $decision );
			}

			return array_merge(
				array_filter(
					array(
						'decision_outcome' => $outcome,
						'decision_signal'  => $selected_signal,
					),
					static fn( mixed $value ): bool => null !== $value
				),
				array_filter(
					$selected_payload,
					static fn( string $key ): bool => ! str_starts_with( $key, '_' ),
					ARRAY_FILTER_USE_KEY
				)
			);
		}

		if ( null !== $result_key ) {
			if ( 1 === count( $signal_names ) ) {
				return array( $result_key => $matched_payloads[ $signal_names[0] ] ?? array() );
			}

			$collected = array();
			foreach ( $signal_names as $signal_name ) {
				if ( array_key_exists( $signal_name, $matched_payloads ) ) {
					$collected[ $signal_name ] = $matched_payloads[ $signal_name ];
				}
			}
			return array( $result_key => $collected );
		}

		$output = array();
		foreach ( $signal_names as $signal_name ) {
			foreach ( $matched_payloads[ $signal_name ] ?? array() as $key => $value ) {
				if ( ! str_starts_with( $key, '_' ) ) {
					$output[ $key ] = $value;
				}
			}
		}

		return $output;
	}

	/**
	 * Collect the first matching payload for each configured signal name.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $step_def    Signal step definition.
	 * @param array $state       Current workflow state.
	 * @return array{matched_payloads: array<string,array>, first_signal_name: ?string, first_signal_data: ?array}
	 */
	private function resolve_signal_wait_progress( int $workflow_id, array $step_def, array $state ): array {
		$signal_names = $this->signal_names_for_step( $step_def );
		if ( empty( $signal_names ) ) {
			return array(
				'matched_payloads'  => array(),
				'first_signal_name' => null,
				'first_signal_data' => null,
			);
		}

		$sig_tbl      = $this->conn->table( Config::table_signals() );
		$placeholders = array();
		$params       = array( 'workflow_id' => $workflow_id );

		foreach ( $signal_names as $index => $signal_name ) {
			$key            = 'signal_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $signal_name;
		}

		$stmt = $this->conn->pdo()->prepare(
			"SELECT signal_name, payload
			FROM {$sig_tbl}
			WHERE workflow_id = :workflow_id
				AND signal_name IN (" . implode( ', ', $placeholders ) . ')
			ORDER BY id ASC'
		);
		$stmt->execute( $params );

		$matched_payloads  = array();
		$first_signal_name = null;
		$first_signal_data = null;

		foreach ( $stmt->fetchAll() as $row ) {
			$signal_name = (string) $row['signal_name'];
			$payload     = $row['payload'] ? ( json_decode( $row['payload'], true ) ?: array() ) : array();

			if ( ! $this->signal_payload_matches( $payload, $state, $step_def ) ) {
				continue;
			}

			if ( null === $first_signal_name ) {
				$first_signal_name = $signal_name;
				$first_signal_data = $payload;
			}

			if ( ! array_key_exists( $signal_name, $matched_payloads ) ) {
				$matched_payloads[ $signal_name ] = $payload;
			}
		}

		return array(
			'matched_payloads'  => $matched_payloads,
			'first_signal_name' => $first_signal_name,
			'first_signal_data' => $first_signal_data,
		);
	}

	/**
	 * Fetch stored signal payloads that satisfy a signal wait definition.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $step_def    Signal step definition.
	 * @param array $state       Current workflow state.
	 * @return array<string,array>|null
	 */
	private function resolve_signal_wait_payloads( int $workflow_id, array $step_def, array $state ): ?array {
		$signal_names = $this->signal_names_for_step( $step_def );
		if ( empty( $signal_names ) ) {
			return null;
		}

		$mode           = $this->wait_mode_for_step( $step_def );
		$progress       = $this->resolve_signal_wait_progress( $workflow_id, $step_def, $state );
		$first_payloads = $progress['matched_payloads'];

		if ( WaitMode::Any === $mode ) {
			if ( null === $progress['first_signal_name'] ) {
				return null;
			}

			return array( $progress['first_signal_name'] => $progress['first_signal_data'] ?? array() );
		}

		foreach ( $signal_names as $signal_name ) {
			if ( ! array_key_exists( $signal_name, $first_payloads ) ) {
				return null;
			}
		}

		$ordered = array();
		foreach ( $signal_names as $signal_name ) {
			$ordered[ $signal_name ] = $first_payloads[ $signal_name ];
		}

		return $ordered;
	}

	/**
	 * Resolve workflow dependency IDs for a workflow wait step.
	 *
	 * @param array $step_def Step definition.
	 * @param array $state    Current workflow state.
	 * @return int[]
	 */
	private function resolve_wait_for_workflows_ids( array $step_def, array $state ): array {
		$workflow_ids = $step_def['workflow_ids'] ?? null;
		if ( is_array( $workflow_ids ) ) {
			return $this->normalize_workflow_ids( $workflow_ids );
		}

		$group_key = $this->wait_for_workflows_group_key_for_step( $step_def );
		if ( null !== $group_key ) {
			$groups = $state['_workflow_groups'] ?? array();
			if ( is_array( $groups ) && array_key_exists( $group_key, $groups ) ) {
				return $this->normalize_workflow_ids( $groups[ $group_key ] );
			}

			if ( array_key_exists( $group_key, $state ) ) {
				return $this->normalize_workflow_ids( $state[ $group_key ] );
			}

			return array();
		}

		$workflow_key = trim( (string) ( $step_def['workflow_id_key'] ?? '' ) );
		if ( '' === $workflow_key ) {
			return array();
		}

		$value = $state[ $workflow_key ] ?? null;
		if ( null === $value ) {
			return array();
		}

		return $this->normalize_workflow_ids( $value );
	}

	/**
	 * Fetch dependency workflow rows keyed by workflow ID.
	 *
	 * @param int[] $workflow_ids Workflow IDs.
	 * @return array<int,array>
	 */
	private function fetch_workflow_rows_by_id( array $workflow_ids ): array {
		if ( empty( $workflow_ids ) ) {
			return array();
		}

		$wf_tbl       = $this->conn->table( Config::table_workflows() );
		$placeholders = array();
		$params       = array();

		foreach ( array_values( $workflow_ids ) as $index => $workflow_id ) {
			$key            = 'workflow_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $workflow_id;
		}

		$stmt = $this->conn->pdo()->prepare(
			"SELECT id, status, state
			FROM {$wf_tbl}
			WHERE id IN (" . implode( ', ', $placeholders ) . ')'
		);
		$stmt->execute( $params );

		$rows = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		return $rows;
	}

	/**
	 * Evaluate whether a workflow wait is satisfied, still pending, or impossible.
	 *
	 * @param array $step_def      Step definition.
	 * @param int[] $workflow_ids  Dependency workflow IDs.
	 * @param array $workflow_rows Workflow rows keyed by ID.
	 * @return string
	 */
	private function evaluate_wait_for_workflows_state( array $step_def, array $workflow_ids, array $workflow_rows ): string {
		$mode = $this->wait_mode_for_step( $step_def );

		if ( empty( $workflow_ids ) ) {
			return WaitMode::All === $mode ? 'satisfied' : 'impossible';
		}

		$quorum            = $this->wait_for_workflows_quorum_for_step( $step_def ) ?? 1;
		$completed_count   = 0;
		$terminal_failures = 0;
		$active_count      = 0;

		foreach ( $workflow_ids as $workflow_id ) {
			$row = $workflow_rows[ $workflow_id ] ?? null;
			if ( null === $row ) {
				return 'impossible';
			}

			$status = WorkflowStatus::from( $row['status'] );
			if ( WorkflowStatus::Completed === $status ) {
				++$completed_count;
			} elseif ( in_array( $status, array( WorkflowStatus::Failed, WorkflowStatus::Cancelled ), true ) ) {
				++$terminal_failures;
			} else {
				++$active_count;
			}
		}

		if ( WaitMode::Any === $mode ) {
			if ( $completed_count >= 1 ) {
				return 'satisfied';
			}

			return 0 === $active_count ? 'impossible' : 'pending';
		}

		if ( WaitMode::Quorum === $mode ) {
			if ( $completed_count >= $quorum ) {
				return 'satisfied';
			}

			return $completed_count + $active_count < $quorum ? 'impossible' : 'pending';
		}

		if ( $completed_count === count( $workflow_ids ) ) {
			return 'satisfied';
		}

		if ( $terminal_failures > 0 ) {
			return 'impossible';
		}

		return 'pending';
	}

	/**
	 * Build public step output for a satisfied workflow wait step.
	 *
	 * @param array $step_def      Step definition.
	 * @param int[] $workflow_ids  Dependency workflow IDs.
	 * @param array $workflow_rows Workflow rows keyed by ID.
	 * @return array
	 */
	private function wait_for_workflows_step_output( array $step_def, array $workflow_ids, array $workflow_rows ): array {
		$result_key = $this->wait_result_key_for_step( $step_def );
		$mode       = $this->wait_mode_for_step( $step_def );
		$results    = array();

		foreach ( $workflow_ids as $workflow_id ) {
			$row = $workflow_rows[ $workflow_id ] ?? null;
			if ( null === $row || WorkflowStatus::Completed->value !== $row['status'] ) {
				continue;
			}

			$state                            = json_decode( $row['state'], true ) ?: array();
			$results[ (string) $workflow_id ] = $this->public_state( $state );

			if ( WaitMode::Any === $mode ) {
				break;
			}
		}

		if ( null !== $result_key ) {
			if ( 1 === count( $workflow_ids ) && 1 === count( $results ) ) {
				return array( $result_key => array_values( $results )[0] );
			}

			return array( $result_key => $results );
		}

		$output = array();
		foreach ( $workflow_ids as $workflow_id ) {
			foreach ( $results[ (string) $workflow_id ] ?? array() as $key => $value ) {
				if ( ! str_starts_with( $key, '_' ) ) {
					$output[ $key ] = $value;
				}
			}

			if ( WaitMode::Any === $mode && ! empty( $results[ (string) $workflow_id ] ?? null ) ) {
				break;
			}
		}

		return $output;
	}

	/**
	 * Build inspectable wait details for a signal step.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $step_def    Signal step definition.
	 * @param array $state       Workflow state.
	 * @return array
	 */
	private function signal_wait_details( int $workflow_id, array $step_def, array $state ): array {
		$progress          = $this->resolve_signal_wait_progress( $workflow_id, $step_def, $state );
		$signal_names      = $this->signal_names_for_step( $step_def );
		$matched_signals   = array_keys( $progress['matched_payloads'] );
		$remaining_signals = array_values( array_diff( $signal_names, $matched_signals ) );
		$details           = array(
			'step_name'     => $step_def['name'] ?? null,
			'result_key'    => $this->wait_result_key_for_step( $step_def ),
			'human_wait'    => $step_def['human_wait'] ?? null,
			'match_payload' => $this->signal_match_payload_for_step( $step_def ),
			'matched'       => $matched_signals,
			'remaining'     => $remaining_signals,
		);

		$correlation_key = $this->signal_correlation_key_for_step( $step_def );
		if ( null !== $correlation_key ) {
			$details['correlation_key']   = $correlation_key;
			$details['correlation_value'] = $state[ $correlation_key ] ?? null;
		}

		return array_filter(
			$details,
			static fn( mixed $value ): bool => null !== $value
		);
	}

	/**
	 * Build inspectable wait details for a workflow dependency step.
	 *
	 * @param array $step_def Workflow wait definition.
	 * @param array $state    Workflow state.
	 * @return array
	 */
	private function wait_for_workflows_details( array $step_def, array $state ): array {
		$workflow_ids  = $this->resolve_wait_for_workflows_ids( $step_def, $state );
		$workflow_rows = $this->fetch_workflow_rows_by_id( $workflow_ids );
		$matched       = array();
		$remaining     = array();
		$failed        = array();

		foreach ( $workflow_ids as $workflow_id ) {
			$row = $workflow_rows[ $workflow_id ] ?? null;
			if ( null === $row ) {
				$remaining[] = (string) $workflow_id;
				continue;
			}

			$status = WorkflowStatus::from( $row['status'] );
			if ( WorkflowStatus::Completed === $status ) {
				$matched[] = (string) $workflow_id;
			} elseif ( in_array( $status, array( WorkflowStatus::Failed, WorkflowStatus::Cancelled ), true ) ) {
				$failed[] = (string) $workflow_id;
			} else {
				$remaining[] = (string) $workflow_id;
			}
		}

		return array_filter(
			array(
				'step_name'  => $step_def['name'] ?? null,
				'group_key'  => $this->wait_for_workflows_group_key_for_step( $step_def ),
				'result_key' => $this->wait_result_key_for_step( $step_def ),
				'quorum'     => $this->wait_for_workflows_quorum_for_step( $step_def ),
				'matched'    => $matched,
				'remaining'  => $remaining,
				'failed'     => $failed,
			),
			static fn( mixed $value ): bool => null !== $value
		);
	}

	/**
	 * Build the public wait-inspection payload for a workflow state.
	 *
	 * @param int                      $workflow_id  Workflow ID.
	 * @param array                    $state        Workflow state.
	 * @param int                      $current_step Current step index.
	 * @param array<string,mixed>|null $wait Wait context.
	 * @return array|null
	 */
	private function wait_details_from_state( int $workflow_id, array $state, int $current_step, ?array $wait ): ?array {
		if ( null === $wait ) {
			return null;
		}

		$step_def = $state['_steps'][ $current_step ] ?? null;
		if ( ! is_array( $step_def ) ) {
			return array_filter(
				array(
					'step_name'  => $wait['step_name'] ?? null,
					'result_key' => $wait['result_key'] ?? null,
				),
				static fn( mixed $value ): bool => null !== $value
			);
		}

		$details = match ( $wait['type'] ?? null ) {
			'signal' => $this->signal_wait_details( $workflow_id, $step_def, $state ),
			'workflow' => $this->wait_for_workflows_details( $step_def, $state ),
			default => array(),
		};

		$details['wait_mode'] = $wait['wait_mode'] ?? null;

		return array_filter(
			$details,
			static fn( mixed $value ): bool => null !== $value
		);
	}

	/**
	 * Delete workflow dependency rows for a specific wait step.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @param int $step_index  Step index.
	 */
	private function clear_workflow_dependencies( int $workflow_id, int $step_index ): void {
		$dep_tbl = $this->conn->table( Config::table_workflow_dependencies() );
		$stmt    = $this->conn->pdo()->prepare(
			"DELETE FROM {$dep_tbl}
			WHERE waiting_workflow_id = :workflow_id
				AND step_index = :step_index"
		);
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
			)
		);
	}

	/**
	 * Register workflow dependency rows for a pending wait step.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Step index.
	 * @param int[] $workflow_ids Dependency workflow IDs.
	 */
	private function store_workflow_dependencies( int $workflow_id, int $step_index, array $workflow_ids ): void {
		$this->clear_workflow_dependencies( $workflow_id, $step_index );

		if ( empty( $workflow_ids ) ) {
			return;
		}

		$dep_tbl = $this->conn->table( Config::table_workflow_dependencies() );
		$stmt    = $this->conn->pdo()->prepare(
			"INSERT INTO {$dep_tbl}
			(waiting_workflow_id, step_index, dependency_workflow_id)
			VALUES (:waiting_workflow_id, :step_index, :dependency_workflow_id)"
		);

		foreach ( $workflow_ids as $dependency_workflow_id ) {
			$stmt->execute(
				array(
					'waiting_workflow_id'    => $workflow_id,
					'step_index'             => $step_index,
					'dependency_workflow_id' => $dependency_workflow_id,
				)
			);
		}
	}

	/**
	 * Advance a satisfied wait step without requiring a completing job row.
	 *
	 * @param \PDO  $pdo         Active PDO connection.
	 * @param array $wf_row      Locked workflow row.
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Step index.
	 * @param array $state       Workflow state.
	 * @param array $step_output Wait step output.
	 * @return int[] Completed workflow IDs that may unblock dependent waits.
	 */
	private function settle_wait_step(
		\PDO $pdo,
		array $wf_row,
		int $workflow_id,
		int $step_index,
		array $state,
		array $step_output,
	): array {
		$wf_tbl      = $this->conn->table( Config::table_workflows() );
		$steps       = $state['_steps'] ?? array();
		$total_steps = (int) $wf_row['total_steps'];

		$this->clear_wait_context( $state );
		$this->merge_step_output_into_state( $state, $step_output, $step_index );

		$current_step_def = $steps[ $step_index ] ?? null;
		$this->push_compensation_snapshot( $state, $current_step_def, $step_index );
		$this->increment_transition_counter( $state );
		$this->assert_workflow_budget( $state );

		$next_step = $step_index + 1;
		$is_last   = $next_step >= $total_steps;

		if ( $is_last ) {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET status = 'completed', state = :state, current_step = :step, completed_at = NOW()
				WHERE id = :id"
			);
		} else {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET status = 'running', state = :state, current_step = :step
				WHERE id = :id"
			);
		}

		$stmt->execute(
			array(
				'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
				'step'  => $next_step,
				'id'    => $workflow_id,
			)
		);

		if ( null !== $this->event_log ) {
			$this->event_log->record_workflow_resumed(
				workflow_id: $workflow_id,
				step_index: $step_index,
				handler: $this->event_handler_for_step( $current_step_def ),
				state_snapshot: $this->public_state( $state ),
				step_output: $step_output,
			);
		}

		if ( $is_last ) {
			$this->logger->log(
				LogEvent::WorkflowCompleted,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $wf_row['name'],
					'queue'       => $state['_queue'] ?? 'default',
				)
			);
			return $this->on_workflow_completed( $workflow_id, $state, $pdo );
		}

		if ( isset( $steps[ $next_step ] ) ) {
			$this->assert_for_each_budget_for_step( $state, $steps[ $next_step ] );

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

		return array();
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
			if ( '_workflow_groups' === $key && is_array( $value ) ) {
				$state['_workflow_groups'] = $value;
				continue;
			}

			if ( '_repeat_steps' === $key && is_array( $value ) ) {
				$state['_repeat_steps'] = $value;
				continue;
			}

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
	 * Normalize persisted for-each branch entries so sparse indexes survive JSON round-trips.
	 *
	 * @param array $entries Persisted result or failure entries.
	 * @return array<string,array>
	 */
	private function normalize_for_each_entries( array $entries ): array {
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
	 * Normalize for-each runtime state loaded from persisted workflow state.
	 *
	 * @param array $runtime Raw runtime state.
	 * @return array
	 */
	private function normalize_for_each_runtime( array $runtime ): array {
		$runtime['items']        = array_values( is_array( $runtime['items'] ?? null ) ? $runtime['items'] : array() );
		$runtime['results']      = $this->normalize_for_each_entries( is_array( $runtime['results'] ?? null ) ? $runtime['results'] : array() );
		$runtime['failures']     = $this->normalize_for_each_entries( is_array( $runtime['failures'] ?? null ) ? $runtime['failures'] : array() );
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
	 * Build the public aggregate payload for a settled for-each step.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime for-each state.
	 * @return array
	 */
	private function build_for_each_aggregate( array $step_def, array $runtime ): array {
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
			'mode'      => $step_def['mode'] ?? ForEachMode::All->value,
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
	 * Determine whether a for-each step has satisfied its completion condition.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime for-each state.
	 * @return bool
	 */
	private function for_each_completion_satisfied( array $step_def, array $runtime ): bool {
		$mode      = ForEachMode::from( $step_def['mode'] ?? ForEachMode::All->value );
		$successes = count( $runtime['results'] ?? array() );
		$total     = count( $runtime['items'] ?? array() );

		return match ( $mode ) {
			ForEachMode::All => $successes >= $total,
			ForEachMode::FirstSuccess => $successes >= 1,
			ForEachMode::Quorum => $successes >= max( 1, (int) ( $step_def['quorum'] ?? 1 ) ),
		};
	}

	/**
	 * Determine whether a for-each step can no longer satisfy its completion condition.
	 *
	 * @param array $step_def Step definition.
	 * @param array $runtime  Runtime for-each state.
	 * @return bool
	 */
	private function for_each_completion_impossible( array $step_def, array $runtime ): bool {
		$mode      = ForEachMode::from( $step_def['mode'] ?? ForEachMode::All->value );
		$successes = count( $runtime['results'] ?? array() );
		$failures  = count( $runtime['failures'] ?? array() );
		$total     = count( $runtime['items'] ?? array() );
		$remaining = max( 0, $total - $successes - $failures );

		return match ( $mode ) {
			ForEachMode::All => $failures > 0,
			ForEachMode::FirstSuccess => 0 === $remaining && 0 === $successes,
			ForEachMode::Quorum => $successes + $remaining < max( 1, (int) ( $step_def['quorum'] ?? 1 ) ),
		};
	}

	/**
	 * Resolve the public result key for a for-each aggregate.
	 *
	 * @param array $step_def    Step definition.
	 * @param int   $step_index  Step index.
	 * @return string
	 */
	private function for_each_result_key( array $step_def, int $step_index ): string {
		$result_key = $step_def['result_key'] ?? null;
		if ( is_string( $result_key ) && '' !== trim( $result_key ) ) {
			return trim( $result_key );
		}

		$name = $step_def['name'] ?? (string) $step_index;
		return $name . '_results';
	}

	/**
	 * Build final step output for a settled for-each step.
	 *
	 * @param array $state      Workflow state.
	 * @param array $step_def   Step definition.
	 * @param array $runtime    Runtime for-each state.
	 * @param int   $step_index Step index.
	 * @return array
	 * @throws \RuntimeException If the reducer class is invalid or returns invalid output.
	 */
	private function for_each_step_output( array $state, array $step_def, array $runtime, int $step_index ): array {
		$result_key = $this->for_each_result_key( $step_def, $step_index );
		$aggregate  = $this->build_for_each_aggregate( $step_def, $runtime );
		$output     = array( $result_key => $aggregate );

		$reducer_class = $step_def['reducer_class'] ?? null;
		if ( ! is_string( $reducer_class ) || '' === trim( $reducer_class ) ) {
			return $output;
		}

		if ( ! class_exists( $reducer_class ) ) {
			throw new \RuntimeException( "For-each reducer class '{$reducer_class}' not found." );
		}

		$reducer = new $reducer_class();
		if ( ! $reducer instanceof ForEachReducer && ! method_exists( $reducer, 'reduce' ) ) {
			throw new \RuntimeException( "For-each reducer '{$reducer_class}' must implement reduce()." );
		}

		$reducer_state                = $state;
		$reducer_state[ $result_key ] = $aggregate;
		$reducer_output               = $reducer->reduce( $this->public_state( $reducer_state ), $aggregate );

		if ( ! is_array( $reducer_output ) ) {
			throw new \RuntimeException( "For-each reducer '{$reducer_class}' must return an array." );
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
	 * @return int[] Completed workflow IDs that may unblock dependent waits.
	 * @throws \RuntimeException If `_next_step` references an unknown step name.
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
	): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$this->merge_step_output_into_state( $state, $step_output, $current_step );

		$current_step_def = $steps[ $current_step ] ?? null;
		$this->push_compensation_snapshot( $state, $current_step_def, $current_step );
		$this->increment_cost_units( $state, (int) ( $job_row['cost_units'] ?? 0 ) );
		$budget_delta = $this->workflow_budget_delta_from_step_output( $step_output );
		$this->increment_started_workflow_counter( $state, $budget_delta['started_workflows'] );
		$this->increment_transition_counter( $state );
		$this->assert_workflow_budget( $state );

		$next_step = $current_step + 1;
		if ( isset( $step_output['_next_step'] ) ) {
			$next_step_name  = $step_output['_next_step'];
			$next_step_index = $this->find_step_index_by_name( $steps, $next_step_name );

			if ( null === $next_step_index ) {
				throw new \RuntimeException(
					"Workflow {$workflow_id}: _next_step target '{$next_step_name}' not found."
				);
			}

			$next_step = $next_step_index;
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
			return $this->on_workflow_completed( $workflow_id, $state, $pdo );
		}

		if ( ! $is_paused && isset( $steps[ $next_step ] ) ) {
			$this->assert_for_each_budget_for_step( $state, $steps[ $next_step ] );

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

		return array();
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
					concurrency_group: $handler_defaults['concurrency_group'],
					concurrency_limit: $handler_defaults['concurrency_limit'],
					cost_units: $handler_defaults['cost_units'] ?? 1,
				);
			}
		} elseif ( 'for_each' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_for_each',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'run_workflow' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_run_workflow',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'start_workflows' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_start_workflows',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'delay' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_delay',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				delay: $step_def['delay_seconds'] ?? 0,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'wait_for_signal' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_wait_for_signal',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'wait_for_workflows' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_wait_for_workflows',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
		} elseif ( 'repeat' === $type ) {
			$this->queue->dispatch(
				handler: '__queuety_repeat',
				payload: array( 'step_index' => $step_index ),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
				cost_units: 0,
			);
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
				concurrency_group: $handler_defaults['concurrency_group'],
				concurrency_limit: $handler_defaults['concurrency_limit'],
				cost_units: $handler_defaults['cost_units'] ?? 1,
			);
		}
	}

	/**
	 * Handle a signal step: check for pre-existing signals or pause the workflow.
	 *
	 * When a workflow reaches a signal step, it checks whether the signal has
	 * already been sent. If so, the signal data is merged into state and the
	 * workflow advances. If not, the workflow is set to 'waiting_for_signal' status.
	 *
	 * @param array $step_def    Signal step definition.
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Step index.
	 * @throws \Throwable If the database transaction fails.
	 */
	private function enqueue_signal_step( array $step_def, int $workflow_id, int $step_index ): void {
		$pdo           = $this->conn->pdo();
		$wf_tbl        = $this->conn->table( Config::table_workflows() );
		$completed_ids = array();

		$pdo->beginTransaction();
		try {
			$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				return;
			}

			$state            = json_decode( $wf_row['state'], true ) ?: array();
			$current_step     = (int) $wf_row['current_step'];
			$status           = WorkflowStatus::from( $wf_row['status'] );
			$matched_payloads = $this->resolve_signal_wait_payloads( $workflow_id, $step_def, $state );

			if (
				$current_step !== $step_index
				|| ! in_array( $status, array( WorkflowStatus::Running, WorkflowStatus::Paused, WorkflowStatus::WaitingForSignal ), true )
			) {
				$pdo->commit();
				return;
			}

			if ( null !== $matched_payloads ) {
				$completed_ids = $this->settle_wait_step(
					$pdo,
					$wf_row,
					$workflow_id,
					$step_index,
					$state,
					$this->signal_step_output( $step_def, $matched_payloads ),
				);
			} else {
				$this->set_wait_context(
					$state,
					'signal',
					$step_index,
					$this->signal_names_for_step( $step_def ),
					$this->wait_mode_for_step( $step_def ),
					$this->wait_result_key_for_step( $step_def ),
					array(
						'step_name'       => $step_def['name'] ?? null,
						'human_wait'      => $step_def['human_wait'] ?? null,
						'match_payload'   => $this->signal_match_payload_for_step( $step_def ),
						'correlation_key' => $this->signal_correlation_key_for_step( $step_def ),
					),
				);

				$upd = $pdo->prepare(
					"UPDATE {$wf_tbl}
					SET status = :status, state = :state, current_step = :step
					WHERE id = :id"
				);
				$upd->execute(
					array(
						'status' => WorkflowStatus::WaitingForSignal->value,
						'state'  => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'   => $step_index,
						'id'     => $workflow_id,
					)
				);

				if ( null !== $this->event_log ) {
					$this->event_log->record_workflow_waiting(
						workflow_id: $workflow_id,
						step_index: $step_index,
						handler: '__queuety_wait_for_signal',
						state_snapshot: $this->public_state( $state ),
						wait_type: 'signal',
						waiting_for: $this->signal_names_for_step( $step_def ),
						details: array(
							'wait_mode'       => $this->wait_mode_for_step( $step_def )->value,
							'step_name'       => $step_def['name'] ?? null,
							'human_wait'      => $step_def['human_wait'] ?? null,
							'result_key'      => $this->wait_result_key_for_step( $step_def ),
							'match_payload'   => $this->signal_match_payload_for_step( $step_def ),
							'correlation_key' => $this->signal_correlation_key_for_step( $step_def ),
						),
					);
				}
			}

			$pdo->commit();
			$this->invalidate_workflow_cache( $workflow_id );
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		foreach ( $completed_ids as $completed_workflow_id ) {
			$this->reconcile_waiting_for_workflows_for_dependency( $completed_workflow_id );
		}
	}

	/**
	 * Expand a for-each placeholder into branch jobs for the current workflow step.
	 *
	 * @param int   $workflow_id    Workflow ID.
	 * @param int   $job_id         Placeholder job ID.
	 * @param int   $step_index     Step index.
	 * @param array $workflow_state Current workflow state.
	 * @return bool True when the placeholder job should be logged as completed.
	 * @throws WorkflowConstraintViolationException If the workflow exceeds a configured guardrail.
	 * @throws \RuntimeException If the for-each step definition or source state is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_for_each_step( int $workflow_id, int $job_id, int $step_index, array $workflow_state ): bool {
		$pdo                   = $this->conn->pdo();
		$wf_tbl                = $this->conn->table( Config::table_workflows() );
		$jb_tbl                = $this->conn->table( Config::table_jobs() );
		$state                 = $workflow_state;
		$should_log_completion = false;
		$should_compensate     = false;
		$terminal_ids          = array();

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

			if ( ! is_array( $step_def ) || 'for_each' !== ( $step_def['type'] ?? '' ) ) {
				throw new \RuntimeException( "Step {$step_index} is not a for_each definition." );
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

				$runtime = $state['_for_each_steps'][ $step_index ] ?? null;
			if ( ! is_array( $runtime ) || empty( $runtime['initialized'] ) ) {
				$items = $state[ $step_def['items_key'] ] ?? array();
				if ( ! is_array( $items ) ) {
					throw new \RuntimeException(
						"For-each step '{$step_def['name']}' expected state key '{$step_def['items_key']}' to contain an array."
					);
				}

				$max_for_each_items = $this->workflow_budget_limits( $state )['max_for_each_items'] ?? null;
				if ( null !== $max_for_each_items && count( $items ) > $max_for_each_items ) {
					throw new WorkflowConstraintViolationException(
						sprintf(
							"For-each step '%s' planned %d items, exceeding max_for_each_items budget of %d.",
							$step_def['name'],
							count( $items ),
							$max_for_each_items
						)
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
				$runtime = $this->normalize_for_each_runtime( $runtime );
			}

			$all_indexes = array_keys( $runtime['items'] ?? array() );
			$done        = array_map( 'intval', array_merge( array_keys( $runtime['results'] ?? array() ), array_keys( $runtime['failures'] ?? array() ) ) );
			$missing     = array_values( array_diff( $all_indexes, $done ) );

			$state['_for_each_steps'][ $step_index ] = $runtime;

			if ( empty( $missing ) ) {
				if ( $this->for_each_completion_satisfied( $step_def, $runtime ) ) {
					$step_output           = $this->for_each_step_output( $state, $step_def, $runtime, $step_index );
					$terminal_ids          = $this->finalize_step_completion(
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
				} elseif ( $this->for_each_completion_impossible( $step_def, $runtime ) ) {
					$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $workflow_id, $job_id, 'For-each completion condition could not be satisfied.', $state );
					$should_compensate = ! empty( $state['_compensate_on_failure'] );
					$terminal_ids      = array( $workflow_id );
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

				foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
					$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
				}

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
						'__for_each' => array(
							'item_index' => $item_index,
							'item'       => $runtime['items'][ $item_index ],
						),
					),
					queue: $queue_name,
					priority: $priority,
					max_attempts: $effective_max,
					workflow_id: $workflow_id,
					step_index: $step_index,
					concurrency_group: $handler_defaults['concurrency_group'],
					concurrency_limit: $handler_defaults['concurrency_limit'],
					cost_units: $handler_defaults['cost_units'] ?? 1,
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
	 * Record a terminal for-each branch failure and decide whether the workflow should fail.
	 *
	 * @param int    $workflow_id   Workflow ID.
	 * @param int    $failed_job_id Failed branch job ID.
	 * @param string $error_message Error message.
	 * @return bool True when the workflow failed as a result.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_for_each_terminal_failure( int $workflow_id, int $failed_job_id, string $error_message ): bool {
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
			if ( ! is_array( $step_def ) || 'for_each' !== ( $step_def['type'] ?? '' ) ) {
				$pdo->commit();
				return false;
			}

				$runtime = $state['_for_each_steps'][ $step_index ] ?? null;
			if ( ! is_array( $runtime ) ) {
				$runtime = array(
					'initialized'  => true,
					'items'        => array(),
					'results'      => array(),
					'failures'     => array(),
					'winner_index' => null,
				);
			} else {
				$runtime = $this->normalize_for_each_runtime( $runtime );
			}

				$payload     = $job_row['payload'] ? ( json_decode( $job_row['payload'], true ) ?: array() ) : array();
				$branch_meta = $payload['__for_each'] ?? array();
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

				$state['_for_each_steps'][ $step_index ] = $runtime;

			if ( ! $this->for_each_completion_impossible( $step_def, $runtime ) ) {
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
	 * Called by the Worker when it encounters a __queuety_wait_for_signal placeholder.
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
	 * Handle a workflow dependency wait step dispatched via the worker.
	 *
	 * @param int   $workflow_id    Workflow ID.
	 * @param array $step_def       Step definition.
	 * @param int   $step_index     Step index.
	 * @param array $workflow_state Current workflow state.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function handle_wait_for_workflows_step( int $workflow_id, array $step_def, int $step_index, array $workflow_state ): void {
		$pdo               = $this->conn->pdo();
		$wf_tbl            = $this->conn->table( Config::table_workflows() );
		$should_compensate = false;
		$state             = $workflow_state;
		$completed_ids     = array();
		$terminal_ids      = array();

		$pdo->beginTransaction();
		try {
			$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$wf_stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $wf_stmt->fetch();

			if ( ! $wf_row ) {
				$pdo->rollBack();
				return;
			}

			$state        = json_decode( $wf_row['state'], true ) ?: $workflow_state;
			$current_step = (int) $wf_row['current_step'];
			$status       = WorkflowStatus::from( $wf_row['status'] );

			if (
				$current_step !== $step_index
				|| ! in_array( $status, array( WorkflowStatus::Running, WorkflowStatus::Paused, WorkflowStatus::WaitingForWorkflows ), true )
			) {
				$pdo->commit();
				return;
			}

			$workflow_ids  = $this->resolve_wait_for_workflows_ids( $step_def, $state );
			$workflow_rows = $this->fetch_workflow_rows_by_id( $workflow_ids );
			$evaluation    = $this->evaluate_wait_for_workflows_state( $step_def, $workflow_ids, $workflow_rows );

			if ( 'satisfied' === $evaluation ) {
				$this->clear_workflow_dependencies( $workflow_id, $step_index );
				$completed_ids = $this->settle_wait_step(
					$pdo,
					$wf_row,
					$workflow_id,
					$step_index,
					$state,
					$this->wait_for_workflows_step_output( $step_def, $workflow_ids, $workflow_rows ),
				);
				$terminal_ids  = $completed_ids;
			} elseif ( 'pending' === $evaluation ) {
				$this->store_workflow_dependencies( $workflow_id, $step_index, $workflow_ids );
				$this->set_wait_context(
					$state,
					'workflow',
					$step_index,
					array_map( 'strval', $workflow_ids ),
					$this->wait_mode_for_step( $step_def ),
					$this->wait_result_key_for_step( $step_def ),
					array(
						'step_name' => $step_def['name'] ?? null,
						'group_key' => $this->wait_for_workflows_group_key_for_step( $step_def ),
						'quorum'    => $this->wait_for_workflows_quorum_for_step( $step_def ),
					),
				);

				$upd = $pdo->prepare(
					"UPDATE {$wf_tbl}
					SET status = :status, state = :state, current_step = :step
					WHERE id = :id"
				);
				$upd->execute(
					array(
						'status' => WorkflowStatus::WaitingForWorkflows->value,
						'state'  => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'   => $step_index,
						'id'     => $workflow_id,
					)
				);

				if ( null !== $this->event_log ) {
					$this->event_log->record_workflow_waiting(
						workflow_id: $workflow_id,
						step_index: $step_index,
						handler: '__queuety_wait_for_workflows',
						state_snapshot: $this->public_state( $state ),
						wait_type: 'workflow',
						waiting_for: array_map( 'strval', $workflow_ids ),
						details: array(
							'wait_mode'  => $this->wait_mode_for_step( $step_def )->value,
							'step_name'  => $step_def['name'] ?? null,
							'group_key'  => $this->wait_for_workflows_group_key_for_step( $step_def ),
							'quorum'     => $this->wait_for_workflows_quorum_for_step( $step_def ),
							'result_key' => $this->wait_result_key_for_step( $step_def ),
						),
					);
				}
			} else {
				$this->clear_workflow_dependencies( $workflow_id, $step_index );
				$this->clear_wait_context( $state );
				$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $workflow_id, 0, 'Workflow wait could not be satisfied.', $state );
				$should_compensate = ! empty( $state['_compensate_on_failure'] );
				$terminal_ids      = array( $workflow_id );
			}

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

		foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
			$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
		}
	}

	/**
	 * Evaluate a repeat control step and emit a `_next_step` when the repeat should continue.
	 *
	 * @param int   $workflow_id    Workflow ID.
	 * @param array $step_def       Step definition.
	 * @param int   $step_index     Step index.
	 * @param array $workflow_state Current workflow state.
	 * @return array
	 * @throws WorkflowConstraintViolationException If the repeat exceeds its configured max_iterations.
	 * @throws \RuntimeException If the repeat definition is invalid.
	 */
	public function handle_repeat_step( int $workflow_id, array $step_def, int $step_index, array $workflow_state ): array {
		if ( 'repeat' !== ( $step_def['type'] ?? '' ) ) {
			throw new \RuntimeException( "Workflow {$workflow_id}: step {$step_index} is not a repeat definition." );
		}

		$target_step = trim( (string) ( $step_def['target_step'] ?? '' ) );
		$state_key   = trim( (string) ( $step_def['state_key'] ?? '' ) );

		if ( '' === $target_step ) {
			throw new \RuntimeException( "Workflow {$workflow_id}: repeat step {$step_index} is missing a target step." );
		}

		if ( '' === $state_key ) {
			$condition_class = trim( (string) ( $step_def['condition_class'] ?? '' ) );
			if ( '' === $condition_class ) {
				throw new \RuntimeException( "Workflow {$workflow_id}: repeat step {$step_index} is missing a state key." );
			}
		}

		$should_continue = $this->repeat_should_continue( $step_def, $workflow_state );
		if ( ! $should_continue ) {
			return array(
				'_repeat_steps' => $this->repeat_steps_state(
					$workflow_state,
					$step_index,
					$this->repeat_iteration_count( $workflow_state, $step_index )
				),
			);
		}

		$next_iterations = $this->repeat_iteration_count( $workflow_state, $step_index ) + 1;
		$max_iterations  = $step_def['max_iterations'] ?? null;

		if ( null !== $max_iterations && $next_iterations > (int) $max_iterations ) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					"Workflow %d: repeat step '%s' exceeded max_iterations of %d.",
					$workflow_id,
					$step_def['name'] ?? (string) $step_index,
					(int) $max_iterations
				)
			);
		}

		return array(
			'_next_step'    => $target_step,
			'_repeat_steps' => $this->repeat_steps_state( $workflow_state, $step_index, $next_iterations ),
		);
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
		$pdo           = $this->conn->pdo();
		$wf_tbl        = $this->conn->table( Config::table_workflows() );
		$sig_tbl       = $this->conn->table( Config::table_signals() );
		$state_changed = false;
		$completed_ids = array();

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

			$state        = json_decode( $wf_row['state'], true ) ?: array();
			$current_step = (int) $wf_row['current_step'];
			$steps        = $state['_steps'] ?? array();
			$step_def     = $steps[ $current_step ] ?? null;

			if (
				WorkflowStatus::WaitingForSignal->value === $wf_row['status']
				&& is_array( $step_def )
				&& 'wait_for_signal' === ( $step_def['type'] ?? '' )
				&& in_array( $signal_name, $this->signal_names_for_step( $step_def ), true )
			) {
				$matched_payloads = $this->resolve_signal_wait_payloads( $workflow_id, $step_def, $state );
				if ( null !== $matched_payloads ) {
					$completed_ids = $this->settle_wait_step(
						$pdo,
						$wf_row,
						$workflow_id,
						$current_step,
						$state,
						$this->signal_step_output( $step_def, $matched_payloads ),
					);
					$state_changed = true;
				}
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ( $state_changed ) {
			$this->invalidate_workflow_cache( $workflow_id );
		}

		foreach ( $completed_ids as $completed_workflow_id ) {
			$this->reconcile_waiting_for_workflows_for_dependency( $completed_workflow_id );
		}
	}

	/**
	 * Re-evaluate workflows that are waiting on a terminal dependency workflow.
	 *
	 * @param int $dependency_workflow_id Dependency workflow ID.
	 * @throws \Throwable If a dependent workflow reconciliation transaction fails.
	 */
	private function reconcile_waiting_for_workflows_for_dependency( int $dependency_workflow_id ): void {
		$pdo     = $this->conn->pdo();
		$dep_tbl = $this->conn->table( Config::table_workflow_dependencies() );
		$wf_tbl  = $this->conn->table( Config::table_workflows() );

		$dep_stmt = $pdo->prepare( "SELECT status FROM {$wf_tbl} WHERE id = :id" );
		$dep_stmt->execute( array( 'id' => $dependency_workflow_id ) );
		$dependency_row = $dep_stmt->fetch();

		if ( ! $dependency_row ) {
			return;
		}

		$dependency_status = WorkflowStatus::from( $dependency_row['status'] );

		$stmt = $pdo->prepare(
			"SELECT DISTINCT waiting_workflow_id, step_index
			FROM {$dep_tbl}
			WHERE dependency_workflow_id = :dependency_workflow_id
			ORDER BY waiting_workflow_id ASC"
		);
		$stmt->execute( array( 'dependency_workflow_id' => $dependency_workflow_id ) );

		foreach ( $stmt->fetchAll() as $row ) {
			$waiting_workflow_id = (int) $row['waiting_workflow_id'];
			$step_index          = (int) $row['step_index'];
			$completed_ids       = array();
			$terminal_ids        = array();
			$should_compensate   = false;
			$state               = array();

			$pdo->beginTransaction();
			try {
				if ( WorkflowStatus::Completed === $dependency_status ) {
					$mark = $pdo->prepare(
						"UPDATE {$dep_tbl}
						SET satisfied_at = NOW()
						WHERE waiting_workflow_id = :waiting_workflow_id
							AND step_index = :step_index
							AND dependency_workflow_id = :dependency_workflow_id
							AND satisfied_at IS NULL"
					);
					$mark->execute(
						array(
							'waiting_workflow_id'    => $waiting_workflow_id,
							'step_index'             => $step_index,
							'dependency_workflow_id' => $dependency_workflow_id,
						)
					);
				}

				$wf_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
				$wf_stmt->execute( array( 'id' => $waiting_workflow_id ) );
				$wf_row = $wf_stmt->fetch();

				if ( ! $wf_row ) {
					$this->clear_workflow_dependencies( $waiting_workflow_id, $step_index );
					$pdo->commit();
					continue;
				}

				$state = json_decode( $wf_row['state'], true ) ?: array();
				if (
					WorkflowStatus::WaitingForWorkflows->value !== $wf_row['status']
					|| (int) $wf_row['current_step'] !== $step_index
				) {
					$this->clear_workflow_dependencies( $waiting_workflow_id, $step_index );
					$pdo->commit();
					$this->invalidate_workflow_cache( $waiting_workflow_id );
					continue;
				}

				$steps    = $state['_steps'] ?? array();
				$step_def = $steps[ $step_index ] ?? null;
				if ( ! is_array( $step_def ) || 'wait_for_workflows' !== ( $step_def['type'] ?? '' ) ) {
					$this->clear_workflow_dependencies( $waiting_workflow_id, $step_index );
					$this->clear_wait_context( $state );

					$upd = $pdo->prepare(
						"UPDATE {$wf_tbl} SET state = :state WHERE id = :id"
					);
					$upd->execute(
						array(
							'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
							'id'    => $waiting_workflow_id,
						)
					);

					$pdo->commit();
					$this->invalidate_workflow_cache( $waiting_workflow_id );
					continue;
				}

				$workflow_ids  = $this->resolve_wait_for_workflows_ids( $step_def, $state );
				$workflow_rows = $this->fetch_workflow_rows_by_id( $workflow_ids );
				$evaluation    = $this->evaluate_wait_for_workflows_state( $step_def, $workflow_ids, $workflow_rows );

				if ( 'satisfied' === $evaluation ) {
					$this->clear_workflow_dependencies( $waiting_workflow_id, $step_index );
					$completed_ids = $this->settle_wait_step(
						$pdo,
						$wf_row,
						$waiting_workflow_id,
						$step_index,
						$state,
						$this->wait_for_workflows_step_output( $step_def, $workflow_ids, $workflow_rows ),
					);
					$terminal_ids  = $completed_ids;
				} elseif ( 'impossible' === $evaluation ) {
					$this->clear_workflow_dependencies( $waiting_workflow_id, $step_index );
					$this->clear_wait_context( $state );
					$state             = $this->mark_workflow_failed_locked( $pdo, $wf_row, $waiting_workflow_id, 0, 'Workflow wait could not be satisfied.', $state );
					$should_compensate = ! empty( $state['_compensate_on_failure'] );
					$terminal_ids      = array( $waiting_workflow_id );
				}

				$pdo->commit();
			} catch ( \Throwable $e ) {
				if ( $pdo->inTransaction() ) {
					$pdo->rollBack();
				}
				throw $e;
			}

			if ( $should_compensate ) {
				$state = $this->run_compensations( $waiting_workflow_id, $state, 'failure' );
				$this->persist_internal_state( $waiting_workflow_id, $state );
			}

			$this->invalidate_workflow_cache( $waiting_workflow_id );

			foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
				if ( $terminal_workflow_id !== $dependency_workflow_id ) {
					$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
				}
			}
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
	 * @throws \RuntimeException If the workflow is not found or if _next_step target is invalid.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function advance_step( int $workflow_id, int $completed_job_id, array $step_output, int $duration_ms = 0 ): void {
		$pdo          = $this->conn->pdo();
		$wf_tbl       = $this->conn->table( Config::table_workflows() );
		$jb_tbl       = $this->conn->table( Config::table_jobs() );
		$terminal_ids = array();

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

			if ( 'for_each' === $current_step_type ) {
				$payload     = json_decode( $job_row['payload'], true ) ?: array();
				$branch_meta = $payload['__for_each'] ?? null;

				if ( ! is_array( $current_step_def ) || ! is_array( $branch_meta ) || ! array_key_exists( 'item_index', $branch_meta ) ) {
					throw new \RuntimeException( "Workflow {$workflow_id}: invalid for-each branch payload." );
				}

				$runtime = $state['_for_each_steps'][ $current_step ] ?? null;
				if ( ! is_array( $runtime ) || empty( $runtime['initialized'] ) ) {
					throw new \RuntimeException( "Workflow {$workflow_id}: for-each runtime state missing for step {$current_step}." );
				}
				$runtime = $this->normalize_for_each_runtime( $runtime );

				if ( ! empty( $runtime['settled'] ) ) {
					$stmt = $pdo->prepare(
						"UPDATE {$jb_tbl}
							SET status = :status, failed_at = NOW(), error_message = :error
							WHERE id = :id"
					);
					$stmt->execute(
						array(
							'status' => JobStatus::Buried->value,
							'error'  => 'For-each step already settled.',
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

				$state['_for_each_steps'][ $current_step ] = $runtime;

				if ( ! $this->for_each_completion_satisfied( $current_step_def, $runtime ) ) {
					$this->increment_cost_units( $state, (int) ( $job_row['cost_units'] ?? 0 ) );
					$this->assert_workflow_budget( $state );
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

				$runtime['settled']                        = true;
				$state['_for_each_steps'][ $current_step ] = $runtime;
				$this->persist_internal_state( $workflow_id, $state );
				$this->bury_active_jobs_for_step( $workflow_id, $current_step, 'For-each completion settled early.', $completed_job_id );

				$step_output  = $this->for_each_step_output( $state, $current_step_def, $runtime, $current_step );
				$terminal_ids = $this->finalize_step_completion(
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
				foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
					$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
				}
				return;
			}

			if ( 'parallel' === $current_step_type ) {
				$this->merge_step_output_into_state( $state, $step_output, $current_step );
				$this->increment_cost_units( $state, (int) ( $job_row['cost_units'] ?? 0 ) );
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

				// Another branch may have merged fresher state before this one won the completion race.
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
				$this->increment_transition_counter( $state );
				$this->assert_workflow_budget( $state );

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
					$terminal_ids = $this->on_workflow_completed( $workflow_id, $state, $pdo );
				} elseif ( ! $is_paused && isset( $steps[ $next_step ] ) ) {
					$this->assert_for_each_budget_for_step( $state, $steps[ $next_step ] );

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
				foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
					$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
				}
				return;
			}

				$terminal_ids = $this->finalize_step_completion(
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
			foreach ( array_values( array_unique( $terminal_ids ) ) as $terminal_workflow_id ) {
				$this->reconcile_waiting_for_workflows_for_dependency( $terminal_workflow_id );
			}
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
	private function on_workflow_completed( int $workflow_id, array $state, \PDO $pdo ): array {
		$wf_tbl        = $this->conn->table( Config::table_workflows() );
		$completed_ids = array( $workflow_id );

		$stmt = $pdo->prepare(
			"SELECT parent_workflow_id, parent_step_index FROM {$wf_tbl} WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row || empty( $row['parent_workflow_id'] ) ) {
			return $completed_ids;
		}

		$parent_id   = (int) $row['parent_workflow_id'];
		$parent_step = (int) $row['parent_step_index'];

		$parent_stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
		$parent_stmt->execute( array( 'id' => $parent_id ) );
		$parent_row = $parent_stmt->fetch();

		if ( ! $parent_row ) {
			return $completed_ids;
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
			$this->increment_transition_counter( $parent_state );
			$this->assert_workflow_budget( $parent_state );

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

			// Nested run-workflows resume upward one parent at a time.
			$completed_ids = array_merge( $completed_ids, $this->on_workflow_completed( $parent_id, $parent_state, $pdo ) );
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
				$this->assert_for_each_budget_for_step( $parent_state, $parent_steps[ $next_step ] );

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

		return array_values( array_unique( $completed_ids ) );
	}

	/**
	 * Materialize persisted workflow state from a serialised definition bundle.
	 *
	 * @param array    $definition          Workflow definition bundle.
	 * @param array    $initial_state       Initial public state.
	 * @param int|null $started_by_workflow Workflow ID that started this workflow, if any.
	 * @param int|null $started_by_step     Parent step index that started this workflow, if any.
	 * @param array    $dispatch_options    Per-dispatch options.
	 * @return array{state: array, deadline_at: string|null}
	 * @throws \RuntimeException If the definition requires an initial-state budget that is already exceeded.
	 */
	private function materialize_defined_workflow_state(
		array $definition,
		array $initial_state,
		?int $started_by_workflow = null,
		?int $started_by_step = null,
		array $dispatch_options = array(),
	): array {
		$state                  = $initial_state;
		$state['_steps']        = is_array( $definition['steps'] ?? null ) ? $definition['steps'] : array();
		$state['_queue']        = is_string( $definition['queue'] ?? null ) && '' !== trim( $definition['queue'] )
			? trim( $definition['queue'] )
			: 'default';
		$state['_priority']     = (int) ( $definition['priority'] ?? 0 );
		$state['_max_attempts'] = max( 1, (int) ( $definition['max_attempts'] ?? 3 ) );

		$cancel_handler = $definition['cancel_handler'] ?? null;
		if ( is_string( $cancel_handler ) && '' !== trim( $cancel_handler ) ) {
			$state['_on_cancel'] = trim( $cancel_handler );
		}

		$prune_after = $definition['prune_after'] ?? null;
		if ( is_int( $prune_after ) && $prune_after > 0 ) {
			$state['_prune_state_after'] = $prune_after;
			$state['_step_outputs']      = array();
		}

		$deadline_seconds = $definition['deadline_seconds'] ?? null;
		$deadline_at      = null;
		if ( is_int( $deadline_seconds ) && $deadline_seconds > 0 ) {
			$state['_deadline_seconds'] = $deadline_seconds;
			$deadline_at                = gmdate( 'Y-m-d H:i:s', time() + $deadline_seconds );
		}

		$deadline_handler = $definition['deadline_handler'] ?? null;
		if ( is_string( $deadline_handler ) && '' !== trim( $deadline_handler ) ) {
			$state['_on_deadline'] = trim( $deadline_handler );
		}

		$definition_version = $definition['definition_version'] ?? null;
		if ( is_string( $definition_version ) && '' !== trim( $definition_version ) ) {
			$state['_definition_version'] = trim( $definition_version );
		}

		$definition_hash = $definition['definition_hash'] ?? null;
		if ( is_string( $definition_hash ) && '' !== trim( $definition_hash ) ) {
			$state['_definition_hash'] = trim( $definition_hash );
		}

			$workflow_budget = $definition['workflow_budget'] ?? null;
		if ( is_array( $workflow_budget ) && ! empty( $workflow_budget ) ) {
			$normalized_budget = array();
			foreach ( array( 'max_transitions', 'max_for_each_items', 'max_state_bytes', 'max_cost_units', 'max_started_workflows' ) as $key ) {
				$value = $workflow_budget[ $key ] ?? null;
				if ( is_int( $value ) && $value > 0 ) {
					$normalized_budget[ $key ] = $value;
				}
			}

			if ( ! empty( $normalized_budget ) ) {
				$state['_workflow_budget']   = $normalized_budget;
				$state['_workflow_counters'] = array(
					'transitions'       => 0,
					'cost_units'        => 0,
					'started_workflows' => 0,
				);

				$max_state_bytes = $normalized_budget['max_state_bytes'] ?? null;
				if ( null !== $max_state_bytes && strlen( json_encode( $initial_state, JSON_THROW_ON_ERROR ) ) > $max_state_bytes ) {
					throw new \RuntimeException(
						sprintf(
							'Workflow initial state exceeds configured max_state_bytes budget of %d.',
							$max_state_bytes
						)
					);
				}
			}
		}

		if ( ! empty( $definition['compensate_on_failure'] ) ) {
			$state['_compensate_on_failure'] = true;
		}

		$idempotency_key = $this->normalize_workflow_dispatch_idempotency_key( $dispatch_options );
		if ( null !== $idempotency_key ) {
			$state['_idempotency_key'] = $idempotency_key;
		}

		if ( null !== $started_by_workflow ) {
			$state['_started_by_workflow_id'] = $started_by_workflow;
			$state['_started_by_step_index']  = $started_by_step;
		}

		return array(
			'state'       => $state,
			'deadline_at' => $deadline_at,
		);
	}

	/**
	 * Dispatch a workflow from a serialised definition bundle.
	 *
	 * @param array    $definition          Workflow definition bundle.
	 * @param array    $initial_state       Initial public state.
	 * @param int|null $parent_workflow_id  Parent workflow ID when dispatching a run-workflow.
	 * @param int|null $parent_step_index   Parent step index when dispatching a run-workflow.
	 * @param int|null $started_by_workflow Workflow ID that started this workflow, if any.
	 * @param int|null $started_by_step     Step index that started this workflow, if any.
	 * @param array    $dispatch_options    Per-dispatch options.
	 * @return int
	 * @throws \PDOException If the idempotency key insert races with another dispatch.
	 * @throws \Throwable If the database transaction fails.
	 */
	private function dispatch_defined_workflow(
		array $definition,
		array $initial_state,
		?int $parent_workflow_id = null,
		?int $parent_step_index = null,
		?int $started_by_workflow = null,
		?int $started_by_step = null,
		array $dispatch_options = array(),
	): int {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$state_bundle    = $this->materialize_defined_workflow_state( $definition, $initial_state, $started_by_workflow, $started_by_step, $dispatch_options );
		$state           = $state_bundle['state'];
		$deadline_at     = $state_bundle['deadline_at'];
		$steps           = $state['_steps'] ?? array();
		$queue_name      = $state['_queue'] ?? 'default';
		$priority        = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
		$max_attempts    = $state['_max_attempts'] ?? 3;
		$idempotency_key = $this->idempotency_key_from_state( $state );
		$workflow_name   = is_string( $definition['name'] ?? null ) && '' !== trim( $definition['name'] )
			? trim( $definition['name'] )
			: 'workflow';

		$pdo->beginTransaction();
		try {
			if ( null !== $idempotency_key ) {
				$existing_workflow_id = $this->find_existing_workflow_id_for_key( $idempotency_key, $pdo );
				if ( null !== $existing_workflow_id ) {
					$pdo->commit();
					return $existing_workflow_id;
				}
			}

			$stmt = $pdo->prepare(
				"INSERT INTO {$wf_tbl}
				(name, status, state, current_step, total_steps, parent_workflow_id, parent_step_index, deadline_at)
				VALUES (:name, 'running', :state, 0, :total_steps, :parent_id, :parent_step, :deadline_at)"
			);
			$stmt->execute(
				array(
					'name'        => $workflow_name,
					'state'       => json_encode( $state, JSON_THROW_ON_ERROR ),
					'total_steps' => count( $steps ),
					'parent_id'   => $parent_workflow_id,
					'parent_step' => $parent_step_index,
					'deadline_at' => $deadline_at,
				)
			);
			$workflow_id = (int) $pdo->lastInsertId();

			if ( null !== $idempotency_key ) {
				$key_tbl  = $this->conn->table( Config::table_workflow_dispatch_keys() );
				$key_stmt = $pdo->prepare(
					"INSERT INTO {$key_tbl} (dispatch_key, workflow_id)
					VALUES (:dispatch_key, :workflow_id)"
				);
				$key_stmt->execute(
					array(
						'dispatch_key' => $idempotency_key,
						'workflow_id'  => $workflow_id,
					)
				);
			}

			if ( ! empty( $steps ) ) {
				$this->enqueue_step_def(
					$steps[0],
					$workflow_id,
					0,
					$queue_name,
					$priority,
					$max_attempts,
				);
			}

			$this->logger->log(
				LogEvent::WorkflowStarted,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $workflow_name,
					'queue'       => $queue_name,
				)
			);

			$pdo->commit();
			return $workflow_id;
		} catch ( \PDOException $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			if ( null !== $idempotency_key && $this->is_duplicate_key_error( $e ) ) {
				$existing_workflow_id = $this->find_existing_workflow_id_for_key( $idempotency_key );
				if ( null !== $existing_workflow_id ) {
					return $existing_workflow_id;
				}
			}

			throw $e;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $e;
		}
	}

	/**
	 * Dispatch a workflow from a runtime definition bundle.
	 *
	 * @param array $definition       Workflow definition bundle.
	 * @param array $initial_state    Initial public state.
	 * @param array $dispatch_options Per-dispatch options.
	 * @return int
	 * @throws \RuntimeException If the definition has no steps or the workflow dispatch fails.
	 */
	public function dispatch_definition( array $definition, array $initial_state = array(), array $dispatch_options = array() ): int {
		if ( empty( $definition['steps'] ) || ! is_array( $definition['steps'] ) ) {
			throw new \RuntimeException( 'Workflow definition must have at least one step.' );
		}

		return $this->dispatch_defined_workflow( $definition, $initial_state, null, null, null, null, $dispatch_options );
	}

	/**
	 * Dispatch a run-workflow linked to a parent workflow.
	 *
	 * @param int    $parent_workflow_id The parent workflow ID.
	 * @param int    $parent_step_index  The step index in the parent.
	 * @param string $name               Run-workflow name.
	 * @param array  $steps              Step definitions array (from build_steps()).
	 * @param array  $initial_state      Initial state for the run-workflow.
	 * @param string $queue_name         Queue name.
	 * @param int    $priority_value     Priority value.
	 * @param int    $max_attempts       Max attempts.
	 * @return int The run-workflow ID.
	 * @throws \Throwable If the database operation fails.
	 */
	public function dispatch_run_workflow(
		int $parent_workflow_id,
		int $parent_step_index,
		string $name,
		array $steps,
		array $initial_state,
		string $queue_name = 'default',
		int $priority_value = 0,
		int $max_attempts = 3,
	): int {
		return $this->dispatch_defined_workflow(
			array(
				'name'         => $name,
				'steps'        => $steps,
				'queue'        => $queue_name,
				'priority'     => $priority_value,
				'max_attempts' => $max_attempts,
			),
			$initial_state,
			$parent_workflow_id,
			$parent_step_index,
		);
	}

	/**
	 * Handle a run-workflow step: dispatch the run-workflow and mark the placeholder job.
	 *
	 * Called by the Worker when it encounters a __queuety_run_workflow handler.
	 *
	 * @param int   $workflow_id    The parent workflow ID.
	 * @param int   $job_id         The placeholder job ID.
	 * @param int   $step_index     The step index.
	 * @param array $workflow_state The parent workflow's current state.
	 * @throws \RuntimeException If the step definition is not a run_workflow.
	 */
	public function handle_run_workflow_step( int $workflow_id, int $job_id, int $step_index, array $workflow_state ): void {
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$steps  = $workflow_state['_steps'] ?? array();

		$step_def = $steps[ $step_index ] ?? null;
		if ( ! $step_def || ! is_array( $step_def ) || 'run_workflow' !== ( $step_def['type'] ?? '' ) ) {
			throw new \RuntimeException( "Step {$step_index} is not a run_workflow definition." );
		}

		$workflow_steps    = $step_def['workflow_steps'] ?? array();
		$workflow_name     = $step_def['workflow_name'] ?? 'run_workflow';
		$workflow_queue    = $step_def['workflow_queue'] ?? ( $workflow_state['_queue'] ?? 'default' );
		$workflow_priority = $step_def['workflow_priority'] ?? ( $workflow_state['_priority'] ?? 0 );
		$sub_max           = $step_def['workflow_max_attempts'] ?? ( $workflow_state['_max_attempts'] ?? 3 );

		$initial_state = array();
		foreach ( $workflow_state as $key => $value ) {
			if ( ! str_starts_with( $key, '_' ) ) {
				$initial_state[ $key ] = $value;
			}
		}

		$this->dispatch_run_workflow(
			parent_workflow_id: $workflow_id,
			parent_step_index: $step_index,
			name: $workflow_name,
			steps: $workflow_steps,
			initial_state: $initial_state,
			queue_name: $workflow_queue,
			priority_value: $workflow_priority,
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
	 * Start independent top-level workflows from runtime-discovered items.
	 *
	 * @param int   $workflow_id    Parent workflow ID.
	 * @param int   $step_index     Step index.
	 * @param array $workflow_state Parent workflow state.
	 * @return array Step output containing the started workflow IDs.
	 * @throws \RuntimeException If the step definition or source payloads are invalid.
	 * @throws WorkflowConstraintViolationException If the step would exceed the configured start budget.
	 */
	public function handle_start_workflows_step( int $workflow_id, int $step_index, array $workflow_state ): array {
		$steps    = $workflow_state['_steps'] ?? array();
		$step_def = $steps[ $step_index ] ?? null;

		if ( ! is_array( $step_def ) || 'start_workflows' !== ( $step_def['type'] ?? '' ) ) {
			throw new \RuntimeException( "Step {$step_index} is not a start_workflows definition." );
		}

		$items_key = trim( (string) ( $step_def['items_key'] ?? '' ) );
		$items     = $workflow_state[ $items_key ] ?? array();
		if ( ! is_array( $items ) ) {
			throw new \RuntimeException( "Start step '{$step_def['name']}' requires state['{$items_key}'] to be an array." );
		}

		$definition = $step_def['workflow_definition'] ?? null;
		if ( ! is_array( $definition ) || ! is_array( $definition['steps'] ?? null ) ) {
			throw new \RuntimeException( "Start step '{$step_def['name']}' is missing a valid workflow definition." );
		}

		$result_key     = trim( (string) ( $step_def['result_key'] ?? 'started_workflow_ids' ) );
		$payload_key    = trim( (string) ( $step_def['payload_key'] ?? 'item' ) );
		$group_key      = trim( (string) ( $step_def['group_key'] ?? '' ) );
		$inherit_state  = ! empty( $step_def['inherit_state'] );
		$base_state     = $inherit_state ? $this->public_state( $workflow_state ) : array();
		$start_count    = count( $items );
		$budget_limits  = $this->workflow_budget_limits( $workflow_state );
		$started_so_far = (int) ( $workflow_state['_workflow_counters']['started_workflows'] ?? 0 );

		unset( $base_state[ $items_key ], $base_state[ $result_key ] );

		if (
			isset( $budget_limits['max_started_workflows'] )
			&& $started_so_far + $start_count > $budget_limits['max_started_workflows']
		) {
			throw new WorkflowConstraintViolationException(
				sprintf(
					"Start step '%s' would exceed max_started_workflows budget of %d.",
					$step_def['name'] ?? (string) $step_index,
					$budget_limits['max_started_workflows']
				)
			);
		}

		$started_ids = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$child_state = $base_state;

			if ( is_array( $item ) ) {
				foreach ( $item as $key => $value ) {
					if ( is_string( $key ) && ! str_starts_with( $key, '_' ) ) {
						$child_state[ $key ] = $value;
					}
				}
			} else {
				$child_state[ $payload_key ] = $item;
			}

			$child_state['start_item_index'] = $index;

			$started_ids[] = $this->dispatch_defined_workflow(
				$definition,
				$child_state,
				null,
				null,
				$workflow_id,
				$step_index,
			);
		}

		$output = array( $result_key => $started_ids );

		if ( '' !== $group_key ) {
			$groups                     = $workflow_state['_workflow_groups'] ?? array();
			$groups                     = is_array( $groups ) ? $groups : array();
			$groups[ $group_key ]       = $started_ids;
			$output['_workflow_groups'] = $groups;
		}

		if ( ! empty( $started_ids ) ) {
			$output['_workflow_budget_delta'] = array(
				'started_workflows' => count( $started_ids ),
			);
		}

		return $output;
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
		$this->reconcile_waiting_for_workflows_for_dependency( $workflow_id );
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

			$should_compensate = in_array(
				$wf_row['status'],
				array(
					WorkflowStatus::Running->value,
					WorkflowStatus::Paused->value,
					WorkflowStatus::WaitingForSignal->value,
					WorkflowStatus::WaitingForWorkflows->value,
				),
				true
			);
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
		$this->reconcile_waiting_for_workflows_for_dependency( $workflow_id );
	}

	/**
	 * Get the current status of a workflow.
	 *
	 * @param array      $row Workflow row.
	 * @param array|null $artifact_meta Preloaded artifact summary, if available.
	 * @param bool       $include_wait_details Whether to resolve detailed wait inspection data.
	 * @return WorkflowState
	 */
	private function build_workflow_state_from_row( array $row, ?array $artifact_meta = null, bool $include_wait_details = true ): WorkflowState {
		$state         = json_decode( $row['state'], true ) ?: array();
		$current_step  = (int) $row['current_step'];
		$wait          = $this->wait_context_from_state( $state );
		$public_state  = $this->public_state( $state );
		$waiting_for   = $wait['waiting_for'] ?? ( $wait['signal_names'] ?? null );
		$wait_mode     = isset( $wait['wait_mode'] ) && is_string( $wait['wait_mode'] ) ? $wait['wait_mode'] : null;
		$wait_details  = $include_wait_details
			? $this->wait_details_from_state( (int) $row['id'], $state, $current_step, $wait )
			: null;
		$artifact_meta = $artifact_meta ?? ( null !== $this->artifacts ? $this->artifacts->summary( (int) $row['id'] ) : null );

		return new WorkflowState(
			workflow_id: (int) $row['id'],
			name: $row['name'],
			status: WorkflowStatus::from( $row['status'] ),
			current_step: $current_step,
			total_steps: (int) $row['total_steps'],
			state: $public_state,
			parent_workflow_id: $row['parent_workflow_id'] ? (int) $row['parent_workflow_id'] : null,
			parent_step_index: $row['parent_step_index'] !== null ? (int) $row['parent_step_index'] : null,
			wait_type: $wait['type'] ?? null,
			waiting_for: is_array( $waiting_for ) ? $waiting_for : null,
			definition_version: $this->definition_version_from_state( $state ),
			definition_hash: $this->definition_hash_from_state( $state ),
			idempotency_key: $this->idempotency_key_from_state( $state ),
			budget: $this->budget_summary_from_state( $state ),
			current_step_name: $this->current_step_name_from_state( $state, $current_step ),
			wait_mode: $wait_mode,
			wait_details: $wait_details,
			artifact_count: $artifact_meta['count'] ?? null,
			artifact_keys: $artifact_meta['keys'] ?? null,
		);
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
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT id, name, status, state, current_step, total_steps, parent_workflow_id, parent_step_index
			FROM {$wf_tbl}
			WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return null;
		}

		$result = $this->build_workflow_state_from_row( $row );

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

			if ( is_array( $steps[ $current_step ] ) && 'for_each' === ( $steps[ $current_step ]['type'] ?? '' ) ) {
				$runtime = $state['_for_each_steps'][ $current_step ] ?? null;
				if ( is_array( $runtime ) ) {
					$runtime['failures']                       = array();
					$runtime['settled']                        = false;
					$state['_for_each_steps'][ $current_step ] = $runtime;
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
	 * @param int                 $limit  Maximum rows to return.
	 * @return WorkflowState[]
	 */
	public function list( ?WorkflowStatus $status = null, int $limit = 50 ): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$sql    = "SELECT id, name, status, state, current_step, total_steps, parent_workflow_id, parent_step_index FROM {$wf_tbl}";
		$params = array();

		if ( null !== $status ) {
			$sql             .= ' WHERE status = :status';
			$params['status'] = $status->value;
		}

		$limit = max( 1, $limit );
		$sql  .= " ORDER BY id DESC LIMIT {$limit}";
		$stmt  = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$rows = $stmt->fetchAll();

		$artifact_summaries = $this->workflow_artifact_summaries( array_column( $rows, 'id' ) );
		$results            = array();
		foreach ( $rows as $row ) {
			$workflow_id = (int) $row['id'];
			$results[]   = $this->build_workflow_state_from_row(
				$row,
				$artifact_summaries[ $workflow_id ] ?? null,
				false
			);
		}

		return $results;
	}

	/**
	 * Batch-load artifact summaries for workflow rows used by list views.
	 *
	 * @param array<int, mixed> $workflow_ids Workflow IDs.
	 * @return array<int, array{count:int,keys:string[]}>
	 */
	private function workflow_artifact_summaries( array $workflow_ids ): array {
		if ( null === $this->artifacts ) {
			return array();
		}

		return $this->artifacts->summaries(
			array_map(
				'intval',
				$workflow_ids
			)
		);
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
			$this->reconcile_waiting_for_workflows_for_dependency( $workflow_id );
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
				AND status IN (:running, :paused, :waiting_for_signal, :waiting_for_workflows)"
		);
		$stmt->execute(
			array(
				'error'                 => $error_message,
				'id'                    => $workflow_id,
				'running'               => WorkflowStatus::Running->value,
				'paused'                => WorkflowStatus::Paused->value,
				'waiting_for_signal'    => WorkflowStatus::WaitingForSignal->value,
				'waiting_for_workflows' => WorkflowStatus::WaitingForWorkflows->value,
				'state'                 => json_encode( $state, JSON_THROW_ON_ERROR ),
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
