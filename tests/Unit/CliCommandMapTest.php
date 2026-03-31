<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Queuety;

class CliCommandMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Queuety::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_cli_command_map_includes_expected_operations(): void {
		$operations = array_column( Queuety::cli_command_map(), 'operation' );

		$this->assertContains( 'worker.run', $operations );
		$this->assertContains( 'job.dispatch', $operations );
		$this->assertContains( 'workflow.export', $operations );
		$this->assertContains( 'log.query', $operations );
		$this->assertContains( 'webhook.remove', $operations );
	}

	public function test_cli_command_map_exposes_handler_and_target_metadata(): void {
		$definitions = array_column( Queuety::cli_command_map(), null, 'operation' );
		$definition  = $definitions['workflow.export'];

		$this->assertSame( array( 'workflow', 'export' ), $definition['cli_path'] );
		$this->assertSame( 'wp queuety workflow export', $definition['wp_cli_command'] );
		$this->assertSame( \Queuety\CLI\WorkflowCommand::class, $definition['handler']['class'] );
		$this->assertSame( 'export', $definition['handler']['method'] );
		$this->assertSame(
			array(
				array(
					'transport' => 'php',
					'callable'  => \Queuety\Queuety::class . '::export_workflow',
				),
				array(
					'transport' => 'php',
					'callable'  => \Queuety\Queuety::class . '::export_workflow_to_file',
				),
			),
			$definition['targets']
		);
	}

	public function test_resolve_accepts_full_wp_cli_path(): void {
		$plan = Queuety::resolve_cli_command( 'wp queuety workflow list' );

		$this->assertSame( 'workflow.list', $plan['operation'] );
		$this->assertSame( array( 'workflow', 'list' ), $plan['cli_path'] );
		$this->assertSame( 'wp queuety workflow list', $plan['wp_cli_command'] );
		$this->assertSame( 'php', $plan['transport'] );
		$this->assertSame( \Queuety\Queuety::class . '::list_workflows', $plan['callable'] );
		$this->assertSame( array( null, 50 ), $plan['arguments'] );
	}

	public function test_resolve_workflow_timeline_defaults_limit_and_offset(): void {
		$plan = Queuety::resolve_cli_command( array( 'workflow', 'timeline' ), array( '42' ) );

		$this->assertSame( 'workflow.timeline', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::workflow_timeline', $plan['callable'] );
		$this->assertSame( array( 42, 100, 0 ), $plan['arguments'] );
	}

	public function test_resolve_work_with_workers_uses_pool_target(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'work' ),
			array(),
			array(
				'queue'   => 'critical,default',
				'once'    => true,
				'workers' => '4',
			)
		);

		$this->assertSame( 'worker.run', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::run_worker_pool', $plan['callable'] );
		$this->assertSame( array( 4, 'critical,default' ), $plan['arguments'] );
	}

	public function test_resolve_dispatch_normalizes_payload_priority_and_delay(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'dispatch' ),
			array( 'send_email' ),
			array(
				'payload'  => '{"to":"user@example.com"}',
				'queue'    => 'emails',
				'priority' => '3',
				'delay'    => '30',
			)
		);

		$this->assertSame( 'job.dispatch', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::dispatch_job', $plan['callable'] );
		$this->assertSame(
			array(
				'send_email',
				array( 'to' => 'user@example.com' ),
				'emails',
				3,
				30,
			),
			$plan['arguments']
		);
	}

	public function test_resolve_workflow_export_with_output_uses_file_target(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'workflow', 'export' ),
			array( '42' ),
			array( 'output' => '/tmp/workflow.json' )
		);

		$this->assertSame( 'workflow.export', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::export_workflow_to_file', $plan['callable'] );
		$this->assertSame( array( 42, '/tmp/workflow.json' ), $plan['arguments'] );
	}

	public function test_resolve_workflow_export_treats_blank_output_as_file_target(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'workflow', 'export' ),
			array( '42' ),
			array( 'output' => '' )
		);

		$this->assertSame( 'workflow.export', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::export_workflow_to_file', $plan['callable'] );
		$this->assertSame( array( 42, '' ), $plan['arguments'] );
	}

	public function test_resolve_log_query_defaults_to_recent_window(): void {
		$plan = Queuety::resolve_cli_command( array( 'log' ) );

		$this->assertSame( 'log.query', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::query_logs', $plan['callable'] );
		$this->assertSame(
			array(
				array(
					'job_id'      => null,
					'workflow_id' => null,
					'handler'     => null,
					'event'       => null,
					'since'       => '-24 hours',
					'limit'       => 50,
				),
			),
			$plan['arguments']
		);
	}

	public function test_resolve_discover_preserves_register_flag(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'discover' ),
			array( '/srv/app/src/Jobs', 'App\\Jobs' ),
			array( 'register' => true )
		);

		$this->assertSame( 'handler.discover', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::discover_handlers_cli', $plan['callable'] );
		$this->assertSame( array( '/srv/app/src/Jobs', 'App\\Jobs', true ), $plan['arguments'] );
	}

	public function test_resolve_schedule_add_prefers_interval_expression_when_both_are_present(): void {
		$plan = Queuety::resolve_cli_command(
			array( 'schedule', 'add' ),
			array( 'sync_reports' ),
			array(
				'every' => '10 minutes',
				'cron'  => '0 * * * *',
				'queue' => 'scheduled',
			)
		);

		$this->assertSame( 'schedule.add', $plan['operation'] );
		$this->assertSame( \Queuety\Queuety::class . '::add_schedule', $plan['callable'] );
		$this->assertSame(
			array(
				'sync_reports',
				array(),
				'scheduled',
				'10 minutes',
				'interval',
			),
			$plan['arguments']
		);
	}

	public function test_resolve_workflow_approve_requires_json_array_payload(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '--data must be a JSON object.' );

		Queuety::resolve_cli_command(
			array( 'workflow', 'approve' ),
			array( '10' ),
			array( 'data' => '"invalid"' )
		);
	}
}
