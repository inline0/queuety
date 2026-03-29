<?php
/**
 * Workflow compensation contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Runs a compensating action for a previously completed workflow step.
 */
interface Compensation {

	/**
	 * Execute the compensating action.
	 *
	 * @param array $state Public workflow state snapshot captured after the step completed.
	 */
	public function handle( array $state ): void;
}
