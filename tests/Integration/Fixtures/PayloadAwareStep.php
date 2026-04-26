<?php
/**
 * Test fixture: step that exposes the current job payload.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\ExecutionContext;
use Queuety\Step;

/**
 * Returns payload metadata for structured branch assertions.
 */
class PayloadAwareStep implements Step {

	public function handle( array $state ): array {
		$payload = ExecutionContext::payload();
		$runtime = is_array( $payload['__queuety_runtime'] ?? null )
			? $payload['__queuety_runtime']
			: array();
		$branch  = is_string( $payload['branch'] ?? null ) ? $payload['branch'] : 'unknown';

		return array(
			"branch_{$branch}_payload" => $branch,
			"branch_{$branch}_timeout" => $runtime['timeout_seconds'] ?? null,
		);
	}

	public function config(): array {
		return array();
	}
}
