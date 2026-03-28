<?php
/**
 * Integration tests for ChainBuilder.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SimpleJob;
use Queuety\Tests\Integration\Fixtures\FailingJob;

class ChainBuilderTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
		);

		Queuety::init( $this->conn );
		SimpleJob::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_chain_dispatches_jobs_with_dependencies(): void {
		$first_id = Queuety::chain( array(
			new SimpleJob( 'step-1' ),
			new SimpleJob( 'step-2' ),
			new SimpleJob( 'step-3' ),
		) )->dispatch();

		$this->assertGreaterThan( 0, $first_id );

		// First job should be claimable.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->assertSame( $first_id, $job1->id );
		$this->assertNull( $job1->depends_on );

		// Second and third should not be claimable (depends_on not completed).
		$job2_attempt = $this->queue->claim();
		$this->assertNull( $job2_attempt );

		// Process first job.
		$this->worker->process_job( $job1 );

		// Now second job should be claimable.
		$job2 = $this->queue->claim();
		$this->assertNotNull( $job2 );
		$this->assertSame( $first_id, $job2->depends_on );

		$this->worker->process_job( $job2 );

		// Third job.
		$job3 = $this->queue->claim();
		$this->assertNotNull( $job3 );
		$this->worker->process_job( $job3 );

		// All three should have been processed in order.
		$this->assertSame( array( 'step-1', 'step-2', 'step-3' ), SimpleJob::$log );
	}

	public function test_chain_on_queue(): void {
		Queuety::chain( array(
			new SimpleJob( 'q1' ),
			new SimpleJob( 'q2' ),
		) )->on_queue( 'pipeline' )->dispatch();

		$job = $this->queue->claim( 'pipeline' );
		$this->assertNotNull( $job );
		$this->assertSame( 'pipeline', $job->queue );
	}

	public function test_chain_failure_blocks_subsequent_jobs(): void {
		Queuety::chain( array(
			new FailingJob( 'chain-fail' ),
			new SimpleJob( 'after-fail' ),
		) )->dispatch();

		// Process the failing job until buried (3 attempts).
		for ( $i = 0; $i < 5; $i++ ) {
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		// The second job should never have been processed.
		$this->assertNotContains( 'after-fail', SimpleJob::$log );
	}

	public function test_chain_with_handler_arrays(): void {
		$first_id = Queuety::chain( array(
			array( 'handler' => SimpleJob::class, 'payload' => array( 'label' => 'arr-1' ) ),
			array( 'handler' => SimpleJob::class, 'payload' => array( 'label' => 'arr-2' ) ),
		) )->dispatch();

		$this->assertGreaterThan( 0, $first_id );

		// Process first.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->worker->process_job( $job1 );

		// Process second.
		$job2 = $this->queue->claim();
		$this->assertNotNull( $job2 );
		$this->worker->process_job( $job2 );

		$this->assertSame( array( 'arr-1', 'arr-2' ), SimpleJob::$log );
	}

	public function test_chain_catch_metadata(): void {
		$first_id = Queuety::chain( array(
			new SimpleJob( 'catch-test' ),
		) )
			->catch( 'SomeErrorHandler' )
			->dispatch();

		$job = $this->queue->find( $first_id );
		$this->assertSame( 'SomeErrorHandler', $job->payload['__chain_catch'] ?? null );
	}
}
