<?php
/**
 * Test fixture: simple job for batch testing.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * Processes an image and appends its ID to a shared temp file.
 */
class ProcessImageJob implements Job {

	use Dispatchable;

	/**
	 * Constructor.
	 *
	 * @param int $image_id Image ID to process.
	 */
	public function __construct(
		public readonly int $image_id,
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		$file = sys_get_temp_dir() . '/queuety_test_images.json';
		$data = file_exists( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
		$data[] = $this->image_id;
		file_put_contents( $file, json_encode( $data ) );
	}
}
