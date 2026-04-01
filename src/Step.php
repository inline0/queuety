<?php
/**
 * Workflow step handler interface.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Interface for workflow step handlers.
 *
 * Step handlers receive the accumulated workflow state and return
 * data that gets merged into the state for subsequent steps.
 *
 * @example
 * class FetchDataHandler implements Step {
 *     public function handle( array $state ): array {
 *         $user = get_user_by( 'ID', $state['user_id'] );
 *         return [ 'user_name' => $user->display_name ];
 *     }
 *     public function config(): array {
 *         return [ 'max_attempts' => 5 ];
 *     }
 * }
 */
interface Step {

	/**
	 * Execute the step.
	 *
	 * @param array $state Accumulated workflow state from all previous steps.
	 * @return array Data to merge into the workflow state.
	 */
	public function handle( array $state ): array;

	/**
	 * Optional step configuration.
	 *
	 * Supported keys: max_attempts, backoff, concurrency_group, concurrency_limit, cost_units.
	 *
	 * @return array Configuration array.
	 */
	public function config(): array;
}
