<?php
/**
 * Test fixture: job class with failed() hook.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * A job class that defines a failed() method for testing.
 */
class JobWithFailedHook implements Job {

	use Dispatchable;

	/**
	 * Exceptions received by failed() hook.
	 *
	 * @var \Throwable[]
	 */
	public static array $failed_exceptions = array();

	/**
	 * Processed payloads.
	 *
	 * @var array
	 */
	public static array $processed = array();

	/**
	 * Constructor.
	 *
	 * @param string $message Some message.
	 */
	public function __construct(
		public readonly string $message = 'hello',
	) {}

	/**
	 * Execute the job (always fails).
	 */
	public function handle(): void {
		throw new \RuntimeException( 'JobWithFailedHook intentional failure' );
	}

	/**
	 * Called when the job fails after all retries.
	 *
	 * @param \Throwable $exception The exception that caused the failure.
	 */
	public function failed( \Throwable $exception ): void {
		self::$failed_exceptions[] = $exception;
	}

	/**
	 * Reset state.
	 */
	public static function reset(): void {
		self::$failed_exceptions = array();
		self::$processed         = array();
	}
}
