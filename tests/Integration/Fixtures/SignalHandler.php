<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Handler;
use Queuety\Queuety;

class SignalHandler implements Handler {

	public function handle( array $payload ): void {
		file_put_contents( $payload['marker'] . '.started', 'started' );
		$until = microtime( true ) + 0.3;
		while ( microtime( true ) < $until ) {
			usleep( 10_000 );
		}
		Queuety::queue()->stats();
		file_put_contents( $payload['marker'] . '.finished', 'finished' );
	}

	public function config(): array {
		return array();
	}
}
