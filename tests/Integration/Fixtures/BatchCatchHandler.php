<?php
/**
 * Test fixture: batch 'catch' callback handler.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Batch;

/**
 * Handler called when a batch job fails.
 */
class BatchCatchHandler {

	/**
	 * Received batch objects.
	 *
	 * @var Batch[]
	 */
	public static array $calls = array();

	/**
	 * Handle batch failure.
	 *
	 * @param Batch $batch The batch object.
	 */
	public function handle( Batch $batch ): void {
		self::$calls[] = $batch;
	}

	/**
	 * Reset state.
	 */
	public static function reset(): void {
		self::$calls = array();
	}
}
