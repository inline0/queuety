<?php
/**
 * Workflow state value object.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\WorkflowStatus;

/**
 * Immutable value object representing a workflow's current state.
 */
readonly class WorkflowState {

	/**
	 * Constructor.
	 *
	 * @param int            $workflow_id  The workflow ID.
	 * @param string         $name         The workflow name.
	 * @param WorkflowStatus $status       Current workflow status.
	 * @param int            $current_step The current step index.
	 * @param int            $total_steps  Total number of steps.
	 * @param array          $state        Accumulated state data.
	 */
	public function __construct(
		public int $workflow_id,
		public string $name,
		public WorkflowStatus $status,
		public int $current_step,
		public int $total_steps,
		public array $state,
	) {}
}
