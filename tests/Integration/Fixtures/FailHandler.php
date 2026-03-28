<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Handler;

class FailHandler implements Handler {

	public function handle( array $payload ): void {
		throw new \RuntimeException( $payload['message'] ?? 'Handler failed' );
	}

	public function config(): array {
		return array();
	}
}
