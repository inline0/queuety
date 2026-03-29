<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\JoinReducer;

class FanOutSummaryReducer implements JoinReducer {

	public function reduce( array $state, array $fan_out ): array {
		return array(
			'fan_out_count' => count( $fan_out['results'] ?? array() ),
			'winner_id'     => $fan_out['winner']['output']['branch_id'] ?? null,
		);
	}
}
