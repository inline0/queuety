<?php
/**
 * Batch builder for fluent batch creation.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Job as JobContract;
use Queuety\Enums\Priority;
use Queuety\Testing\FakeQueue;

/**
 * Fluent builder for creating and dispatching job batches.
 *
 * @example
 * Queuety::batch([new Job1(), new Job2()])
 *     ->name('Import users')
 *     ->then(ImportCompleteHandler::class)
 *     ->catch(ImportFailedHandler::class)
 *     ->finally(ImportCleanupHandler::class)
 *     ->dispatch();
 */
class BatchBuilder {

	/**
	 * Optional batch name.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * Target queue name.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Handler class called when the batch completes successfully.
	 *
	 * @var string|null
	 */
	private ?string $then_handler = null;

	/**
	 * Handler class called when any job in the batch fails.
	 *
	 * @var string|null
	 */
	private ?string $catch_handler = null;

	/**
	 * Handler class called when the batch finishes (success or failure).
	 *
	 * @var string|null
	 */
	private ?string $finally_handler = null;

	/**
	 * Whether to allow failures without blocking the then callback.
	 *
	 * @var bool
	 */
	private bool $allow_failures = false;

	/**
	 * Constructor.
	 *
	 * @param array        $jobs          Array of Contracts\Job instances or handler+payload arrays.
	 * @param Queue        $queue_ops     Queue operations instance.
	 * @param BatchManager $batch_manager Batch manager instance.
	 */
	public function __construct(
		private readonly array $jobs,
		private readonly Queue $queue_ops,
		private readonly BatchManager $batch_manager,
	) {}

	/**
	 * Set a name for the batch.
	 *
	 * @param string $name Batch name.
	 * @return self
	 */
	public function name( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * Set the queue for all jobs in the batch.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 */
	public function on_queue( string $queue ): self {
		$this->queue = $queue;
		return $this;
	}

	/**
	 * Set the handler to call when the batch completes successfully.
	 *
	 * The handler class must have a handle(Batch $batch) method.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function then( string $handler_class ): self {
		$this->then_handler = $handler_class;
		return $this;
	}

	/**
	 * Set the handler to call when any job in the batch fails.
	 *
	 * The handler class must have a handle(Batch $batch) method.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function catch( string $handler_class ): self {
		$this->catch_handler = $handler_class;
		return $this;
	}

	/**
	 * Set the handler to call when the batch finishes (success or failure).
	 *
	 * The handler class must have a handle(Batch $batch) method.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function finally( string $handler_class ): self {
		$this->finally_handler = $handler_class;
		return $this;
	}

	/**
	 * Allow failures without blocking the then callback.
	 *
	 * @return self
	 */
	public function allow_failures(): self {
		$this->allow_failures = true;
		return $this;
	}

	/**
	 * Dispatch the batch: create the batch row and all jobs.
	 *
	 * @return Batch The created batch.
	 */
	public function dispatch(): Batch {
		$options = array();

		if ( null !== $this->then_handler ) {
			$options['then'] = $this->then_handler;
		}
		if ( null !== $this->catch_handler ) {
			$options['catch'] = $this->catch_handler;
		}
		if ( null !== $this->finally_handler ) {
			$options['finally'] = $this->finally_handler;
		}
		if ( $this->allow_failures ) {
			$options['allow_failures'] = true;
		}

		if ( $this->queue_ops instanceof FakeQueue ) {
			$fake_options = $options;
			if ( null !== $this->name ) {
				$fake_options['name'] = $this->name;
			}

			$this->queue_ops->recorder()->push_batch( $this->jobs, $fake_options );
		}

		$batch_id = $this->batch_manager->create(
			total_jobs: count( $this->jobs ),
			name: $this->name,
			options: $options,
		);

		foreach ( $this->jobs as $job ) {
			if ( $job instanceof JobContract ) {
				$serialized = JobSerializer::serialize( $job );
				$this->queue_ops->dispatch(
					handler: $serialized['handler'],
					payload: $serialized['payload'],
					queue: $this->queue,
					batch_id: $batch_id,
				);
			} elseif ( is_array( $job ) && isset( $job['handler'] ) ) {
				$handler_defaults = $this->handler_defaults( $job['handler'] );

				$this->queue_ops->dispatch(
					handler: $job['handler'],
					payload: $job['payload'] ?? array(),
					queue: $job['queue'] ?? $this->queue,
					priority: $job['priority'] ?? Priority::Low,
					delay: $job['delay'] ?? 0,
					max_attempts: $job['max_attempts'] ?? ( $handler_defaults['max_attempts'] ?? 3 ),
					batch_id: $batch_id,
				);
			}
		}

		return $this->batch_manager->find( $batch_id );
	}

	/**
	 * Resolve handler defaults for batch array jobs.
	 *
	 * @param string $handler Handler alias or class.
	 * @return array{queue: string|null, max_attempts: int|null, backoff: string|array|null, rate_limit: array{int, int}|null}
	 */
	private function handler_defaults( string $handler ): array {
		$class = null;

		if ( class_exists( $handler ) ) {
			$class = $handler;
		} else {
			try {
				$class = Queuety::registry()->class_name( $handler );
			} catch ( \RuntimeException ) {
				$class = null;
			}
		}

		if ( null === $class ) {
			return HandlerMetadata::from_class( $handler );
		}

		return HandlerMetadata::from_class( $class );
	}
}
