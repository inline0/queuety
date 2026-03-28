<?php
/**
 * Test fixture: rate-limited handler.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Handler;

/**
 * Handler with a rate limit of 2 executions per 60 seconds.
 */
class RateLimitedHandler implements Handler {

	/**
	 * Payloads that have been processed.
	 *
	 * @var array
	 */
	public static array $processed = array();

	/**
	 * Execute the job.
	 *
	 * @param array $payload Job payload data.
	 */
	public function handle( array $payload ): void {
		self::$processed[] = $payload;
	}

	/**
	 * Handler configuration with rate limit.
	 *
	 * @return array Configuration array.
	 */
	public function config(): array {
		return array(
			'rate_limit' => array( 2, 60 ),
		);
	}

	/**
	 * Reset processed payloads.
	 */
	public static function reset(): void {
		self::$processed = array();
	}
}
