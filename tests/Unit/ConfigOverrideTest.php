<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Queuety\Config;

class ConfigOverrideTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_table_prefix_override_changes_default_table_names(): void {
		define( 'QUEUETY_TABLE_PREFIX', 'themequeue' );

		$this->assertSame( 'themequeue_', Config::table_prefix() );
		$this->assertSame( 'themequeue_jobs', Config::table_jobs() );
		$this->assertSame( 'themequeue_workflows', Config::table_workflows() );
		$this->assertSame( 'themequeue_state_machines', Config::table_state_machines() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_table_prefix_preserves_empty_override(): void {
		define( 'QUEUETY_TABLE_PREFIX', '' );

		$this->assertSame( '', Config::table_prefix() );
		$this->assertSame( 'jobs', Config::table_jobs() );
		$this->assertSame( 'workflow_events', Config::table_workflow_events() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_per_table_override_wins_over_shared_table_prefix(): void {
		define( 'QUEUETY_TABLE_PREFIX', 'themequeue' );
		define( 'QUEUETY_TABLE_JOBS', 'custom_jobs' );

		$this->assertSame( 'custom_jobs', Config::table_jobs() );
		$this->assertSame( 'themequeue_logs', Config::table_logs() );
	}
}
