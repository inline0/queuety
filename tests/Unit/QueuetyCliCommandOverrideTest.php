<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Queuety\Queuety;

class QueuetyCliCommandOverrideTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cli_command_uses_custom_constant_when_defined(): void {
		define( 'QUEUETY_CLI_COMMAND', 'themequeue' );

		$this->assertSame( 'themequeue', Queuety::cli_command() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cli_command_map_uses_custom_root_command(): void {
		define( 'QUEUETY_CLI_COMMAND', 'themequeue' );

		$definitions = array_column( Queuety::cli_command_map(), null, 'operation' );
		$definition  = $definitions['workflow.export'];

		$this->assertSame( 'wp themequeue workflow export', $definition['wp_cli_command'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_resolve_cli_command_accepts_custom_root_command(): void {
		define( 'QUEUETY_CLI_COMMAND', 'themequeue' );

		$plan = Queuety::resolve_cli_command( 'wp themequeue workflow list' );

		$this->assertSame( 'workflow.list', $plan['operation'] );
		$this->assertSame( 'wp themequeue workflow list', $plan['wp_cli_command'] );
		$this->assertSame( \Queuety\Queuety::class . '::list_workflows', $plan['callable'] );
		$this->assertSame( array( null, 50 ), $plan['arguments'] );
	}
}
