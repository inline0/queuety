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
	 * @param int|null       $parent_workflow_id Parent workflow ID, if this is a run-workflow.
	 * @param int|null       $parent_step_index  Parent step index, if this is a run-workflow.
	 * @param string|null    $wait_type          Wait primitive currently blocking the workflow, if any.
	 * @param array|null     $waiting_for        Wait targets currently blocking the workflow, if any.
	 * @param string|null    $definition_version Application-level workflow definition version, if set.
	 * @param string|null    $definition_hash    Deterministic hash of the workflow definition.
	 * @param string|null    $idempotency_key    Durable dispatch key, if set.
	 * @param array|null     $budget             Public budget summary for the run, if configured.
	 * @param string|null    $current_step_name  Current step name, if available.
	 * @param string|null    $wait_mode          Wait mode currently blocking the workflow, if any.
	 * @param array|null     $wait_details       Additional wait details for inspection, if any.
	 * @param int|null       $artifact_count     Number of stored artifacts, if available.
	 * @param array|null     $artifact_keys      Stored artifact keys, if available.
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
		public ?string $definition_version = null,
		public ?string $definition_hash = null,
		public ?string $idempotency_key = null,
		public ?array $budget = null,
		public ?string $current_step_name = null,
		public ?string $wait_mode = null,
		public ?array $wait_details = null,
		public ?int $artifact_count = null,
		public ?array $artifact_keys = null,
	) {}
}
