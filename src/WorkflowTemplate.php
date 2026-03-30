<?php
/**
 * Workflow template for reusable workflow definitions.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\Priority;

/**
 * Stores a named workflow template that can be dispatched multiple times.
 */
readonly class WorkflowTemplate {

	/**
	 * Constructor.
	 *
	 * @param string   $name         Template name.
	 * @param array    $steps        Step definitions (from WorkflowBuilder::build_steps()).
	 * @param string   $queue        Queue name.
	 * @param Priority $priority     Priority level.
	 * @param int      $max_attempts Maximum retry attempts per step.
	 * @param array    $definition   Full runtime workflow definition bundle.
	 */
	public function __construct(
		public string $name,
		public array $steps,
		public string $queue,
		public Priority $priority,
		public int $max_attempts,
		public array $definition,
	) {}

	/**
	 * Dispatch a new workflow instance from this template.
	 *
	 * @param array $payload Initial payload/state for the workflow.
	 * @param array $options Per-dispatch options like idempotency_key.
	 * @return int The workflow ID.
	 * @throws \RuntimeException If Queuety is not initialized.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function dispatch( array $payload = array(), array $options = array() ): int {
		return Queuety::dispatch_workflow_definition( $this->definition, $payload, $options );
	}
}
