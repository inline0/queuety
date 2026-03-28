<?php
/**
 * Test fixture: simple job for chain/batch tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * A simple job that records its execution order.
 */
class SimpleJob implements Job {

	use Dispatchable;

	/**
	 * Execution log with order tracking.
	 *
	 * @var array
	 */
	public static array $log = array();

	/**
	 * Constructor.
	 *
	 * @param string $label Identifier for this job.
	 */
	public function __construct(
		public readonly string $label = 'default',
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		self::$log[] = $this->label;
	}

	/**
	 * Reset state.
	 */
	public static function reset(): void {
		self::$log = array();
	}
}
