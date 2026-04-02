<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\WorkerPoolScalePolicy;

class WorkerPoolScalePolicyTest extends TestCase {

	public function test_target_worker_count_scales_with_backlog_inside_bounds(): void {
		$this->assertSame(
			4,
			WorkerPoolScalePolicy::target_worker_count( 4, 2, 2, 6 )
		);
	}

	public function test_target_worker_count_never_drops_below_minimum(): void {
		$this->assertSame(
			2,
			WorkerPoolScalePolicy::target_worker_count( 0, 2, 2, 6 )
		);
	}

	public function test_target_worker_count_caps_at_maximum(): void {
		$this->assertSame(
			6,
			WorkerPoolScalePolicy::target_worker_count( 20, 3, 2, 6 )
		);
	}

	public function test_target_worker_count_does_not_scale_up_when_capacity_blocks_it(): void {
		$this->assertSame(
			3,
			WorkerPoolScalePolicy::target_worker_count( 8, 3, 2, 6, false )
		);
	}

	public function test_target_worker_count_holds_steady_during_idle_grace_window(): void {
		$this->assertSame(
			5,
			WorkerPoolScalePolicy::target_worker_count( 0, 5, 2, 6, true, false )
		);
	}
}
