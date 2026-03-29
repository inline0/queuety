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
	 * Step definitions in order.
	 *
	 * Each entry is an array with keys: type, class (for single), handlers (for parallel),
	 * name (optional), builder (for sub_workflow).
	 *
	 * @var array[]
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
	 * Cancellation cleanup handler class.
	 *
	 * @var string|null
	 */
	private ?string $cancel_handler = null;

	/**
	 * Number of steps after which old state data is pruned.
	 *
	 * @var int|null
	 */
	private ?int $prune_after = null;

	/**
	 * Deadline duration in seconds for the entire workflow.
	 *
	 * @var int|null
	 */
	private ?int $deadline_seconds = null;

	/**
	 * Handler class to call when the workflow deadline is exceeded.
	 *
	 * @var string|null
	 */
	private ?string $deadline_handler = null;

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
	 * @param string      $handler_class Fully qualified class name implementing Step.
	 * @param string|null $name          Optional step name for conditional branching.
	 * @return self
	 */
	public function then( string $handler_class, ?string $name = null ): self {
		$index         = count( $this->steps );
		$this->steps[] = array(
			'type'  => 'single',
			'class' => $handler_class,
			'name'  => $name ?? (string) $index,
		);
		return $this;
	}

	/**
	 * Add a parallel step group to the workflow.
	 *
	 * All handlers in the group run concurrently. The workflow advances
	 * only when all parallel jobs complete.
	 *
	 * @param array $handler_classes Array of fully qualified class names implementing Step.
	 * @return self
	 */
	public function parallel( array $handler_classes ): self {
		$index         = count( $this->steps );
		$this->steps[] = array(
			'type'     => 'parallel',
			'handlers' => array_values( $handler_classes ),
			'name'     => (string) $index,
		);
		return $this;
	}

	/**
	 * Add a sub-workflow step to the workflow.
	 *
	 * When this step is reached, the sub-workflow is dispatched and the parent
	 * pauses until the sub-workflow completes.
	 *
	 * @param string          $sub_name    Name for the sub-workflow.
	 * @param WorkflowBuilder $sub_builder Builder defining the sub-workflow steps.
	 * @return self
	 */
	public function sub_workflow( string $sub_name, WorkflowBuilder $sub_builder ): self {
		$index         = count( $this->steps );
		$this->steps[] = array(
			'type'     => 'sub_workflow',
			'name'     => (string) $index,
			'builder'  => $sub_builder,
			'sub_name' => $sub_name,
		);
		return $this;
	}

	/**
	 * Add a durable timer step to the workflow.
	 *
	 * The workflow will pause for the specified duration before continuing
	 * to the next step. The delay is implemented via the job's available_at
	 * column, making it durable across worker restarts.
	 *
	 * @param int $seconds Seconds to wait.
	 * @param int $minutes Minutes to wait.
	 * @param int $hours   Hours to wait.
	 * @param int $days    Days to wait.
	 * @return self
	 */
	public function sleep( int $seconds = 0, int $minutes = 0, int $hours = 0, int $days = 0 ): self {
		$total       = $seconds + ( $minutes * 60 ) + ( $hours * 3600 ) + ( $days * 86400 );
		$timer_count = 0;
		foreach ( $this->steps as $step ) {
			if ( 'timer' === $step['type'] ) {
				++$timer_count;
			}
		}
		$this->steps[] = array(
			'type'          => 'timer',
			'delay_seconds' => $total,
			'name'          => 'timer_' . $timer_count,
		);
		return $this;
	}

	/**
	 * Add a signal wait step to the workflow.
	 *
	 * When the workflow reaches this step, it pauses and waits for an
	 * external signal with the given name. If the signal has already been
	 * sent before the step is reached, the workflow continues immediately.
	 *
	 * @param string $name The signal name to wait for.
	 * @return self
	 */
	public function wait_for_signal( string $name ): self {
		$this->steps[] = array(
			'type'        => 'signal',
			'signal_name' => $name,
			'name'        => 'wait_for_' . $name,
		);
		return $this;
	}

	/**
	 * Register a cleanup handler class that runs when the workflow is cancelled.
	 *
	 * The handler class must implement a handle(array $state): void method.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function on_cancel( string $handler_class ): self {
		$this->cancel_handler = $handler_class;
		return $this;
	}

	/**
	 * Enable state pruning after a given number of steps.
	 *
	 * When enabled, step output keys from steps older than the given number
	 * are removed from the workflow state to keep it bounded.
	 * Reserved keys (starting with _) are never pruned.
	 *
	 * @param int $steps Number of steps to keep (default 2).
	 * @return self
	 */
	public function prune_state_after( int $steps = 2 ): self {
		$this->prune_after = $steps;
		return $this;
	}

	/**
	 * Set a deadline for the entire workflow.
	 *
	 * If the workflow is not completed within the specified duration,
	 * the deadline handler (if set) will be called and the workflow
	 * will be marked as failed.
	 *
	 * @param int $seconds Seconds.
	 * @param int $minutes Minutes.
	 * @param int $hours   Hours.
	 * @param int $days    Days.
	 * @return self
	 */
	public function must_complete_within( int $seconds = 0, int $minutes = 0, int $hours = 0, int $days = 0 ): self {
		$this->deadline_seconds = $seconds + ( $minutes * 60 ) + ( $hours * 3600 ) + ( $days * 86400 );
		return $this;
	}

	/**
	 * Register a handler class that is called when the workflow deadline is exceeded.
	 *
	 * The handler class must implement a handle(array $state): void method.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function on_deadline( string $handler_class ): self {
		$this->deadline_handler = $handler_class;
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
	 * Get the workflow name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get the queue name.
	 *
	 * @return string
	 */
	public function get_queue(): string {
		return $this->queue;
	}

	/**
	 * Get the priority.
	 *
	 * @return Priority
	 */
	public function get_priority(): Priority {
		return $this->priority;
	}

	/**
	 * Get the max attempts.
	 *
	 * @return int
	 */
	public function get_max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Build the serialisable step definitions array.
	 *
	 * Sub-workflow builders are converted to their serialisable form.
	 * The returned array is suitable for JSON-encoding in workflow state.
	 *
	 * @return array[]
	 */
	public function build_steps(): array {
		$result = array();
		foreach ( $this->steps as $step ) {
			if ( 'sub_workflow' === $step['type'] ) {
				/* @var WorkflowBuilder $builder Sub-workflow builder instance. */
				$builder  = $step['builder'];
				$result[] = array(
					'type'             => 'sub_workflow',
					'name'             => $step['name'],
					'sub_name'         => $step['sub_name'],
					'sub_steps'        => $builder->build_steps(),
					'sub_queue'        => $builder->get_queue(),
					'sub_priority'     => $builder->get_priority()->value,
					'sub_max_attempts' => $builder->get_max_attempts(),
				);
			} elseif ( 'timer' === $step['type'] ) {
				$result[] = array(
					'type'          => 'timer',
					'name'          => $step['name'],
					'delay_seconds' => $step['delay_seconds'],
				);
			} elseif ( 'signal' === $step['type'] ) {
				$result[] = array(
					'type'        => 'signal',
					'name'        => $step['name'],
					'signal_name' => $step['signal_name'],
				);
			} else {
				$entry = array(
					'type' => $step['type'],
					'name' => $step['name'],
				);
				if ( 'single' === $step['type'] ) {
					$entry['class'] = $step['class'];
				} elseif ( 'parallel' === $step['type'] ) {
					$entry['handlers'] = $step['handlers'];
				}
				$result[] = $entry;
			}
		}
		return $result;
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

		$built_steps = $this->build_steps();

		// Store step definitions in the state under a reserved key.
		$state                  = $initial_payload;
		$state['_steps']        = $built_steps;
		$state['_queue']        = $this->queue;
		$state['_priority']     = $this->priority->value;
		$state['_max_attempts'] = $this->max_attempts;

		if ( null !== $this->cancel_handler ) {
			$state['_on_cancel'] = $this->cancel_handler;
		}

		if ( null !== $this->prune_after ) {
			$state['_prune_state_after'] = $this->prune_after;
			$state['_step_outputs']      = array();
		}

		if ( null !== $this->deadline_seconds ) {
			$state['_deadline_seconds'] = $this->deadline_seconds;
		}

		if ( null !== $this->deadline_handler ) {
			$state['_on_deadline'] = $this->deadline_handler;
		}

		$deadline_at = null;
		if ( null !== $this->deadline_seconds ) {
			$deadline_at = gmdate( 'Y-m-d H:i:s', time() + $this->deadline_seconds );
		}

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"INSERT INTO {$wf_tbl} (name, status, state, current_step, total_steps, deadline_at)
				VALUES (:name, 'running', :state, 0, :total_steps, :deadline_at)"
			);
			$stmt->execute(
				array(
					'name'        => $this->name,
					'state'       => json_encode( $state, JSON_THROW_ON_ERROR ),
					'total_steps' => count( $built_steps ),
					'deadline_at' => $deadline_at,
				)
			);
			$workflow_id = (int) $pdo->lastInsertId();

			// Enqueue the first step.
			$first_step = $built_steps[0];
			$this->enqueue_step( $first_step, $workflow_id, 0 );

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

	/**
	 * Enqueue a step definition as one or more jobs.
	 *
	 * @param array $step_def    The step definition from build_steps().
	 * @param int   $workflow_id The workflow ID.
	 * @param int   $step_index  The step index.
	 */
	private function enqueue_step( array $step_def, int $workflow_id, int $step_index ): void {
		if ( 'parallel' === $step_def['type'] ) {
			foreach ( $step_def['handlers'] as $handler_class ) {
				$handler_defaults = HandlerMetadata::from_class( $handler_class );
				$this->queue_ops->dispatch(
					handler: $handler_class,
					payload: array(),
					queue: $this->queue,
					priority: $this->priority,
					max_attempts: $handler_defaults['max_attempts'] ?? $this->max_attempts,
					workflow_id: $workflow_id,
					step_index: $step_index,
				);
			}
		} elseif ( 'sub_workflow' === $step_def['type'] ) {
			// Sub-workflows are dispatched by Workflow::advance_step when reached,
			// not at build time. Dispatch a placeholder handler that triggers the sub-workflow.
			// Actually, for the first step, if it's a sub_workflow, we handle it in Workflow.
			$this->queue_ops->dispatch(
				handler: '__queuety_sub_workflow',
				payload: array( 'step_index' => $step_index ),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'timer' === $step_def['type'] ) {
			$this->queue_ops->dispatch(
				handler: '__queuety_timer',
				payload: array( 'step_index' => $step_index ),
				queue: $this->queue,
				priority: $this->priority,
				delay: $step_def['delay_seconds'] ?? 0,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} elseif ( 'signal' === $step_def['type'] ) {
			// Signal steps are handled by Workflow::enqueue_step_def.
			// When dispatched from the builder (first step), delegate to Workflow logic
			// by dispatching a placeholder that Workflow will interpret.
			$this->queue_ops->dispatch(
				handler: '__queuety_signal',
				payload: array( 'step_index' => $step_index ),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		} else {
			// Single step.
			$handler_defaults = HandlerMetadata::from_class( $step_def['class'] );
			$this->queue_ops->dispatch(
				handler: $step_def['class'],
				payload: array(),
				queue: $this->queue,
				priority: $this->priority,
				max_attempts: $handler_defaults['max_attempts'] ?? $this->max_attempts,
				workflow_id: $workflow_id,
				step_index: $step_index,
			);
		}
	}
}
