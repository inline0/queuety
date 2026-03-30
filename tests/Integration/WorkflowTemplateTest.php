<?php
/**
 * Workflow template tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowRegistry;
use Queuety\WorkflowTemplate;

/**
 * Tests for workflow template registration and dispatching.
 */
class WorkflowTemplateTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow_mgr = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry     = new HandlerRegistry();
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			$this->registry,
			new Config(),
		);

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/**
	 * Process exactly one job.
	 *
	 * @return Job|null
	 */
	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}
		$this->worker->process_job( $job );
		return $job;
	}

	public function test_register_and_get_template(): void {
		$reg = Queuety::workflow_templates();

		$this->assertFalse( $reg->has( 'my_template' ) );

		$builder = Queuety::define_workflow( 'my_template' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class );

		Queuety::register_workflow_template( $builder );

		$this->assertTrue( $reg->has( 'my_template' ) );

		$template = $reg->get( 'my_template' );
		$this->assertInstanceOf( WorkflowTemplate::class, $template );
		$this->assertSame( 'my_template', $template->name );
		$this->assertCount( 2, $template->steps );
	}

	public function test_has_returns_false_for_unregistered(): void {
		$reg = Queuety::workflow_templates();
		$this->assertFalse( $reg->has( 'nonexistent' ) );
		$this->assertNull( $reg->get( 'nonexistent' ) );
	}

	public function test_all_returns_all_registered(): void {
		$reg = Queuety::workflow_templates();

		$builder_a = Queuety::define_workflow( 'template_a' )
			->then( DataFetchStep::class );
		Queuety::register_workflow_template( $builder_a );

		$builder_b = Queuety::define_workflow( 'template_b' )
			->then( AccumulatingStep::class );
		Queuety::register_workflow_template( $builder_b );

		$all = $reg->all();
		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'template_a', $all );
		$this->assertArrayHasKey( 'template_b', $all );
	}

	public function test_dispatch_from_template(): void {
		$builder = Queuety::define_workflow( 'report_gen' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class );

		Queuety::register_workflow_template( $builder );

		$wf_id = Queuety::run_workflow( 'report_gen', array( 'user_id' => 42 ) );
		$this->assertGreaterThan( 0, $wf_id );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 'report_gen', $status->name );

		// Process both steps.
		$this->process_one();
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 1, $status->state['counter'] );
	}

	public function test_dispatch_multiple_instances_from_template(): void {
		$builder = Queuety::define_workflow( 'multi_instance' )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class );

		Queuety::register_workflow_template( $builder );

		$wf_id_1 = Queuety::run_workflow( 'multi_instance', array( 'user_id' => 1 ) );
		$wf_id_2 = Queuety::run_workflow( 'multi_instance', array( 'user_id' => 2 ) );
		$wf_id_3 = Queuety::run_workflow( 'multi_instance', array( 'user_id' => 3 ) );

		$this->assertNotSame( $wf_id_1, $wf_id_2 );
		$this->assertNotSame( $wf_id_2, $wf_id_3 );

		// Process all workflows.
		$this->worker->flush();

		foreach ( array( $wf_id_1, $wf_id_2, $wf_id_3 ) as $wf_id ) {
			$status = $this->workflow_mgr->status( $wf_id );
			$this->assertSame( WorkflowStatus::Completed, $status->status );
		}

		// Verify each had isolated state.
		$this->assertSame( 'User #1', $this->workflow_mgr->status( $wf_id_1 )->state['user_name'] );
		$this->assertSame( 'User #2', $this->workflow_mgr->status( $wf_id_2 )->state['user_name'] );
		$this->assertSame( 'User #3', $this->workflow_mgr->status( $wf_id_3 )->state['user_name'] );
	}

	public function test_run_workflow_throws_for_unregistered_template(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not registered' );

		Queuety::run_workflow( 'nonexistent_template' );
	}

	public function test_template_preserves_priority_and_queue(): void {
		$builder = Queuety::define_workflow( 'custom_opts' )
			->then( DataFetchStep::class )
			->on_queue( 'reports' )
			->with_priority( Priority::High )
			->max_attempts( 5 );

		Queuety::register_workflow_template( $builder );

		$template = Queuety::workflow_templates()->get( 'custom_opts' );
		$this->assertSame( 'reports', $template->queue );
		$this->assertSame( Priority::High, $template->priority );
		$this->assertSame( 5, $template->max_attempts );
	}

	public function test_run_workflow_preserves_runtime_definition_metadata(): void {
		$builder = Queuety::define_workflow( 'rich_template' )
			->version( 'rich-template.v2' )
			->max_transitions( 4 )
			->prune_state_after( 1 )
			->must_complete_within( minutes: 5 )
			->compensate_on_failure()
			->then( DataFetchStep::class );

		Queuety::register_workflow_template( $builder );

		$wf_id = Queuety::run_workflow(
			'rich_template',
			array( 'user_id' => 7 ),
			array( 'idempotency_key' => 'rich-template:7' )
		);

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 'rich-template.v2', $status->definition_version );
		$this->assertSame( 'rich-template:7', $status->idempotency_key );
		$this->assertSame( 4, $status->budget['max_transitions'] );

		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT state, deadline_at FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $wf_id ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		$this->assertIsArray( $row );

		$state = json_decode( $row['state'], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 1, $state['_prune_state_after'] );
		$this->assertTrue( $state['_compensate_on_failure'] );
		$this->assertNotNull( $row['deadline_at'] );
	}
}
