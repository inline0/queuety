<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\Tests\IntegrationTestCase;

class StatePruningTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
	}

	// -- helpers ------------------------------------------------------------

	private function create_pruning_workflow( int $step_count, int $prune_after = 2 ): int {
		$builder = new WorkflowBuilder( 'prune_wf', $this->conn, $this->queue, $this->logger );
		for ( $i = 0; $i < $step_count; $i++ ) {
			$builder->then( 'Step' . $i );
		}
		$builder->prune_state_after( $prune_after );
		return $builder->dispatch( array( 'initial' => 'data' ) );
	}

	private function create_non_pruning_workflow( int $step_count ): int {
		$builder = new WorkflowBuilder( 'no_prune_wf', $this->conn, $this->queue, $this->logger );
		for ( $i = 0; $i < $step_count; $i++ ) {
			$builder->then( 'Step' . $i );
		}
		return $builder->dispatch( array( 'initial' => 'data' ) );
	}

	/**
	 * Get the internal state (including reserved keys) for a workflow.
	 */
	private function get_internal_state( int $wf_id ): array {
		return $this->workflow->get_state( $wf_id ) ?? array();
	}

	// -- state is pruned after configured number of steps -------------------

	public function test_state_pruned_after_configured_steps(): void {
		$wf_id = $this->create_pruning_workflow( 5, 2 );

		// Step 0: output some data.
		$job = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job->id, array( 'step0_data' => 'value0' ) );

		// Step 1: output some data.
		$job = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job->id, array( 'step1_data' => 'value1' ) );

		// Step 2: output some data. Now step 0 data should be pruned (current=2, cutoff=0).
		$job = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job->id, array( 'step2_data' => 'value2' ) );

		$state = $this->get_internal_state( $wf_id );

		// Step 0 data should be pruned.
		$this->assertArrayNotHasKey( 'step0_data', $state, 'Step 0 data should be pruned.' );
		// Step 1 and step 2 data should still exist.
		$this->assertArrayHasKey( 'step1_data', $state, 'Step 1 data should be preserved.' );
		$this->assertArrayHasKey( 'step2_data', $state, 'Step 2 data should be preserved.' );
	}

	// -- reserved keys never pruned -----------------------------------------

	public function test_reserved_keys_never_pruned(): void {
		$wf_id = $this->create_pruning_workflow( 5, 1 );

		// Run through 3 steps.
		for ( $i = 0; $i < 3; $i++ ) {
			$job = $this->queue->claim();
			$this->assertNotNull( $job, "Should be able to claim step {$i}." );
			$this->workflow->advance_step( $wf_id, $job->id, array( "step{$i}_out" => "v{$i}" ) );
		}

		$state = $this->get_internal_state( $wf_id );

		// Reserved keys should always be present.
		$this->assertArrayHasKey( '_steps', $state );
		$this->assertArrayHasKey( '_queue', $state );
		$this->assertArrayHasKey( '_priority', $state );
		$this->assertArrayHasKey( '_max_attempts', $state );
		$this->assertArrayHasKey( '_prune_state_after', $state );
		$this->assertArrayHasKey( '_step_outputs', $state );
	}

	// -- recent step data preserved -----------------------------------------

	public function test_recent_step_data_preserved(): void {
		$wf_id = $this->create_pruning_workflow( 6, 2 );

		// Run through 4 steps.
		for ( $i = 0; $i < 4; $i++ ) {
			$job = $this->queue->claim();
			$this->assertNotNull( $job );
			$this->workflow->advance_step( $wf_id, $job->id, array( "step{$i}_data" => "val{$i}" ) );
		}

		$state = $this->get_internal_state( $wf_id );

		// Steps 0 and 1 should be pruned (current=3, cutoff=1).
		$this->assertArrayNotHasKey( 'step0_data', $state );
		$this->assertArrayNotHasKey( 'step1_data', $state );

		// Steps 2 and 3 should still exist (recent).
		$this->assertArrayHasKey( 'step2_data', $state );
		$this->assertArrayHasKey( 'step3_data', $state );
	}

	// -- pruning disabled by default ----------------------------------------

	public function test_pruning_disabled_by_default(): void {
		$wf_id = $this->create_non_pruning_workflow( 5 );

		// Run through 4 steps.
		for ( $i = 0; $i < 4; $i++ ) {
			$job = $this->queue->claim();
			$this->assertNotNull( $job );
			$this->workflow->advance_step( $wf_id, $job->id, array( "step{$i}_data" => "val{$i}" ) );
		}

		$state = $this->get_internal_state( $wf_id );

		// ALL step data should still be present since pruning is disabled.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertArrayHasKey( "step{$i}_data", $state, "Step {$i} data should be preserved when pruning is disabled." );
		}
	}

	// -- initial payload preserved ------------------------------------------

	public function test_initial_payload_not_pruned_if_not_overwritten(): void {
		$wf_id = $this->create_pruning_workflow( 4, 1 );

		// Run through 3 steps with unique keys (not overlapping initial payload).
		for ( $i = 0; $i < 3; $i++ ) {
			$job = $this->queue->claim();
			$this->assertNotNull( $job );
			$this->workflow->advance_step( $wf_id, $job->id, array( "step{$i}_out" => "v{$i}" ) );
		}

		$state = $this->get_internal_state( $wf_id );

		// Initial payload key 'initial' was set before any step, so it's not tracked
		// in _step_outputs and should remain.
		$this->assertArrayHasKey( 'initial', $state );
		$this->assertSame( 'data', $state['initial'] );
	}
}
