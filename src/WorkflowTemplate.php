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
	 */
	public function __construct(
		public string $name,
		public array $steps,
		public string $queue,
		public Priority $priority,
		public int $max_attempts,
	) {}

	/**
	 * Dispatch a new workflow instance from this template.
	 *
	 * @param array $payload Initial payload/state for the workflow.
	 * @return int The workflow ID.
	 * @throws \RuntimeException If Queuety is not initialized.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function dispatch( array $payload = array() ): int {
		$builder = Queuety::workflow( $this->name );
		$builder->on_queue( $this->queue );
		$builder->with_priority( $this->priority );
		$builder->max_attempts( $this->max_attempts );

		return $this->dispatch_from_definitions( $payload );
	}

	/**
	 * Dispatch a workflow directly from stored step definitions.
	 *
	 * @param array $payload Initial payload/state.
	 * @return int The workflow ID.
	 * @throws \RuntimeException If Queuety is not initialized or steps are empty.
	 * @throws \Throwable If the database transaction fails.
	 */
	private function dispatch_from_definitions( array $payload ): int {
		if ( empty( $this->steps ) ) {
			throw new \RuntimeException( 'Workflow template must have at least one step.' );
		}

		$conn   = Queuety::connection();
		$queue  = Queuety::queue();
		$logger = Queuety::logger();

		$pdo    = $conn->pdo();
		$wf_tbl = $conn->table( Config::table_workflows() );

		$state                  = $payload;
		$state['_steps']        = $this->steps;
		$state['_queue']        = $this->queue;
		$state['_priority']     = $this->priority->value;
		$state['_max_attempts'] = $this->max_attempts;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"INSERT INTO {$wf_tbl} (name, status, state, current_step, total_steps)
				VALUES (:name, 'running', :state, 0, :total_steps)"
			);
			$stmt->execute(
				array(
					'name'        => $this->name,
					'state'       => json_encode( $state, JSON_THROW_ON_ERROR ),
					'total_steps' => count( $this->steps ),
				)
			);
			$workflow_id = (int) $pdo->lastInsertId();

			$first_step = $this->steps[0];
			$this->enqueue_step( $first_step, $workflow_id, 0, $queue );

			$logger->log(
				\Queuety\Enums\LogEvent::WorkflowStarted,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => $this->name,
					'queue'       => $this->queue,
				)
			);

			$pdo->commit();
			return $workflow_id;
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Enqueue a step from a step definition.
	 *
	 * @param array $step_def    Step definition.
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Step index.
	 * @param Queue $queue_ops   Queue operations instance.
	 */
	private function enqueue_step( array $step_def, int $workflow_id, int $step_index, Queue $queue_ops ): void {
		$type = $step_def['type'] ?? 'single';

		if ( 'parallel' === $type ) {
			foreach ( $step_def['handlers'] as $handler_class ) {
				$queue_ops->dispatch(
					handler: $handler_class,
					payload: array(),
					queue: $this->queue,
					priority: $this->priority,
					max_attempts: $this->max_attempts,
					workflow_id: $workflow_id,
					step_index: $step_index,
				);
			}
		} elseif ( 'sub_workflow' === $type ) {
			$queue_ops->dispatch(
				handler: '__queuety_sub_workflow',
				payload: array( 'step_index' => $step_index ),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'fan_out' === $type ) {
			$queue_ops->dispatch(
				handler: '__queuety_fan_out',
				payload: array( 'step_index' => $step_index ),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} else {
			$queue_ops->dispatch(
				handler: $step_def['class'],
				payload: array(),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		}
	}
}
