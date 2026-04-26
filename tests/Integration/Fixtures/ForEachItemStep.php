<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\ForEachHandler;

class ForEachItemStep implements ForEachHandler {

	public function handle_item( array $state, mixed $item, int $index ): array {
		$item = is_array( $item ) ? $item : array( 'value' => $item );

		if ( ( $item['action'] ?? 'success' ) === 'fail' ) {
			throw new \RuntimeException( 'For-each branch failed' );
		}

		return array(
			'branch_id' => $item['id'] ?? $index,
			'value'     => $item['value'] ?? null,
			'source'    => $state['source'] ?? null,
			'index'     => $index,
		);
	}

	public function config(): array {
		return array(
			'max_attempts' => 1,
		);
	}
}
