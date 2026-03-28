<?php
/**
 * Test fixture: readonly job with typed properties and middleware.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;
use Queuety\Middleware\RateLimited;

/**
 * Fetches user data and writes to a temp file for verification.
 */
class FetchUserDataJob implements Job {

	use Dispatchable;

	public int $tries = 3;
	public int $timeout = 30;

	/**
	 * Constructor.
	 *
	 * @param int $user_id User ID to fetch.
	 */
	public function __construct(
		public readonly int $user_id,
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		$data = array(
			'user_id' => $this->user_id,
			'name'    => "User #{$this->user_id}",
			'email'   => "user{$this->user_id}@test.com",
		);
		file_put_contents(
			sys_get_temp_dir() . "/queuety_test_user_{$this->user_id}.json",
			json_encode( $data ),
		);
	}

	/**
	 * Middleware for this job.
	 *
	 * @return array
	 */
	public function middleware(): array {
		return array( new RateLimited( 100, 60 ) );
	}

	/**
	 * Called when the job permanently fails.
	 *
	 * @param \Throwable $e The exception that caused the failure.
	 */
	public function failed( \Throwable $e ): void {
		file_put_contents(
			sys_get_temp_dir() . "/queuety_test_failed_{$this->user_id}.txt",
			$e->getMessage(),
		);
	}
}
