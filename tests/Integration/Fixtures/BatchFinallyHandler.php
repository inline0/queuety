<?php
/**
 * Test fixture: batch 'finally' callback handler.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Batch;

/**
 * Handler called when a batch finishes (success or failure).
 */
class BatchFinallyHandler {

	/**
	 * Received batch objects.
	 *
	 * @var Batch[]
	 */
	public static array $calls = array();

	/**
	 * Handle batch finish.
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
