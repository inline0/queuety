<?php
/**
 * Test fixture: failing job for batch/chain tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * A job that always fails.
 */
class FailingJob implements Job {

	use Dispatchable;

	/**
	 * Constructor.
	 *
	 * @param string $reason Failure reason.
	 */
	public function __construct(
		public readonly string $reason = 'intentional failure',
	) {}

	/**
	 * Execute the job (always fails).
	 */
	public function handle(): void {
		throw new \RuntimeException( $this->reason );
	}
}
