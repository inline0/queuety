<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Simulates fetching data from a database or API.
 * Returns structured data based on the input state.
 */
class DataFetchStep implements Step {

	public function handle( array $state ): array {
		$user_id = $state['user_id'] ?? 0;

		return array(
			'user_name'   => "User #{$user_id}",
			'user_email'  => "user{$user_id}@example.com",
			'order_count' => 42,
			'order_data'  => array(
				array( 'id' => 101, 'total' => 29.99, 'status' => 'completed' ),
				array( 'id' => 102, 'total' => 149.50, 'status' => 'completed' ),
				array( 'id' => 103, 'total' => 9.99, 'status' => 'refunded' ),
			),
		);
	}

	public function config(): array {
		return array();
	}
}
