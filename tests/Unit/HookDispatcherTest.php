<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\HookDispatcher;

// Define a do_action stub in the global namespace so HookDispatcher can find it.
// This tracks all fired hooks for verification. Must be defined before tests run.
if ( ! function_exists( 'do_action' ) ) {
	function _queuety_test_register_do_action(): void {
		// Use eval to define the function in the global namespace.
		eval( '
			function do_action( string $hook, mixed ...$args ): void {
				global $_queuety_test_fired_hooks;
				if ( ! is_array( $_queuety_test_fired_hooks ) ) {
					$_queuety_test_fired_hooks = array();
				}
				$_queuety_test_fired_hooks[] = array( "hook" => $hook, "args" => $args );
			}
		' );
	}
	_queuety_test_register_do_action();
}

class HookDispatcherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $_queuety_test_fired_hooks;
		$_queuety_test_fired_hooks = array();
	}

	protected function tearDown(): void {
		global $_queuety_test_fired_hooks;
		$_queuety_test_fired_hooks = array();
		parent::tearDown();
	}

	private function fired_hooks(): array {
		global $_queuety_test_fired_hooks;
		return $_queuety_test_fired_hooks ?? array();
	}

	public function test_fire_calls_do_action_with_hook_name(): void {
		HookDispatcher::fire( 'test_hook' );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'test_hook', $hooks[0]['hook'] );
	}

	public function test_fire_passes_arguments_to_do_action(): void {
		HookDispatcher::fire( 'test_hook', 'arg1', 42, true );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( array( 'arg1', 42, true ), $hooks[0]['args'] );
	}

	public function test_fire_with_no_extra_args(): void {
		HookDispatcher::fire( 'empty_hook' );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( array(), $hooks[0]['args'] );
	}

	public function test_job_started_fires_correct_hook(): void {
		HookDispatcher::job_started( 5, 'SendEmailHandler' );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_job_started', $hooks[0]['hook'] );
		$this->assertSame( 5, $hooks[0]['args'][0] );
		$this->assertSame( 'SendEmailHandler', $hooks[0]['args'][1] );
	}

	public function test_job_completed_fires_correct_hook(): void {
		HookDispatcher::job_completed( 10, 'ProcessHandler', 250 );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_job_completed', $hooks[0]['hook'] );
		$this->assertSame( 10, $hooks[0]['args'][0] );
		$this->assertSame( 'ProcessHandler', $hooks[0]['args'][1] );
		$this->assertSame( 250, $hooks[0]['args'][2] );
	}

	public function test_job_failed_fires_correct_hook(): void {
		$exception = new \RuntimeException( 'oops' );
		HookDispatcher::job_failed( 3, 'FailHandler', $exception );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_job_failed', $hooks[0]['hook'] );
		$this->assertSame( 3, $hooks[0]['args'][0] );
		$this->assertSame( 'FailHandler', $hooks[0]['args'][1] );
		$this->assertSame( $exception, $hooks[0]['args'][2] );
	}

	public function test_job_buried_fires_correct_hook(): void {
		$exception = new \LogicException( 'fatal' );
		HookDispatcher::job_buried( 7, 'DeadHandler', $exception );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_job_buried', $hooks[0]['hook'] );
		$this->assertSame( 7, $hooks[0]['args'][0] );
		$this->assertSame( 'DeadHandler', $hooks[0]['args'][1] );
		$this->assertSame( $exception, $hooks[0]['args'][2] );
	}

	public function test_workflow_started_fires_correct_hook(): void {
		HookDispatcher::workflow_started( 20, 'report_gen', 4 );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_workflow_started', $hooks[0]['hook'] );
		$this->assertSame( 20, $hooks[0]['args'][0] );
		$this->assertSame( 'report_gen', $hooks[0]['args'][1] );
		$this->assertSame( 4, $hooks[0]['args'][2] );
	}

	public function test_workflow_step_completed_fires_correct_hook(): void {
		HookDispatcher::workflow_step_completed( 20, 2, 'StepTwo', 100 );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_workflow_step_completed', $hooks[0]['hook'] );
		$this->assertSame( 20, $hooks[0]['args'][0] );
		$this->assertSame( 2, $hooks[0]['args'][1] );
		$this->assertSame( 'StepTwo', $hooks[0]['args'][2] );
		$this->assertSame( 100, $hooks[0]['args'][3] );
	}

	public function test_workflow_completed_fires_correct_hook(): void {
		$state = array( 'result' => 'success' );
		HookDispatcher::workflow_completed( 20, 'report_gen', $state );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_workflow_completed', $hooks[0]['hook'] );
		$this->assertSame( 20, $hooks[0]['args'][0] );
		$this->assertSame( 'report_gen', $hooks[0]['args'][1] );
		$this->assertSame( $state, $hooks[0]['args'][2] );
	}

	public function test_workflow_failed_fires_correct_hook(): void {
		$exception = new \RuntimeException( 'step failed' );
		HookDispatcher::workflow_failed( 20, 'report_gen', 2, $exception );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_workflow_failed', $hooks[0]['hook'] );
		$this->assertSame( 20, $hooks[0]['args'][0] );
		$this->assertSame( 'report_gen', $hooks[0]['args'][1] );
		$this->assertSame( 2, $hooks[0]['args'][2] );
		$this->assertSame( $exception, $hooks[0]['args'][3] );
	}

	public function test_worker_started_fires_correct_hook(): void {
		HookDispatcher::worker_started( 12345 );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_worker_started', $hooks[0]['hook'] );
		$this->assertSame( 12345, $hooks[0]['args'][0] );
	}

	public function test_worker_stopped_fires_correct_hook(): void {
		HookDispatcher::worker_stopped( 12345, 'memory_limit' );

		$hooks = $this->fired_hooks();
		$this->assertCount( 1, $hooks );
		$this->assertSame( 'queuety_worker_stopped', $hooks[0]['hook'] );
		$this->assertSame( 12345, $hooks[0]['args'][0] );
		$this->assertSame( 'memory_limit', $hooks[0]['args'][1] );
	}

	public function test_multiple_fires_accumulate(): void {
		HookDispatcher::job_started( 1, 'A' );
		HookDispatcher::job_completed( 1, 'A', 50 );

		$hooks = $this->fired_hooks();
		$this->assertCount( 2, $hooks );
		$this->assertSame( 'queuety_job_started', $hooks[0]['hook'] );
		$this->assertSame( 'queuety_job_completed', $hooks[1]['hook'] );
	}
}
