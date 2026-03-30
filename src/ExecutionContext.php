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
	 * Active execution frame.
	 *
	 * @var array<string,int|null>|null
	 */
	private static ?array $frame = null;

	/**
	 * Store the active execution frame.
	 *
	 * @param int      $job_id       Current job ID.
	 * @param int|null $workflow_id  Current workflow ID, if any.
	 * @param int|null $step_index   Current workflow step index, if any.
	 */
	public static function enter( int $job_id, ?int $workflow_id = null, ?int $step_index = null ): void {
		self::$frame = array(
			'job_id'      => $job_id,
			'workflow_id' => $workflow_id,
			'step_index'  => $step_index,
		);
	}

	/**
	 * Clear the active execution frame.
	 */
	public static function clear(): void {
		self::$frame = null;
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
}
