<?php
/**
 * Queue driver that records dispatches without touching the database.
 *
 * @package Queuety
 */

namespace Queuety\Testing;

use Queuety\Enums\Priority;
use Queuety\Queue;

/**
 * Minimal queue implementation for facade fakes.
 */
class FakeQueue extends Queue {

	/**
	 * Next synthetic job ID.
	 *
	 * @var int
	 */
	private int $next_job_id = 1;

	/**
	 * Constructor.
	 *
	 * @param QueueFake $fake Recorder instance.
	 */
	public function __construct(
		private readonly QueueFake $fake,
	) {}

	/**
	 * Get the underlying recorder.
	 *
	 * @return QueueFake
	 */
	public function recorder(): QueueFake {
		return $this->fake;
	}

	/**
	 * Record a synthetic dispatch and return a fake job ID.
	 *
	 * @param string        $handler      Handler name or class.
	 * @param array         $payload      Job payload.
	 * @param string        $queue        Queue name.
	 * @param Priority      $priority     Job priority.
	 * @param int           $delay        Delay before availability.
	 * @param int           $max_attempts Maximum attempts.
	 * @param int|null      $workflow_id  Parent workflow ID.
	 * @param int|null      $step_index   Step index within the workflow.
	 * @param bool          $unique       Whether the job is unique.
	 * @param int|null      $depends_on   Dependency job ID.
	 * @param int|null      $batch_id     Parent batch ID.
	 * @return int
	 */
	public function dispatch(
		string $handler,
		array $payload = array(),
		string $queue = 'default',
		Priority $priority = Priority::Low,
		int $delay = 0,
		int $max_attempts = 3,
		?int $workflow_id = null,
		?int $step_index = null,
		bool $unique = false,
		?int $depends_on = null,
		?int $batch_id = null,
	): int {
		unset( $priority, $delay, $max_attempts, $workflow_id, $step_index, $unique, $depends_on, $batch_id );

		$this->fake->push( $handler, $payload, $queue );

		return $this->next_job_id++;
	}

	/**
	 * Record a synthetic multi-dispatch.
	 *
	 * @param array $jobs Job definitions.
	 * @return int[]
	 */
	public function batch( array $jobs ): array {
		$ids = array();

		foreach ( $jobs as $job ) {
			$ids[] = $this->dispatch(
				handler: $job['handler'],
				payload: $job['payload'] ?? array(),
				queue: $job['queue'] ?? 'default',
				priority: $job['priority'] ?? Priority::Low,
				delay: $job['delay'] ?? 0,
				max_attempts: $job['max_attempts'] ?? 3,
			);
		}

		return $ids;
	}
}
