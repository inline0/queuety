<?php
/**
 * Runtime execution context for the currently processing job.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Exposes workflow/job context to helper APIs during worker execution.
 */
final class ExecutionContext {

	/**
	 * Reserved step-output key for trace metadata.
	 */
	public const TRACE_OUTPUT_KEY = '_queuety_trace';

	/**
	 * Active execution frame.
	 *
	 * @var array{job_id: int, workflow_id: int|null, step_index: int|null, payload: array<string, mixed>}|null
	 */
	private static ?array $frame = null;

	/**
	 * Active claim identity.
	 *
	 * @var Job|null
	 */
	private static ?Job $job = null;

	/**
	 * Monotonic deadline in nanoseconds, independent of wall-clock changes.
	 *
	 * @var int|null
	 */
	private static ?int $deadline_ns = null;

	/**
	 * Database writes staged until completion.
	 *
	 * @var list<callable(Connection, Job): void>
	 */
	private static array $completion_callbacks = array();

	/**
	 * Whether completion callbacks are currently running.
	 *
	 * @var bool
	 */
	private static bool $completing = false;

	/**
	 * Active trace buffer.
	 *
	 * @var array{input?: mixed, output?: mixed, context?: array<string, mixed>, artifacts?: array<int, array<string, mixed>>, chunks?: array<int, array<string, mixed>>}
	 */
	private static array $trace = array();

	/**
	 * Store the active execution frame.
	 *
	 * @param int                  $job_id      Current job ID.
	 * @param int|null             $workflow_id Current workflow ID, if any.
	 * @param int|null             $step_index  Current workflow step index, if any.
	 * @param array<string, mixed> $payload     Current job payload.
	 * @param Job|null             $job         Current claimed job.
	 */
	public static function enter( int $job_id, ?int $workflow_id = null, ?int $step_index = null, array $payload = array(), ?Job $job = null ): void {
		self::$frame = array(
			'job_id'      => $job_id,
			'workflow_id' => $workflow_id,
			'step_index'  => $step_index,
			'payload'     => $payload,
		);
		self::$trace = array();
		self::$job = $job;
		self::$deadline_ns = null;
		self::$completion_callbacks = array();
		self::$completing = false;
	}

	/**
	 * Clear the active execution frame.
	 */
	public static function clear(): void {
		self::$frame = null;
		self::$trace = array();
		self::$job = null;
		self::$deadline_ns = null;
		self::$completion_callbacks = array();
		self::$completing = false;
	}

	/** Return the claim identity of the executing job. */
	public static function job(): ?Job {
		return self::$job;
	}

	/**
	 * Set the cooperative deadline for the current action.
	 *
	 * @param int|null $deadline_ns Monotonic deadline from hrtime(true).
	 */
	public static function set_deadline( ?int $deadline_ns ): void {
		self::$deadline_ns = $deadline_ns;
	}

	/**
	 * Return the remaining budget to apply to an HTTP or tool transport.
	 *
	 * @return float|null Seconds remaining, or null outside a bounded action.
	 */
	public static function remaining_seconds(): ?float {
		return null === self::$deadline_ns ? null : max( 0.0, ( self::$deadline_ns - hrtime( true ) ) / 1_000_000_000 );
	}

	/**
	 * Check the cooperative deadline and current database claim before more work.
	 *
	 * @throws \Queuety\Exceptions\TimeoutException When the cooperative budget expires.
	 */
	public static function assert_active(): void {
		if ( null !== self::$deadline_ns && hrtime( true ) >= self::$deadline_ns ) {
			throw new \Queuety\Exceptions\TimeoutException( 0 );
		}
		if ( null !== self::$job ) {
			Heartbeat::assert_active();
		}
	}

	/**
	 * Stage database-only writes in the job's successful completion transaction.
	 *
	 * Callbacks receive the owning connection and claim. They must not commit,
	 * perform network I/O, or dispatch external effects. A thrown exception rolls
	 * back the checkpoint, workflow state, job completion, and next enqueue.
	 *
	 * @param callable(Connection, Job): void $callback Completion database writes.
	 * @throws \LogicException When no claimed job is executing.
	 */
	public static function on_completion( callable $callback ): void {
		if ( null === self::$job || self::$completing ) {
			throw new \LogicException( 'Completion callbacks require an executing claimed job.' );
		}
		self::$completion_callbacks[] = $callback;
	}

	/** Whether writes are running within the completion transaction. */
	public static function is_completing(): bool {
		return self::$completing;
	}

	/**
	 * Run staged database writes after the caller locks and validates the claim.
	 *
	 * @param Connection $conn Owning connection with an active transaction.
	 * @param Job        $job  Claim being completed.
	 * @throws \LogicException When the transaction or execution identity differs.
	 */
	public static function flush_completion( Connection $conn, Job $job ): void {
		self::assert_active();
		self::assert_completion_transaction( $conn );
		if ( null === self::$job ) {
			return;
		}
		if ( self::$job->id !== $job->id || self::$job->claim_token !== $job->claim_token ) {
			throw new \LogicException( 'Completion writes belong to a different claim.' );
		}
		self::$completing = true;
		try {
			foreach ( self::$completion_callbacks as $callback ) {
				$callback( $conn, $job );
				self::assert_completion_transaction( $conn );
				self::assert_active();
			}
		} finally {
			self::$completing = false;
		}
	}

	/**
	 * Check transaction state again after application callbacks may have changed it.
	 *
	 * @param Connection $conn Completion connection.
	 * @throws \LogicException When the completion transaction has ended.
	 */
	private static function assert_completion_transaction( Connection $conn ): void {
		if ( ! $conn->pdo()->inTransaction() ) {
			throw new \LogicException( 'Completion writes require an active transaction.' );
		}
	}

	/**
	 * Run authorized post-commit cleanup outside the completed job's frame.
	 *
	 * This is an internal context lifecycle helper, not an authorization check.
	 * The caller must establish its own administrative generation before calling
	 * it. The cooperative deadline remains active throughout cleanup.
	 *
	 * @template T
	 * @param callable(): T $callback Authorized cleanup operation.
	 * @return T Callback result.
	 */
	public static function without_claim( callable $callback ): mixed {
		$frame = self::$frame;
		$job = self::$job;
		$trace = self::$trace;
		$callbacks = self::$completion_callbacks;
		$completing = self::$completing;
		self::$frame = null;
		self::$job = null;
		self::$trace = array();
		self::$completion_callbacks = array();
		self::$completing = false;
		try {
			return $callback();
		} finally {
			self::$frame = $frame;
			self::$job = $job;
			self::$trace = $trace;
			self::$completion_callbacks = $callbacks;
			self::$completing = $completing;
		}
	}

	/**
	 * Get the current workflow ID.
	 *
	 * @return int|null
	 */
	public static function workflow_id(): ?int {
		return self::$frame['workflow_id'] ?? null;
	}

	/**
	 * Get the current workflow step index.
	 *
	 * @return int|null
	 */
	public static function step_index(): ?int {
		return self::$frame['step_index'] ?? null;
	}

	/**
	 * Get the current job ID.
	 *
	 * @return int|null
	 */
	public static function job_id(): ?int {
		return self::$frame['job_id'] ?? null;
	}

	/**
	 * Get the current job payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		return self::$frame['payload'] ?? array();
	}

	/**
	 * Set the current trace input.
	 *
	 * @param mixed $input Trace input.
	 */
	public static function set_trace_input( mixed $input ): void {
		self::$trace['input'] = $input;
	}

	/**
	 * Set the current trace output.
	 *
	 * @param mixed $output Trace output.
	 */
	public static function set_trace_output( mixed $output ): void {
		self::$trace['output'] = $output;
	}

	/**
	 * Add trace context values.
	 *
	 * @param array<string, mixed> $context Context values.
	 */
	public static function add_trace_context( array $context ): void {
		self::$trace['context'] = array_merge( self::$trace['context'] ?? array(), $context );
	}

	/**
	 * Add a trace artifact reference.
	 *
	 * @param array<string, mixed> $artifact Artifact reference.
	 */
	public static function add_trace_artifact( array $artifact ): void {
		self::$trace['artifacts']   = self::$trace['artifacts'] ?? array();
		self::$trace['artifacts'][] = $artifact;
	}

	/**
	 * Add a trace chunk reference.
	 *
	 * @param array<string, mixed> $chunk Chunk reference.
	 */
	public static function add_trace_chunk( array $chunk ): void {
		self::$trace['chunks']   = self::$trace['chunks'] ?? array();
		self::$trace['chunks'][] = $chunk;
	}

	/**
	 * Merge trace data from a reserved step-output payload.
	 *
	 * @param mixed $trace Trace payload.
	 */
	public static function merge_trace_payload( mixed $trace ): void {
		if ( ! is_array( $trace ) ) {
			return;
		}

		if ( array_key_exists( 'input', $trace ) ) {
			self::set_trace_input( $trace['input'] );
		}
		if ( array_key_exists( 'output', $trace ) ) {
			self::set_trace_output( $trace['output'] );
		}
		if ( isset( $trace['context'] ) && is_array( $trace['context'] ) ) {
			self::add_trace_context( self::ensure_string_keys( $trace['context'] ) );
		}
		if ( isset( $trace['artifacts'] ) && is_array( $trace['artifacts'] ) ) {
			foreach ( $trace['artifacts'] as $artifact ) {
				if ( is_array( $artifact ) ) {
					self::add_trace_artifact( self::ensure_string_keys( $artifact ) );
				}
			}
		}
		if ( isset( $trace['chunks'] ) && is_array( $trace['chunks'] ) ) {
			foreach ( $trace['chunks'] as $chunk ) {
				if ( is_array( $chunk ) ) {
					self::add_trace_chunk( self::ensure_string_keys( $chunk ) );
				}
			}
		}
	}

	/**
	 * Filter to only entries with string keys.
	 *
	 * @param array<mixed, mixed> $values Values to filter.
	 * @return array<string, mixed>
	 */
	private static function ensure_string_keys( array $values ): array {
		$result = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Consume and clear the current trace buffer.
	 *
	 * @return array{input?: mixed, output?: mixed, context?: array<string, mixed>, artifacts?: array<int, array<string, mixed>>, chunks?: array<int, array<string, mixed>>}
	 */
	public static function consume_trace(): array {
		$trace       = self::$trace;
		self::$trace = array();
		return $trace;
	}
}
