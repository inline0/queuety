<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\ForEachReducer;
use Queuety\Contracts\RepeatCondition;

class StructuredWorkflowHandlers {

	public static array $calls = array();

	public static function reset(): void {
		self::$calls = array();
	}
}

class StructuredCompensation {

	public function handle( array $state, array $payload = array() ): void {
		StructuredWorkflowHandlers::$calls['compensation'][] = array(
			'state'   => $state,
			'payload' => $payload,
		);
	}
}

class StructuredCancelHandler {

	public function handle( array $state, array $payload = array() ): void {
		StructuredWorkflowHandlers::$calls['cancel'][] = array(
			'state'   => $state,
			'payload' => $payload,
		);
	}
}

class StructuredRepeatCondition implements RepeatCondition {

	public function matches( array $state, array $payload = array() ): bool {
		$key       = is_string( $payload['key'] ?? null ) ? $payload['key'] : 'counter';
		$threshold = is_int( $payload['threshold'] ?? null ) ? $payload['threshold'] : 1;

		StructuredWorkflowHandlers::$calls['condition'][] = array(
			'state'   => $state,
			'payload' => $payload,
		);

		return (int) ( $state[ $key ] ?? 0 ) >= $threshold;
	}
}

class StructuredForEachReducer implements ForEachReducer {

	public function reduce( array $state, array $for_each, array $payload = array() ): array {
		StructuredWorkflowHandlers::$calls['reducer'][] = array(
			'state'    => $state,
			'for_each' => $for_each,
			'payload'  => $payload,
		);

		$result_key = is_string( $payload['result_key'] ?? null ) ? $payload['result_key'] : 'structured_reduce';

		return array(
			$result_key => array(
				'count'   => count( $for_each['results'] ?? array() ),
				'payload' => $payload,
			),
		);
	}
}
