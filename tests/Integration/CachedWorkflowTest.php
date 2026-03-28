<?php
/**
 * Integration tests for cache-backed Workflow operations.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Cache\MemoryCache;
use Queuety\Config;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class CachedWorkflowTest extends IntegrationTestCase {

	private Queue $queue;
	private Workflow $workflow;
	private MemoryCache $cache;
	private Worker $worker;
	private HandlerRegistry $registry;
	private Logger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->cache    = new MemoryCache();
		$this->queue    = new Queue( $this->conn, $this->cache );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger, $this->cache );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
		);

		SuccessHandler::reset();
	}

	/**
	 * Create a simple workflow directly in the DB for testing.
	 *
	 * @param string $name  Workflow name.
	 * @param array  $steps Step definitions.
	 * @param array  $state Initial state.
	 * @return int The workflow ID.
	 */
	private function create_workflow( string $name, array $steps, array $state = array() ): int {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$state['_steps']        = $steps;
		$state['_queue']        = 'default';
		$state['_priority']     = Priority::Low->value;
		$state['_max_attempts'] = 3;

		$stmt = $pdo->prepare(
			"INSERT INTO {$wf_tbl} (name, status, current_step, total_steps, state)
			VALUES (:name, :status, :current_step, :total_steps, :state)"
		);
		$stmt->execute(
			array(
				'name'         => $name,
				'status'       => WorkflowStatus::Running->value,
				'current_step' => 0,
				'total_steps'  => count( $steps ),
				'state'        => json_encode( $state, JSON_THROW_ON_ERROR ),
			)
		);

		return (int) $pdo->lastInsertId();
	}

	// -- get_state caches on read --------------------------------------------

	public function test_get_state_cached_on_read(): void {
		$wf_id = $this->create_workflow( 'test_wf', array( 'StepA', 'StepB' ), array( 'key' => 'value' ) );

		// First call: DB hit, result cached.
		$state = $this->workflow->get_state( $wf_id );
		$this->assertNotNull( $state );
		$this->assertSame( 'value', $state['key'] );

		// Verify cache populated.
		$this->assertTrue( $this->cache->has( "queuety:wf_state:{$wf_id}" ) );

		// Second call: from cache.
		$state2 = $this->workflow->get_state( $wf_id );
		$this->assertSame( $state, $state2 );
	}

	// -- status caches on read -----------------------------------------------

	public function test_status_cached_on_read(): void {
		$wf_id = $this->create_workflow( 'test_wf', array( 'StepA' ), array( 'data' => 123 ) );

		// First call: DB hit, result cached.
		$status = $this->workflow->status( $wf_id );
		$this->assertNotNull( $status );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Verify cache populated.
		$this->assertTrue( $this->cache->has( "queuety:wf_status:{$wf_id}" ) );

		// Second call: from cache.
		$status2 = $this->workflow->status( $wf_id );
		$this->assertSame( $status->workflow_id, $status2->workflow_id );
		$this->assertSame( $status->status, $status2->status );
	}

	// -- advance_step invalidates cache --------------------------------------

	public function test_advance_step_invalidates_cache(): void {
		$this->registry->register( 'success', SuccessHandler::class );

		$wf_id = $this->create_workflow(
			'test_advance',
			array( 'success', 'success' ),
			array( 'initial' => true ),
		);

		// Dispatch first step job.
		$job_id = $this->queue->dispatch(
			handler: 'success',
			payload: array(),
			queue: 'default',
			workflow_id: $wf_id,
			step_index: 0,
		);

		// Prime the caches.
		$this->workflow->get_state( $wf_id );
		$this->workflow->status( $wf_id );
		$this->assertTrue( $this->cache->has( "queuety:wf_state:{$wf_id}" ) );
		$this->assertTrue( $this->cache->has( "queuety:wf_status:{$wf_id}" ) );

		// Advance step should invalidate caches.
		$this->workflow->advance_step(
			workflow_id: $wf_id,
			completed_job_id: $job_id,
			step_output: array( 'step_0_output' => 'done' ),
			duration_ms: 10,
		);

		// Caches should be cleared.
		$this->assertFalse( $this->cache->has( "queuety:wf_state:{$wf_id}" ) );
		$this->assertFalse( $this->cache->has( "queuety:wf_status:{$wf_id}" ) );

		// Fresh read from DB after invalidation.
		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 'done', $state['step_0_output'] );
	}

	// -- workflow works without cache (null) ----------------------------------

	public function test_workflow_works_without_cache(): void {
		$workflow_no_cache = new Workflow( $this->conn, $this->queue, $this->logger );

		$wf_id = $this->create_workflow( 'nocache_wf', array( 'StepA' ), array( 'x' => 1 ) );

		$state = $workflow_no_cache->get_state( $wf_id );
		$this->assertNotNull( $state );
		$this->assertSame( 1, $state['x'] );

		$status = $workflow_no_cache->status( $wf_id );
		$this->assertNotNull( $status );
		$this->assertSame( WorkflowStatus::Running, $status->status );
	}

	// -- get_state returns null for nonexistent workflow ----------------------

	public function test_get_state_returns_null_for_nonexistent(): void {
		$this->assertNull( $this->workflow->get_state( 999999 ) );
	}

	// -- status returns null for nonexistent workflow -------------------------

	public function test_status_returns_null_for_nonexistent(): void {
		$this->assertNull( $this->workflow->status( 999999 ) );
	}
}
