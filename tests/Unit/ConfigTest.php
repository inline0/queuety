<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Config;
use Queuety\Enums\BackoffStrategy;

class ConfigTest extends TestCase {

	public function test_defaults(): void {
		$this->assertSame( 'queuety_jobs', Config::table_jobs() );
		$this->assertSame( 'queuety_workflows', Config::table_workflows() );
		$this->assertSame( 'queuety_logs', Config::table_logs() );
		$this->assertSame( 7, Config::retention_days() );
		$this->assertSame( 0, Config::log_retention_days() );
		$this->assertSame( 300, Config::max_execution_time() );
		$this->assertSame( 1, Config::worker_sleep() );
		$this->assertSame( 1000, Config::worker_max_jobs() );
		$this->assertSame( 128, Config::worker_max_memory() );
		$this->assertSame( BackoffStrategy::Exponential, Config::retry_backoff() );
		$this->assertSame( 600, Config::stale_timeout() );
	}
}
