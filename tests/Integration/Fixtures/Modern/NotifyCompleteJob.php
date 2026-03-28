<?php
/**
 * Test fixture: records that it was called.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * Writes a message to a temp file to confirm execution.
 */
class NotifyCompleteJob implements Job {

	use Dispatchable;

	/**
	 * Constructor.
	 *
	 * @param string $message Message to write.
	 */
	public function __construct(
		public readonly string $message = 'done',
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		file_put_contents(
			sys_get_temp_dir() . '/queuety_test_notify.txt',
			$this->message,
		);
	}
}
