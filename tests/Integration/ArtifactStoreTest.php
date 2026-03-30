<?php
/**
 * Artifact store integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\Integration\Fixtures\ArtifactWritingStep;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class ArtifactStoreTest extends IntegrationTestCase {

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

	public function test_facade_can_store_and_read_workflow_artifacts(): void {
		$workflow_id = Queuety::workflow( 'artifact_holder' )
			->then( ArtifactWritingStep::class )
			->dispatch( array( 'topic' => 'pricing' ) );

		Queuety::put_artifact(
			$workflow_id,
			'research_brief',
			array( 'summary' => 'done' ),
			'json',
			0,
			array( 'source' => 'manual' )
		);

		$artifact = Queuety::workflow_artifact( $workflow_id, 'research_brief' );
		$this->assertNotNull( $artifact );
		$this->assertSame( 'json', $artifact['kind'] );
		$this->assertSame( 'done', $artifact['content']['summary'] );
		$this->assertSame( 'manual', $artifact['metadata']['source'] );

		$status = Queuety::workflow_status( $workflow_id );
		$this->assertSame( 1, $status->artifact_count );
		$this->assertSame( array( 'research_brief' ), $status->artifact_keys );
	}

	public function test_artifacts_can_be_replaced_and_listed_with_content(): void {
		$workflow_id = Queuety::workflow( 'artifact_replace' )
			->then( ArtifactWritingStep::class )
			->dispatch( array( 'topic' => 'pricing' ) );

		Queuety::put_artifact(
			$workflow_id,
			'research_brief',
			array( 'summary' => 'draft' ),
			'json',
			0,
			array( 'source' => 'agent' )
		);
		Queuety::put_artifact(
			$workflow_id,
			'research_brief',
			"# Brief\n\nFinal.",
			'markdown',
			1,
			array( 'source' => 'editor' )
		);

		$artifact = Queuety::workflow_artifact( $workflow_id, 'research_brief' );
		$this->assertNotNull( $artifact );
		$this->assertSame( 'markdown', $artifact['kind'] );
		$this->assertSame( "# Brief\n\nFinal.", $artifact['content'] );
		$this->assertSame( 1, $artifact['step_index'] );
		$this->assertSame( 'editor', $artifact['metadata']['source'] );

		$artifacts = Queuety::workflow_artifacts( $workflow_id, true );
		$this->assertCount( 1, $artifacts );
		$this->assertSame( "# Brief\n\nFinal.", $artifacts[0]['content'] );
	}

	public function test_artifacts_can_be_deleted_and_status_summary_updates(): void {
		$workflow_id = Queuety::workflow( 'artifact_delete' )
			->then( ArtifactWritingStep::class )
			->dispatch( array( 'topic' => 'pricing' ) );

		Queuety::put_artifact( $workflow_id, 'draft', array( 'summary' => 'ready' ) );
		Queuety::put_artifact( $workflow_id, 'citations', array( 'count' => 3 ) );
		Queuety::delete_workflow_artifact( $workflow_id, 'draft' );

		$this->assertNull( Queuety::workflow_artifact( $workflow_id, 'draft' ) );
		$this->assertCount( 1, Queuety::workflow_artifacts( $workflow_id ) );

		$status = Queuety::workflow_status( $workflow_id );
		$this->assertSame( 1, $status->artifact_count );
		$this->assertSame( array( 'citations' ), $status->artifact_keys );
	}

	public function test_workflow_steps_can_store_artifacts_via_current_execution_context(): void {
		$workflow_id = Queuety::workflow( 'artifact_writer' )
			->then( ArtifactWritingStep::class )
			->dispatch( array( 'topic' => 'reviews' ) );

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$this->worker->process_job( $job );

		$artifact = Queuety::workflow_artifact( $workflow_id, 'draft' );
		$this->assertNotNull( $artifact );
		$this->assertSame( 'ready', $artifact['content']['status'] );
		$this->assertSame( 'reviews', $artifact['content']['topic'] );
		$this->assertSame( 0, $artifact['metadata']['step_index'] );
		$this->assertSame( $workflow_id, $artifact['metadata']['workflow_id'] );
	}

	public function test_execution_context_helpers_are_empty_outside_worker_execution(): void {
		$this->assertNull( Queuety::current_workflow_id() );
		$this->assertNull( Queuety::current_step_index() );
	}

	public function test_put_current_artifact_requires_an_active_workflow_context(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No workflow is currently executing.' );

		Queuety::put_current_artifact( 'draft', array( 'summary' => 'nope' ) );
	}
}
