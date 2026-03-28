<?php
/**
 * Workflow builder for defining multi-step workflows.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;

/**
 * Fluent builder for defining and dispatching workflows.
 *
 * @example
 * Queuety::workflow('generate_report')
 *     ->then(FetchDataHandler::class)
 *     ->then(CallLLMHandler::class)
 *     ->then(FormatOutputHandler::class)
 *     ->dispatch(['user_id' => 42]);
 */
class WorkflowBuilder {

	/**
	 * Step handler class names in order.
	 *
	 * @var string[]
	 */
	private array $steps = array();

	/**
	 * Target queue name.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Priority level for all steps.
	 *
	 * @var Priority
	 */
	private Priority $priority = Priority::Low;

	/**
	 * Maximum retry attempts per step.
	 *
	 * @var int
	 */
	private int $max_attempts = 3;

	/**
	 * Constructor.
	 *
	 * @param string     $name      Workflow name.
	 * @param Connection $conn      Database connection.
	 * @param Queue      $queue_ops Queue operations instance.
	 * @param Logger     $logger    Logger instance.
	 */
	public function __construct(
		private readonly string $name,
		private readonly Connection $conn,
		private readonly Queue $queue_ops,
		private readonly Logger $logger,
	) {}

	/**
	 * Add a step to the workflow.
	 *
	 * @param string $handler_class Fully qualified class name implementing Step.
	 * @return self
	 */
	public function then( string $handler_class ): self {
		$this->steps[] = $handler_class;
		return $this;
	}

	/**
	 * Set the queue for all steps.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 */
	public function on_queue( string $queue ): self {
		$this->queue = $queue;
		return $this;
	}

	/**
	 * Set the priority for all steps.
	 *
	 * @param Priority $priority Priority level.
	 * @return self
	 */
	public function with_priority( Priority $priority ): self {
		$this->priority = $priority;
		return $this;
	}

	/**
	 * Set the max attempts per step.
	 *
	 * @param int $max Maximum attempts.
	 * @return self
	 */
	public function max_attempts( int $max ): self {
		$this->max_attempts = $max;
		return $this;
	}

	/**
	 * Dispatch the workflow. Creates the workflow row and enqueues the first step.
	 *
	 * @param array $initial_payload Starting state for the workflow.
	 * @return int The workflow ID.
	 * @throws \RuntimeException If the workflow has no steps.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function dispatch( array $initial_payload = array() ): int {
		if ( empty( $this->steps ) ) {
			throw new \RuntimeException( 'Workflow must have at least one step.' );
		}

		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		// Store step definitions in the state under a reserved key.
		$state                  = $initial_payload;
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

			// Enqueue the first step.
			$this->queue_ops->dispatch(
				handler: $this->steps[0],
				payload: array(),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: 0,
			);

			$this->logger->log(
				LogEvent::WorkflowStarted,
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
}
