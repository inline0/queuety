<?php
/**
 * Test fixture: batch 'then' callback handler.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Batch;

/**
 * Handler called when a batch completes successfully.
 */
class BatchThenHandler {

	/**
	 * Received batch objects.
	 *
	 * @var Batch[]
	 */
	public static array $calls = array();

	/**
	 * Handle batch completion.
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
