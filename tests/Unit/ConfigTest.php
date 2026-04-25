<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Queuety\Config;
use Queuety\Enums\BackoffStrategy;

class ConfigTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_defaults(): void {
		$this->assertSame( 'queuety_', Config::table_prefix() );
		$this->assertSame( 'queuety_jobs', Config::table_jobs() );
		$this->assertSame( 'queuety_workflows', Config::table_workflows() );
		$this->assertSame( 'queuety_logs', Config::table_logs() );
		$this->assertSame( 7, Config::retention_days() );
		$this->assertSame( 0, Config::log_retention_days() );
		$this->assertSame( 300, Config::max_execution_time() );
		$this->assertSame( 1, Config::worker_sleep() );
		$this->assertSame( 1000, Config::worker_max_jobs() );
		$this->assertSame( 128, Config::worker_max_memory() );
		$this->assertTrue( Config::resource_admission_enabled() );
		$this->assertTrue( Config::resource_system_memory_awareness_enabled() );
		$this->assertSame( 16, Config::resource_memory_headroom_mb() );
		$this->assertSame( 32, Config::resource_system_memory_headroom_mb() );
		$this->assertSame( array(), Config::resource_queue_cost_budgets() );
		$this->assertSame( array(), Config::resource_group_cost_budgets() );
		$this->assertSame( 5, Config::worker_pool_scale_interval_seconds() );
		$this->assertSame( 15, Config::worker_pool_idle_grace_seconds() );
		$this->assertSame( BackoffStrategy::Exponential, Config::retry_backoff() );
		$this->assertSame( 600, Config::stale_timeout() );
	}
}
