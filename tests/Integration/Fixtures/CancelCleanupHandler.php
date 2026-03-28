<?php

namespace Queuety\Tests\Integration\Fixtures;

/**
 * Test fixture that records cleanup was called on workflow cancellation.
 */
class CancelCleanupHandler {

	/**
	 * Whether handle() was called.
	 *
	 * @var bool
	 */
	public static bool $called = false;

	/**
	 * The state passed to handle().
	 *
	 * @var array
	 */
	public static array $received_state = array();

	/**
	 * Handle cancellation cleanup.
	 *
	 * @param array $state Current public workflow state.
	 */
	public function handle( array $state ): void {
		self::$called         = true;
		self::$received_state = $state;
	}

	/**
	 * Reset static state for clean tests.
	 */
	public static function reset(): void {
		self::$called         = false;
		self::$received_state = array();
	}
}
