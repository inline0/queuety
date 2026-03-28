<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Logger;
use Queuety\Enums\LogEvent;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;

class PurgeTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
	}

	// -- job purge ----------------------------------------------------------

	public function test_purge_completed_with_backdated_jobs(): void {
		$old1    = $this->queue->dispatch( 'h' );
		$old2    = $this->queue->dispatch( 'h' );
		$recent  = $this->queue->dispatch( 'h' );
		$pending = $this->queue->dispatch( 'h' );

		$this->queue->complete( $old1 );
		$this->queue->complete( $old2 );
		$this->queue->complete( $recent );

		// Backdate old1 and old2 to 15 days ago.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 86400 * 15 );
		$this->raw_update( 'queuety_jobs', array( 'completed_at' => $old_date ), array( 'id' => $old1 ) );
		$this->raw_update( 'queuety_jobs', array( 'completed_at' => $old_date ), array( 'id' => $old2 ) );

		$deleted = $this->queue->purge_completed( 7 );

		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->queue->find( $old1 ) );
		$this->assertNull( $this->queue->find( $old2 ) );
		$this->assertNotNull( $this->queue->find( $recent ) );
		$this->assertNotNull( $this->queue->find( $pending ) );
	}

	public function test_purge_completed_does_not_delete_non_completed_jobs(): void {
		$failed  = $this->queue->dispatch( 'h' );
		$buried  = $this->queue->dispatch( 'h' );

		$this->queue->fail( $failed, 'err' );
		$this->queue->bury( $buried, 'err' );

		// Backdate failed_at.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 86400 * 30 );
		$this->raw_update( 'queuety_jobs', array( 'failed_at' => $old_date ), array( 'id' => $failed ) );
		$this->raw_update( 'queuety_jobs', array( 'failed_at' => $old_date ), array( 'id' => $buried ) );

		$deleted = $this->queue->purge_completed( 1 );

		$this->assertSame( 0, $deleted );
		$this->assertNotNull( $this->queue->find( $failed ) );
		$this->assertNotNull( $this->queue->find( $buried ) );
	}

	public function test_purge_completed_returns_zero_when_nothing_to_purge(): void {
		$this->assertSame( 0, $this->queue->purge_completed( 7 ) );
	}

	// -- log purge ----------------------------------------------------------

	public function test_log_purge_with_backdated_entries(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'old1' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'old2' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'recent' ) );

		// Backdate the first two entries.
		$table    = $this->conn->table( Config::table_logs() );
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 86400 * 60 );
		$stmt     = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET created_at = :d WHERE handler IN ('old1', 'old2')"
		);
		$stmt->execute( array( 'd' => $old_date ) );

		$deleted = $this->logger->purge( 30 );

		$this->assertSame( 2, $deleted );

		$remaining = $this->logger->since( new \DateTimeImmutable( '2000-01-01' ) );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'recent', $remaining[0]['handler'] );
	}

	public function test_log_purge_returns_zero_when_nothing_to_purge(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'h' ) );

		$deleted = $this->logger->purge( 1 );

		$this->assertSame( 0, $deleted );
	}

	// -- workflow purge -----------------------------------------------------

	public function test_workflow_purge_completed(): void {
		$builder = new WorkflowBuilder( 'purge_test', $this->conn, $this->queue, $this->logger );
		$wf1     = $builder->then( 'StepA' )->dispatch();

		$builder2 = new WorkflowBuilder( 'purge_test2', $this->conn, $this->queue, $this->logger );
		$wf2      = $builder2->then( 'StepA' )->dispatch();

		$builder3 = new WorkflowBuilder( 'purge_test3', $this->conn, $this->queue, $this->logger );
		$wf3      = $builder3->then( 'StepA' )->dispatch();

		// Complete all.
		$job1 = $this->queue->claim();
		$this->workflow->advance_step( $wf1, $job1->id, array() );

		$job2 = $this->queue->claim();
		$this->workflow->advance_step( $wf2, $job2->id, array() );

		$job3 = $this->queue->claim();
		$this->workflow->advance_step( $wf3, $job3->id, array() );

		// Backdate wf1 and wf2.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 86400 * 20 );
		$this->raw_update( 'queuety_workflows', array( 'completed_at' => $old_date ), array( 'id' => $wf1 ) );
		$this->raw_update( 'queuety_workflows', array( 'completed_at' => $old_date ), array( 'id' => $wf2 ) );

		$deleted = $this->workflow->purge_completed( 7 );

		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->workflow->status( $wf1 ) );
		$this->assertNull( $this->workflow->status( $wf2 ) );
		$this->assertNotNull( $this->workflow->status( $wf3 ) );
	}

	public function test_workflow_purge_does_not_delete_running_workflows(): void {
		$builder = new WorkflowBuilder( 'running_wf', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder->then( 'StepA' )->then( 'StepB' )->dispatch();

		// Backdate started_at.
		$this->raw_update(
			'queuety_workflows',
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 30 ) ),
			array( 'id' => $wf_id ),
		);

		$deleted = $this->workflow->purge_completed( 1 );

		$this->assertSame( 0, $deleted );
		$this->assertNotNull( $this->workflow->status( $wf_id ) );
	}
}
