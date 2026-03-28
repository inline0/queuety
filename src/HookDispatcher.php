<?php
/**
 * WordPress hook dispatcher for lifecycle events.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Fires WordPress action hooks at job/workflow lifecycle points.
 * Silently no-ops when WordPress is not loaded.
 */
class HookDispatcher {

	/**
	 * Fire a WordPress action if do_action() is available.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Hook arguments.
	 */
	public static function fire( string $hook, mixed ...$args ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( $hook, ...$args );
		}
	}

	/**
	 * Fire the job_started hook.
	 *
	 * @param int    $job_id  Job ID.
	 * @param string $handler Handler name.
	 */
	public static function job_started( int $job_id, string $handler ): void {
		self::fire( 'queuety_job_started', $job_id, $handler );
	}

	/**
	 * Fire the job_completed hook.
	 *
	 * @param int    $job_id      Job ID.
	 * @param string $handler     Handler name.
	 * @param int    $duration_ms Duration in milliseconds.
	 */
	public static function job_completed( int $job_id, string $handler, int $duration_ms ): void {
		self::fire( 'queuety_job_completed', $job_id, $handler, $duration_ms );
	}

	/**
	 * Fire the job_failed hook.
	 *
	 * @param int        $job_id    Job ID.
	 * @param string     $handler   Handler name.
	 * @param \Throwable $exception The exception that caused the failure.
	 */
	public static function job_failed( int $job_id, string $handler, \Throwable $exception ): void {
		self::fire( 'queuety_job_failed', $job_id, $handler, $exception );
	}

	/**
	 * Fire the job_buried hook.
	 *
	 * @param int        $job_id    Job ID.
	 * @param string     $handler   Handler name.
	 * @param \Throwable $exception The exception that caused the burial.
	 */
	public static function job_buried( int $job_id, string $handler, \Throwable $exception ): void {
		self::fire( 'queuety_job_buried', $job_id, $handler, $exception );
	}

	/**
	 * Fire the workflow_started hook.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param string $name        Workflow name.
	 * @param int    $total_steps Total number of steps.
	 */
	public static function workflow_started( int $workflow_id, string $name, int $total_steps ): void {
		self::fire( 'queuety_workflow_started', $workflow_id, $name, $total_steps );
	}

	/**
	 * Fire the workflow_step_completed hook.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param int    $step_index  The completed step index.
	 * @param string $handler     Handler name.
	 * @param int    $duration_ms Duration in milliseconds.
	 */
	public static function workflow_step_completed( int $workflow_id, int $step_index, string $handler, int $duration_ms ): void {
		self::fire( 'queuety_workflow_step_completed', $workflow_id, $step_index, $handler, $duration_ms );
	}

	/**
	 * Fire the workflow_completed hook.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param string $name        Workflow name.
	 * @param array  $final_state Final accumulated state.
	 */
	public static function workflow_completed( int $workflow_id, string $name, array $final_state ): void {
		self::fire( 'queuety_workflow_completed', $workflow_id, $name, $final_state );
	}

	/**
	 * Fire the workflow_failed hook.
	 *
	 * @param int        $workflow_id The workflow ID.
	 * @param string     $name        Workflow name.
	 * @param int        $failed_step The step that failed.
	 * @param \Throwable $exception   The exception that caused the failure.
	 */
	public static function workflow_failed( int $workflow_id, string $name, int $failed_step, \Throwable $exception ): void {
		self::fire( 'queuety_workflow_failed', $workflow_id, $name, $failed_step, $exception );
	}

	/**
	 * Fire the worker_started hook.
	 *
	 * @param int $pid Worker process ID.
	 */
	public static function worker_started( int $pid ): void {
		self::fire( 'queuety_worker_started', $pid );
	}

	/**
	 * Fire the worker_stopped hook.
	 *
	 * @param int    $pid    Worker process ID.
	 * @param string $reason Reason the worker stopped.
	 */
	public static function worker_stopped( int $pid, string $reason ): void {
		self::fire( 'queuety_worker_stopped', $pid, $reason );
	}
}
