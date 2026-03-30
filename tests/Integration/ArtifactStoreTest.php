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
	}
}
