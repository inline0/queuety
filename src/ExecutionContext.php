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
	 * @var array{job_id:int,workflow_id:int|null,step_index:int|null,payload:array}|null
	 */
	private static ?array $frame = null;

	/**
	 * Active trace buffer.
	 *
	 * @var array{input?:mixed,output?:mixed,context?:array,artifacts?:array,chunks?:array}
	 */
	private static array $trace = array();

	/**
	 * Store the active execution frame.
	 *
	 * @param int      $job_id       Current job ID.
	 * @param int|null $workflow_id  Current workflow ID, if any.
	 * @param int|null $step_index   Current workflow step index, if any.
	 * @param array    $payload      Current job payload.
	 */
	public static function enter( int $job_id, ?int $workflow_id = null, ?int $step_index = null, array $payload = array() ): void {
		self::$frame = array(
			'job_id'      => $job_id,
			'workflow_id' => $workflow_id,
			'step_index'  => $step_index,
			'payload'     => $payload,
		);
		self::$trace = array();
	}

	/**
	 * Clear the active execution frame.
	 */
	public static function clear(): void {
		self::$frame = null;
		self::$trace = array();
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
	 * @return array
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
	 * @param array $context Context values.
	 */
	public static function add_trace_context( array $context ): void {
		self::$trace['context'] = array_merge( self::$trace['context'] ?? array(), $context );
	}

	/**
	 * Add a trace artifact reference.
	 *
	 * @param array $artifact Artifact reference.
	 */
	public static function add_trace_artifact( array $artifact ): void {
		self::$trace['artifacts']   = self::$trace['artifacts'] ?? array();
		self::$trace['artifacts'][] = $artifact;
	}

	/**
	 * Add a trace chunk reference.
	 *
	 * @param array $chunk Chunk reference.
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
			self::add_trace_context( $trace['context'] );
		}
		if ( isset( $trace['artifacts'] ) && is_array( $trace['artifacts'] ) ) {
			foreach ( $trace['artifacts'] as $artifact ) {
				if ( is_array( $artifact ) ) {
					self::add_trace_artifact( $artifact );
				}
			}
		}
		if ( isset( $trace['chunks'] ) && is_array( $trace['chunks'] ) ) {
			foreach ( $trace['chunks'] as $chunk ) {
				if ( is_array( $chunk ) ) {
					self::add_trace_chunk( $chunk );
				}
			}
		}
	}

	/**
	 * Consume and clear the current trace buffer.
	 *
	 * @return array
	 */
	public static function consume_trace(): array {
		$trace       = self::$trace;
		self::$trace = array();
		return $trace;
	}
}
