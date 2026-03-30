<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class ConditionalFailingStep implements Step {

	public function handle( array $state ): array {
		if ( ! empty( $state['should_fail'] ) ) {
			throw new \RuntimeException( 'Conditional failure requested.' );
		}

		return array(
			'topic'   => $state['topic'] ?? null,
			'success' => true,
		);
	}

	public function config(): array {
		return array();
	}
}
