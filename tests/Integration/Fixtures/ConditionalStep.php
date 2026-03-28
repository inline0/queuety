<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Step that behaves differently based on accumulated state.
 * Tests that steps can make decisions using prior step output.
 */
class ConditionalStep implements Step {

	public function handle( array $state ): array {
		$order_data = $state['order_data'] ?? array();

		$refunded = array_filter( $order_data, fn( $o ) => 'refunded' === $o['status'] );
		$total    = array_sum( array_column( $order_data, 'total' ) );

		$risk_level = 'low';
		if ( count( $refunded ) > 0 ) {
			$refund_rate = count( $refunded ) / max( count( $order_data ), 1 );
			$risk_level  = $refund_rate > 0.5 ? 'high' : 'medium';
		}

		return array(
			'risk_level'    => $risk_level,
			'refund_count'  => count( $refunded ),
			'total_revenue' => $total,
			'needs_review'  => 'high' === $risk_level,
		);
	}

	public function config(): array {
		return array();
	}
}
