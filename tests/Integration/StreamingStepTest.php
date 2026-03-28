<?php

namespace Queuety\Tests\Integration;

use Queuety\ChunkStore;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Heartbeat;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\SimpleStreamingStep;
use Queuety\Tests\Integration\Fixtures\ResumableStreamingStep;

class StreamingStepTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;
	private ChunkStore $chunk_store;

	protected function setUp(): void {
		parent::setUp();

		$this->queue       = new Queue( $this->conn );
		$this->logger      = new Logger( $this->conn );
		$this->workflow    = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry    = new HandlerRegistry();
		$this->chunk_store = new ChunkStore( $this->conn );
		$this->worker      = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
			chunk_store: $this->chunk_store,
		);

		Heartbeat::clear();
	}

	protected function tearDown(): void {
		Heartbeat::clear();
		parent::tearDown();
	}

	// -- helpers ------------------------------------------------------------

	/**
	 * Create a workflow with the given step classes.
	 *
	 * @param array  $steps           Step class names.
	 * @param array  $initial_payload Initial workflow state.
	 * @return int Workflow ID.
	 */
	private function create_workflow( array $steps, array $initial_payload = array() ): int {
		$builder = new WorkflowBuilder( 'streaming_test', $this->conn, $this->queue, $this->logger );
		foreach ( $steps as $step ) {
			$builder->then( $step );
		}
		return $builder->dispatch( $initial_payload );
	}

	// -- test_streaming_step_persists_chunks --------------------------------

	public function test_streaming_step_persists_chunks(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 5 ),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->assertSame( $wf_id, $job->workflow_id );

		// Manually persist chunks like the worker would (but we process the job to test the full flow).
		$this->worker->process_job( $job );

		// Chunks should have been cleared after completion, verify via workflow state.
		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 'chunk_0chunk_1chunk_2chunk_3chunk_4', $state['streamed_content'] );
		$this->assertSame( 5, $state['chunk_total'] );

		// Verify chunks are cleaned up.
		$remaining = $this->chunk_store->chunk_count( $job->id );
		$this->assertSame( 0, $remaining, 'Chunks should be cleared after successful completion.' );
	}

	// -- test_streaming_step_calls_on_complete ------------------------------

	public function test_streaming_step_calls_on_complete(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 3 ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 3, $state['chunk_total'] );
		$this->assertSame( 'chunk_0chunk_1chunk_2', $state['streamed_content'] );
	}

	// -- test_streaming_step_result_merges_into_workflow_state ---------------

	public function test_streaming_step_result_merges_into_workflow_state(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 2, 'existing_key' => 'preserved' ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$state = $this->workflow->get_state( $wf_id );

		// on_complete output is merged.
		$this->assertSame( 'chunk_0chunk_1', $state['streamed_content'] );
		$this->assertSame( 2, $state['chunk_total'] );

		// Original state keys are preserved.
		$this->assertSame( 'preserved', $state['existing_key'] );
	}

	// -- test_streaming_step_resume_with_existing_chunks --------------------

	public function test_streaming_step_resume_with_existing_chunks(): void {
		$wf_id = $this->create_workflow(
			array( ResumableStreamingStep::class ),
			array( 'chunk_count' => 5 ),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Simulate a partial failure: manually insert 3 chunks as if the previous
		// attempt persisted them before crashing.
		$this->chunk_store->append_chunk( $job->id, 0, 'chunk_0', $job->workflow_id, $job->step_index );
		$this->chunk_store->append_chunk( $job->id, 1, 'chunk_1', $job->workflow_id, $job->step_index );
		$this->chunk_store->append_chunk( $job->id, 2, 'chunk_2', $job->workflow_id, $job->step_index );

		// Process the job. ResumableStreamingStep skips existing chunks.
		$this->worker->process_job( $job );

		$state = $this->workflow->get_state( $wf_id );

		// All 5 chunks should be present in the final result.
		$this->assertSame( 'chunk_0chunk_1chunk_2chunk_3chunk_4', $state['streamed_content'] );
		$this->assertSame( 5, $state['chunk_total'] );

		// Chunks should be cleaned up after completion.
		$this->assertSame( 0, $this->chunk_store->chunk_count( $job->id ) );
	}

	// -- test_streaming_step_clears_chunks_on_completion ---------------------

	public function test_streaming_step_clears_chunks_on_completion(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 4 ),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		// Chunks should be cleared from the database.
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) AS cnt FROM {$table} WHERE job_id = :job_id"
		);
		$stmt->execute( array( 'job_id' => $job->id ) );
		$row = $stmt->fetch();

		$this->assertSame( 0, (int) $row['cnt'], 'Chunks should be cleared after streaming step completes.' );
	}

	// -- test_streaming_step_heartbeat_during_streaming ---------------------

	public function test_streaming_step_heartbeat_during_streaming(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 3 ),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Set reserved_at to 10 minutes ago to simulate stale.
		$old_time = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$this->raw_update(
			Config::table_jobs(),
			array( 'reserved_at' => $old_time ),
			array( 'id' => $job->id ),
		);

		// Process the job (heartbeats happen per chunk).
		$this->worker->process_job( $job );

		// The job should have been completed (not stale-killed).
		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	// -- test_streaming_step_in_workflow_context ----------------------------

	public function test_streaming_step_in_workflow_context(): void {
		// Build a 3-step workflow: regular -> streaming -> regular.
		$wf_id = $this->create_workflow(
			array(
				AccumulatingStep::class,
				SimpleStreamingStep::class,
				AccumulatingStep::class,
			),
			array( 'counter' => 0, 'chunk_count' => 3 ),
		);

		// Step 0: AccumulatingStep.
		$job0 = $this->queue->claim();
		$this->assertNotNull( $job0 );
		$this->assertSame( 0, $job0->step_index );
		$this->worker->process_job( $job0 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 1, $state['counter'] );

		// Step 1: SimpleStreamingStep.
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->assertSame( 1, $job1->step_index );
		$this->worker->process_job( $job1 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 'chunk_0chunk_1chunk_2', $state['streamed_content'] );
		$this->assertSame( 3, $state['chunk_total'] );

		// Step 2: AccumulatingStep.
		$job2 = $this->queue->claim();
		$this->assertNotNull( $job2 );
		$this->assertSame( 2, $job2->step_index );
		$this->worker->process_job( $job2 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertSame( 2, $state['counter'] );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	// -- test_streaming_step_chunk_count -----------------------------------

	public function test_streaming_step_chunk_count(): void {
		$wf_id = $this->create_workflow(
			array( SimpleStreamingStep::class ),
			array( 'chunk_count' => 4 ),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Before processing, no chunks.
		$this->assertSame( 0, $this->chunk_store->chunk_count( $job->id ) );

		// Manually insert chunks to test chunk_count.
		$this->chunk_store->append_chunk( $job->id, 0, 'a', $job->workflow_id, $job->step_index );
		$this->chunk_store->append_chunk( $job->id, 1, 'b', $job->workflow_id, $job->step_index );
		$this->chunk_store->append_chunk( $job->id, 2, 'c', $job->workflow_id, $job->step_index );

		$this->assertSame( 3, $this->chunk_store->chunk_count( $job->id ) );

		// Clean up before process_job to avoid double chunks.
		$this->chunk_store->clear_chunks( $job->id );

		$this->worker->process_job( $job );

		// After completion, chunks are cleared.
		$this->assertSame( 0, $this->chunk_store->chunk_count( $job->id ) );
	}

	// -- test_streaming_step_get_accumulated --------------------------------

	public function test_streaming_step_get_accumulated(): void {
		// Use chunk_store directly to test get_accumulated.
		$fake_job_id = 99999;

		$this->chunk_store->append_chunk( $fake_job_id, 0, 'Hello' );
		$this->chunk_store->append_chunk( $fake_job_id, 1, ' ' );
		$this->chunk_store->append_chunk( $fake_job_id, 2, 'World' );

		$this->assertSame( 'Hello World', $this->chunk_store->get_accumulated( $fake_job_id ) );

		// Clean up.
		$this->chunk_store->clear_chunks( $fake_job_id );
	}
}
