<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\ForEachReducer;

class ForEachSummaryReducer implements ForEachReducer {

	public function reduce( array $state, array $for_each ): array {
		return array(
			'for_each_count' => count( $for_each['results'] ?? array() ),
			'winner_id'     => $for_each['winner']['output']['branch_id'] ?? null,
		);
	}
}
