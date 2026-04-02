<?php
/**
 * Worker-pool sizing policy.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Calculates the desired worker-pool size from backlog and capacity signals.
 */
class WorkerPoolScalePolicy {

	/**
	 * Calculate the desired worker count.
	 *
	 * @param int  $backlog             Claimable pending jobs.
	 * @param int  $current_workers     Current child-worker count.
	 * @param int  $min_workers         Minimum worker count.
	 * @param int  $max_workers         Maximum worker count.
	 * @param bool $can_scale_up        Whether capacity allows scale-up.
	 * @param bool $can_scale_down      Whether the pool may scale back down yet.
	 * @return int
	 */
	public static function target_worker_count(
		int $backlog,
		int $current_workers,
		int $min_workers,
		int $max_workers,
		bool $can_scale_up = true,
		bool $can_scale_down = true,
	): int {
		$target = max( $min_workers, min( $max_workers, max( $backlog, $min_workers ) ) );

		if ( ! $can_scale_up && $target > max( $current_workers, $min_workers ) ) {
			$target = max( $current_workers, $min_workers );
		}

		if ( ! $can_scale_down && $target < $current_workers ) {
			$target = $current_workers;
		}

		return $target;
	}
}
