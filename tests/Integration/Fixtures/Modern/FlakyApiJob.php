<?php
/**
 * Test fixture: job that fails N times then succeeds.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * Simulates a flaky API that fails a configurable number of times before succeeding.
 */
class FlakyApiJob implements Job {

	use Dispatchable;

	public int $tries = 5;
	public array $backoff = array( 1, 5, 10 );

	/**
	 * Constructor.
	 *
	 * @param string $api_key    Unique key for this job's attempt tracker.
	 * @param int    $fail_times Number of times the job should fail before succeeding.
	 */
	public function __construct(
		public readonly string $api_key,
		public readonly int $fail_times = 2,
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		$file     = sys_get_temp_dir() . "/queuety_test_flaky_{$this->api_key}.txt";
		$attempts = file_exists( $file ) ? (int) file_get_contents( $file ) : 0;
		++$attempts;
		file_put_contents( $file, $attempts );

		if ( $attempts <= $this->fail_times ) {
			throw new \RuntimeException( "API call failed (attempt {$attempts})" );
		}
	}

	/**
	 * Called when the job permanently fails.
	 *
	 * @param \Throwable $e The exception that caused the failure.
	 */
	public function failed( \Throwable $e ): void {
		file_put_contents(
			sys_get_temp_dir() . "/queuety_test_flaky_{$this->api_key}_failed.txt",
			$e->getMessage(),
		);
	}
}
