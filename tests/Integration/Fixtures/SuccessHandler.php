<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Handler;

class SuccessHandler implements Handler {

	public static array $processed = array();

	public function handle( array $payload ): void {
		self::$processed[] = $payload;
	}

	public function config(): array {
		return array();
	}

	public static function reset(): void {
		self::$processed = array();
	}
}
