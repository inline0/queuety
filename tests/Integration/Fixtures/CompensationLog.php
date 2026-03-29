<?php

namespace Queuety\Tests\Integration\Fixtures;

class CompensationLog {

	public static array $entries = array();

	public static function reset(): void {
		self::$entries = array();
	}

	public static function record( string $label, array $state ): void {
		self::$entries[] = array(
			'label' => $label,
			'state' => $state,
		);
	}
}
