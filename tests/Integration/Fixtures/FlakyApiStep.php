<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Simulates a flaky HTTP API call (like an LLM endpoint).
 * Fails a configurable number of times, then succeeds.
 * Tracks call history for assertions.
 */
class FlakyApiStep implements Step {

	/** @var int[] Attempt counts per workflow_id-ish key. */
	public static array $attempts = array();

	/** @var array[] Recorded calls: ['state' => ..., 'attempt' => ...]. */
	public static array $calls = array();

	public function handle( array $state ): array {
		$key            = $state['_flaky_key'] ?? 'default';
		$fail_times     = $state['_flaky_fail_times'] ?? 2;
		$response_data  = $state['_flaky_response'] ?? array( 'api_result' => 'success' );

		self::$attempts[ $key ] = ( self::$attempts[ $key ] ?? 0 ) + 1;
		$attempt                = self::$attempts[ $key ];

		self::$calls[] = array(
			'key'     => $key,
			'attempt' => $attempt,
			'state'   => $state,
		);

		if ( $attempt <= $fail_times ) {
			throw new \RuntimeException(
				"Simulated API failure (attempt {$attempt}/{$fail_times}): Connection timed out after 30000ms"
			);
		}

		return $response_data;
	}

	public function config(): array {
		return array();
	}

	public static function reset(): void {
		self::$attempts = array();
		self::$calls    = array();
	}
}
