<?php
/**
 * Test fixture: batch callback handler.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Batch;

/**
 * A callback handler for batch lifecycle tests.
 */
class BatchCallbackHandler {

	/**
	 * Received batch objects per callback type.
	 *
	 * @var array<string, Batch[]>
	 */
	public static array $calls = array(
		'then'    => array(),
		'catch'   => array(),
		'finally' => array(),
	);

	/**
	 * Handle a batch callback.
	 *
	 * @param Batch $batch The batch object.
	 */
	public function handle( Batch $batch ): void {
		// Determine which callback type by checking the batch options.
		// The BatchManager calls this handler for whichever callback is configured.
		// We record all calls generically.
		self::$calls['generic'][] = $batch;
	}

	/**
	 * Reset state.
	 */
	public static function reset(): void {
		self::$calls = array(
			'then'    => array(),
			'catch'   => array(),
			'finally' => array(),
			'generic' => array(),
		);
	}
}
