<?php
/**
 * Pending schedule builder.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\ExpressionType;

/**
 * Fluent builder for adding a recurring schedule.
 *
 * Auto-dispatches in the destructor if not already dispatched.
 *
 * @example
 * Queuety::schedule('cleanup_handler')
 *     ->every('1 hour')
 *     ->on_queue('maintenance');
 */
class PendingSchedule {

	/**
	 * Schedule expression (cron or interval string).
	 *
	 * @var string|null
	 */
	private ?string $expression = null;

	/**
	 * Expression type.
	 *
	 * @var ExpressionType|null
	 */
	private ?ExpressionType $expression_type = null;

	/**
	 * Target queue name.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Whether the schedule has been dispatched.
	 *
	 * @var bool
	 */
	private bool $dispatched = false;

	/**
	 * The dispatched schedule ID.
	 *
	 * @var int|null
	 */
	private ?int $schedule_id = null;

	/**
	 * Constructor.
	 *
	 * @param string    $handler   Handler name or class.
	 * @param array     $payload   Job payload.
	 * @param Scheduler $scheduler Scheduler instance.
	 */
	public function __construct(
		private readonly string $handler,
		private readonly array $payload,
		private readonly Scheduler $scheduler,
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
	 * Set an interval expression (e.g. '1 hour', '30 minutes').
	 *
	 * @param string $interval Interval string compatible with DateTimeImmutable::modify().
	 * @return self
	 */
	public function every( string $interval ): self {
		$this->expression      = $interval;
		$this->expression_type = ExpressionType::Interval;
		return $this;
	}

	/**
	 * Set a cron expression (e.g. '0 3 * * *').
	 *
	 * @param string $expression 5-field cron expression.
	 * @return self
	 */
	public function cron( string $expression ): self {
		$this->expression      = $expression;
		$this->expression_type = ExpressionType::Cron;
		return $this;
	}

	/**
	 * Set the target queue.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 */
	public function on_queue( string $queue ): self {
		$this->queue = $queue;
		return $this;
	}

	/**
	 * Get the dispatched schedule ID. Forces dispatch if not yet done.
	 *
	 * @return int
	 */
	public function id(): int {
		if ( ! $this->dispatched ) {
			$this->do_dispatch();
		}
		return $this->schedule_id;
	}

	/**
	 * Perform the actual schedule dispatch.
	 *
	 * @throws \RuntimeException If no expression has been set.
	 */
	private function do_dispatch(): void {
		if ( null === $this->expression || null === $this->expression_type ) {
			throw new \RuntimeException( 'Schedule expression not set. Call every() or cron() before dispatching.' );
		}

		$this->schedule_id = $this->scheduler->add(
			handler: $this->handler,
			payload: $this->payload,
			queue: $this->queue,
			expression: $this->expression,
			type: $this->expression_type,
		);
		$this->dispatched  = true;
	}
}
