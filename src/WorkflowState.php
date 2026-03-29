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
	 * @param int            $workflow_id        The workflow ID.
	 * @param string         $name               The workflow name.
	 * @param WorkflowStatus $status             Current workflow status.
	 * @param int            $current_step       The current step index.
	 * @param int            $total_steps        Total number of steps.
	 * @param array          $state              Accumulated state data.
	 * @param int|null       $parent_workflow_id Parent workflow ID, if this is a sub-workflow.
	 * @param int|null       $parent_step_index  Parent step index, if this is a sub-workflow.
	 * @param string|null    $wait_type          Wait primitive currently blocking the workflow, if any.
	 * @param array|null     $waiting_for        Wait targets currently blocking the workflow, if any.
	 */
	public function __construct(
		public int $workflow_id,
		public string $name,
		public WorkflowStatus $status,
		public int $current_step,
		public int $total_steps,
		public array $state,
		public ?int $parent_workflow_id = null,
		public ?int $parent_step_index = null,
		public ?string $wait_type = null,
		public ?array $waiting_for = null,
	) {}
}
