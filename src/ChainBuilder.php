<?php
/**
 * Chain builder for sequential job execution.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Job as JobContract;

/**
 * Fluent builder for creating a chain of sequential jobs.
 *
 * Each job in the chain depends on the previous one completing successfully.
 * If any job fails, subsequent jobs remain pending (blocked by depends_on).
 *
 * @example
 * Queuety::chain([
 *     new FetchDataJob( $url ),
 *     new ProcessDataJob(),
 *     new NotifyCompleteJob(),
 * ])->on_queue('pipeline')->dispatch();
 */
class ChainBuilder {

	/**
	 * Target queue name.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Handler class called on failure.
	 *
	 * @var string|null
	 */
	private ?string $catch_handler = null;

	/**
	 * Constructor.
	 *
	 * @param array $jobs     Array of Contracts\Job instances.
	 * @param Queue $queue_ops Queue operations instance.
	 */
	public function __construct(
		private readonly array $jobs,
		private readonly Queue $queue_ops,
	) {}

	/**
	 * Set the queue for all jobs in the chain.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 */
	public function on_queue( string $queue ): self {
		$this->queue = $queue;
		return $this;
	}

	/**
	 * Set the handler to call on failure.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return self
	 */
	public function catch( string $handler_class ): self {
		$this->catch_handler = $handler_class;
		return $this;
	}

	/**
	 * Dispatch the chain: create all jobs with sequential depends_on.
	 *
	 * Returns the ID of the first job in the chain.
	 *
	 * @return int The first job ID.
	 */
	public function dispatch(): int {
		$previous_id = null;

		foreach ( $this->jobs as $job ) {
			if ( $job instanceof JobContract ) {
				$serialized = JobSerializer::serialize( $job );
				$handler    = $serialized['handler'];
				$payload    = $serialized['payload'];
			} elseif ( is_array( $job ) && isset( $job['handler'] ) ) {
				$handler = $job['handler'];
				$payload = $job['payload'] ?? array();
			} else {
				continue;
			}

			// Add catch handler info to payload metadata if configured.
			if ( null !== $this->catch_handler ) {
				$payload['__chain_catch'] = $this->catch_handler;
			}

			$job_id = $this->queue_ops->dispatch(
				handler: $handler,
				payload: $payload,
				queue: $this->queue,
				depends_on: $previous_id,
			);

			if ( null === $previous_id ) {
				$first_id = $job_id;
			}

			$previous_id = $job_id;
		}

		return $first_id ?? 0;
	}
}
