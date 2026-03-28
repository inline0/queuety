<?php
/**
 * Pending job builder.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\Priority;

/**
 * Fluent builder for dispatching a job with options.
 *
 * Auto-dispatches in the destructor if not already dispatched.
 *
 * @example
 * Queuety::dispatch('send_email', ['to' => 'user@example.com'])
 *     ->onQueue('emails')
 *     ->withPriority(Priority::High)
 *     ->delay(300);
 */
class PendingJob {

	/**
	 * Target queue name.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Job priority level.
	 *
	 * @var Priority
	 */
	private Priority $priority = Priority::Low;

	/**
	 * Delay in seconds before the job becomes available.
	 *
	 * @var int
	 */
	private int $delay = 0;

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	private int $max_attempts = 3;

	/**
	 * Rate limit configuration: [max_executions, window_seconds].
	 *
	 * @var array{int, int}|null
	 */
	private ?array $rate_limit_config = null;

	/**
	 * Whether this job should be unique (no duplicates).
	 *
	 * @var bool
	 */
	private bool $unique = false;

	/**
	 * ID of a job this job depends on (must complete first).
	 *
	 * @var int|null
	 */
	private ?int $depends_on = null;

	/**
	 * Whether the job has been dispatched.
	 *
	 * @var bool
	 */
	private bool $dispatched = false;

	/**
	 * The dispatched job ID.
	 *
	 * @var int|null
	 */
	private ?int $job_id = null;

	/**
	 * Constructor.
	 *
	 * @param string $handler   Handler name or class.
	 * @param array  $payload   Job payload.
	 * @param Queue  $queue_ops Queue operations instance.
	 */
	public function __construct(
		private readonly string $handler,
		private readonly array $payload,
		private readonly Queue $queue_ops,
	) {}

	/**
	 * Auto-dispatch on destruction if not already dispatched.
	 */
	public function __destruct() {
		if ( ! $this->dispatched ) {
			$this->do_dispatch();
		}
	}

	/**
	 * Set the queue.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 */
	public function on_queue( string $queue ): self {
		$this->queue = $queue;
		return $this;
	}

	/**
	 * Set the priority.
	 *
	 * @param Priority $priority Priority level.
	 * @return self
	 */
	public function with_priority( Priority $priority ): self {
		$this->priority = $priority;
		return $this;
	}

	/**
	 * Set a delay before the job becomes available.
	 *
	 * @param int $seconds Delay in seconds.
	 * @return self
	 */
	public function delay( int $seconds ): self {
		$this->delay = $seconds;
		return $this;
	}

	/**
	 * Set the maximum retry attempts.
	 *
	 * @param int $max Maximum attempts.
	 * @return self
	 */
	public function max_attempts( int $max ): self {
		$this->max_attempts = $max;
		return $this;
	}

	/**
	 * Set a rate limit for this handler.
	 *
	 * Registers the rate limit with the RateLimiter via the Queuety facade
	 * so that the worker enforces the limit when processing jobs.
	 *
	 * @param int $max    Maximum executions allowed in the window.
	 * @param int $window Window duration in seconds.
	 * @return self
	 */
	public function rate_limit( int $max, int $window ): self {
		$this->rate_limit_config = array( $max, $window );
		return $this;
	}

	/**
	 * Mark this job as unique to prevent duplicate dispatch.
	 *
	 * When unique, dispatching a job with the same handler and payload
	 * that already exists as pending or processing will return the
	 * existing job ID instead of creating a new one.
	 *
	 * @return self
	 */
	public function unique(): self {
		$this->unique = true;
		return $this;
	}

	/**
	 * Set a job dependency. This job will not be claimed until the
	 * specified job has completed.
	 *
	 * @param int $job_id ID of the job that must complete first.
	 * @return self
	 */
	public function after( int $job_id ): self {
		$this->depends_on = $job_id;
		return $this;
	}

	/**
	 * Get the dispatched job ID. Forces dispatch if not yet done.
	 *
	 * @return int
	 */
	public function id(): int {
		if ( ! $this->dispatched ) {
			$this->do_dispatch();
		}
		return $this->job_id;
	}

	/**
	 * Perform the actual job dispatch.
	 */
	private function do_dispatch(): void {
		$this->job_id     = $this->queue_ops->dispatch(
			handler: $this->handler,
			payload: $this->payload,
			queue: $this->queue,
			priority: $this->priority,
			delay: $this->delay,
			max_attempts: $this->max_attempts,
			unique: $this->unique,
			depends_on: $this->depends_on,
		);
		$this->dispatched = true;

		// Register rate limit with the facade if configured.
		if ( null !== $this->rate_limit_config ) {
			try {
				Queuety::rate_limiter()->register(
					$this->handler,
					$this->rate_limit_config[0],
					$this->rate_limit_config[1],
				);
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Facade may not be initialized.
				unset( $e );
			}
		}
	}
}
