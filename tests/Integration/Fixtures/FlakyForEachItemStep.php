<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\ForEachHandler;

class FlakyForEachItemStep implements ForEachHandler {

	/** @var array<string,int> */
	public static array $attempts = array();

	public static function reset(): void {
		self::$attempts = array();
	}

	public function handle_item( array $state, mixed $item, int $index ): array {
		$item = is_array( $item ) ? $item : array( 'value' => $item );
		$key  = (string) ( $item['id'] ?? $index );

		self::$attempts[ $key ] = ( self::$attempts[ $key ] ?? 0 ) + 1;

		if ( ( $item['action'] ?? 'success' ) === 'fail_once' && 1 === self::$attempts[ $key ] ) {
			throw new \RuntimeException( 'For-each branch failed once' );
		}

		return array(
			'branch_id' => $item['id'] ?? $index,
			'value'     => $item['value'] ?? null,
			'source'    => $state['source'] ?? null,
			'index'     => $index,
			'attempt'   => self::$attempts[ $key ],
		);
	}

	public function config(): array {
		return array(
			'max_attempts' => 1,
		);
	}
}
