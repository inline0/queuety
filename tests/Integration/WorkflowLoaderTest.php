<?php
/**
 * WorkflowLoader integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Enums\WorkflowStatus;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\WorkflowLoader;

class WorkflowLoaderTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	public function test_load_workflows_from_directory(): void {
		$dir   = dirname( __DIR__ ) . '/Integration/Fixtures/Workflows';
		$count = Queuety::load_workflows( $dir );

		$this->assertGreaterThanOrEqual( 1, $count );
		$this->assertTrue( Queuety::workflow_templates()->has( 'onboard_user' ) );
	}

	public function test_run_loaded_workflow(): void {
		$dir = dirname( __DIR__ ) . '/Integration/Fixtures/Workflows';
		Queuety::load_workflows( $dir );

		$wf_id = Queuety::run_workflow( 'onboard_user', array( 'user_id' => 42 ) );
		$this->assertGreaterThan( 0, $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 0, $status->current_step );
		$this->assertSame( 2, $status->total_steps );
	}

	public function test_loaded_workflow_completes_with_worker(): void {
		$dir = dirname( __DIR__ ) . '/Integration/Fixtures/Workflows';
		Queuety::load_workflows( $dir );

		$wf_id = Queuety::run_workflow( 'onboard_user', array( 'user_id' => 7 ) );

		Queuety::worker()->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['user_created'] );
		$this->assertTrue( $status->state['welcome_sent'] );
		$this->assertSame( 'User #7', $status->state['user_name'] );
		$this->assertSame( 'user7@test.com', $status->state['welcome_to'] );
	}

	public function test_load_single_file(): void {
		$file     = dirname( __DIR__ ) . '/Integration/Fixtures/Workflows/onboard-user.php';
		$template = Queuety::load_workflow_file( $file );

		$this->assertNotNull( $template );
		$this->assertSame( 'onboard_user', $template->name );
	}

	public function test_load_multiple_instances_from_same_template(): void {
		$dir = dirname( __DIR__ ) . '/Integration/Fixtures/Workflows';
		Queuety::load_workflows( $dir );

		$wf_id_1 = Queuety::run_workflow( 'onboard_user', array( 'user_id' => 1 ) );
		$wf_id_2 = Queuety::run_workflow( 'onboard_user', array( 'user_id' => 2 ) );

		$this->assertNotSame( $wf_id_1, $wf_id_2 );

		Queuety::worker()->flush();

		$status_1 = Queuety::workflow_status( $wf_id_1 );
		$status_2 = Queuety::workflow_status( $wf_id_2 );

		$this->assertSame( WorkflowStatus::Completed, $status_1->status );
		$this->assertSame( WorkflowStatus::Completed, $status_2->status );
		$this->assertSame( 'User #1', $status_1->state['user_name'] );
		$this->assertSame( 'User #2', $status_2->state['user_name'] );
	}
}
