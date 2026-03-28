<?php
/**
 * Realistic workflow scenario tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\ConditionalStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\FlakyApiStep;
use Queuety\Tests\Integration\Fixtures\FormatOutputStep;
use Queuety\Tests\Integration\Fixtures\LlmProcessStep;
use Queuety\Worker;
use Queuety\Workflow;

/**
 * Realistic end-to-end workflow scenarios.
 *
 * These tests simulate real-world use cases: LLM pipelines, data processing
 * chains, flaky API retries, mid-workflow failures, and resume-from-failure.
 */
class WorkflowScenarioTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		Queuety::reset();
		Queuety::init( $this->conn );

		$this->queue        = Queuety::queue();
		$this->logger       = Queuety::logger();
		$this->workflow_mgr = Queuety::workflow_manager();
		$this->registry     = Queuety::registry();
		$this->worker       = Queuety::worker();

		LlmProcessStep::reset();
		FlakyApiStep::reset();

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/**
	 * Process exactly one job (claim + process), not the whole queue.
	 */
	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}
		$this->worker->process_job( $job );
		return $job;
	}

	public function test_llm_report_pipeline(): void {
		$wf_id = Queuety::workflow( 'generate_report' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 42 ) );

		// Process all 3 steps via flush().
		$this->worker->flush();

		// Verify final state.
		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 3, $status->current_step );
		$this->assertSame( '/reports/42.pdf', $status->state['report_url'] );
		$this->assertTrue( $status->state['email_sent'] );
		$this->assertSame( 'user42@example.com', $status->state['email_to'] );
		$this->assertSame( 42, $status->state['user_id'] );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );

		// Verify intermediate states via the workflow event log.
		$event_log = Queuety::workflow_events();

		// Step 0 (DataFetchStep) state snapshot.
		$step0_state = $event_log->get_state_at_step( $wf_id, 0 );
		$this->assertNotNull( $step0_state, 'Step 0 state snapshot should exist in event log.' );
		$this->assertSame( 'User #42', $step0_state['user_name'] );
		$this->assertSame( 42, $step0_state['order_count'] );
		$this->assertCount( 3, $step0_state['order_data'] );

		// Step 1 (LlmProcessStep) state snapshot.
		$step1_state = $event_log->get_state_at_step( $wf_id, 1 );
		$this->assertNotNull( $step1_state, 'Step 1 state snapshot should exist in event log.' );
		$this->assertStringContainsString( 'User #42', $step1_state['llm_response'] );
		$this->assertSame( 'mock-gpt-4', $step1_state['llm_model'] );

		// Step 2 (FormatOutputStep) state snapshot.
		$step2_state = $event_log->get_state_at_step( $wf_id, 2 );
		$this->assertNotNull( $step2_state, 'Step 2 state snapshot should exist in event log.' );
		$this->assertSame( '/reports/42.pdf', $step2_state['report_url'] );
		$this->assertTrue( $step2_state['email_sent'] );

		// Verify the timeline has 3 step_completed events.
		$timeline  = $event_log->get_timeline( $wf_id );
		$completed = array_filter( $timeline, fn( $e ) => 'step_completed' === $e['event'] );
		$this->assertCount( 3, $completed );

		// Verify LLM step received accumulated state from step 0.
		$this->assertCount( 1, LlmProcessStep::$received_states );
		$received = LlmProcessStep::$received_states[0];
		$this->assertSame( 'User #42', $received['user_name'] );
		$this->assertSame( 42, $received['order_count'] );
		$this->assertSame( 42, $received['user_id'] );
	}

	public function test_conditional_step_uses_prior_state(): void {
		$wf_id = Queuety::workflow( 'risk_analysis' )
			->then( DataFetchStep::class )
			->then( ConditionalStep::class )
			->dispatch( array( 'user_id' => 7 ) );

		// flush() processes the entire 2-step workflow.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );

		// DataFetchStep returns 3 orders, 1 refunded (33% rate).
		$this->assertSame( 'medium', $status->state['risk_level'] );
		$this->assertSame( 1, $status->state['refund_count'] );
		$this->assertEqualsWithDelta( 189.48, $status->state['total_revenue'], 0.01 );
		$this->assertFalse( $status->state['needs_review'] );
	}

	public function test_flaky_api_retry_and_recovery(): void {
		$wf_id = Queuety::workflow( 'flaky_pipeline' )
			->then( DataFetchStep::class )
			->then( FlakyApiStep::class )
			->then( FormatOutputStep::class )
			->max_attempts( 5 )
			->dispatch(
				array(
					'user_id'           => 99,
					'_flaky_key'        => 'test_flaky',
					'_flaky_fail_times' => 2,
					'_flaky_response'   => array(
						'llm_response' => 'Generated summary from flaky API',
						'llm_model'    => 'flaky-gpt',
						'llm_tokens'   => 100,
					),
				)
			);

		// Flush processes step 0. Step 1 fails twice (with backoff).
		// We need to reset backoff between flushes so the retries are claimable.
		$this->worker->flush();
		$this->reset_available_at_for_workflow( $wf_id );
		$this->worker->flush();
		$this->reset_available_at_for_workflow( $wf_id );
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/99.pdf', $status->state['report_url'] );

		// Verify the flaky step was called 3 times.
		$this->assertSame( 3, FlakyApiStep::$attempts['test_flaky'] );

		// Verify all calls received accumulated state from step 0.
		foreach ( FlakyApiStep::$calls as $call ) {
			if ( 'test_flaky' === $call['key'] ) {
				$this->assertSame( 'User #99', $call['state']['user_name'] );
				$this->assertSame( 42, $call['state']['order_count'] );
			}
		}
	}

	public function test_concurrent_workflows_isolated_state(): void {
		$wf_ids = array();
		foreach ( array( 1, 2, 3 ) as $user_id ) {
			$wf_ids[ $user_id ] = Queuety::workflow( "report_user_{$user_id}" )
				->then( DataFetchStep::class )
				->then( LlmProcessStep::class )
				->dispatch( array( 'user_id' => $user_id ) );
		}

		// flush() processes everything.
		$this->worker->flush();

		foreach ( $wf_ids as $user_id => $wf_id ) {
			$status = Queuety::workflow_status( $wf_id );
			$this->assertSame( WorkflowStatus::Completed, $status->status );
			$this->assertSame( $user_id, $status->state['user_id'] );
			$this->assertSame( "User #{$user_id}", $status->state['user_name'] );
			$this->assertSame( "user{$user_id}@example.com", $status->state['user_email'] );
			$this->assertStringContainsString( "User #{$user_id}", $status->state['llm_response'] );
		}
	}

	public function test_priority_workflow_processing(): void {
		$low_id = Queuety::workflow( 'low_priority' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->with_priority( Priority::Low )
			->dispatch( array( 'user_id' => 1 ) );

		$urgent_id = Queuety::workflow( 'urgent_report' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->with_priority( Priority::Urgent )
			->dispatch( array( 'user_id' => 999 ) );

		// First claim should be the urgent one.
		$first_job = $this->queue->claim();
		$this->assertNotNull( $first_job );
		$this->assertSame( $urgent_id, $first_job->workflow_id );

		// Process all.
		$this->queue->release( $first_job->id );
		$this->worker->flush();

		$low_status    = Queuety::workflow_status( $low_id );
		$urgent_status = Queuety::workflow_status( $urgent_id );

		$this->assertSame( WorkflowStatus::Completed, $low_status->status );
		$this->assertSame( WorkflowStatus::Completed, $urgent_status->status );
	}

	public function test_complete_workflow_log_trail(): void {
		$wf_id = Queuety::workflow( 'logged_workflow' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 10 ) );

		$this->worker->flush();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		$this->assertContains( 'workflow_started', $events );
		$this->assertContains( 'workflow_completed', $events );

		$started_count   = count( array_filter( $events, fn( $e ) => 'started' === $e ) );
		$completed_count = count( array_filter( $events, fn( $e ) => 'completed' === $e ) );

		$this->assertSame( 3, $started_count );
		$this->assertSame( 3, $completed_count );

		$completed_logs = array_filter( $logs, fn( $l ) => 'completed' === $l['event'] );
		foreach ( $completed_logs as $log ) {
			$this->assertNotNull( $log['duration_ms'] );
			$this->assertGreaterThanOrEqual( 0, (int) $log['duration_ms'] );
		}
	}

	public function test_retry_log_trail(): void {
		$wf_id = Queuety::workflow( 'retry_logs' )
			->then( FlakyApiStep::class )
			->max_attempts( 5 )
			->dispatch(
				array(
					'_flaky_key'        => 'log_retry',
					'_flaky_fail_times' => 2,
					'_flaky_response'   => array( 'result' => 'ok' ),
				)
			);

		// Attempt 1: fails.
		$this->process_one();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 2: fails.
		$this->process_one();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 3: succeeds.
		$this->process_one();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		$this->assertSame( 3, count( array_filter( $events, fn( $e ) => 'started' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'failed' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'retried' === $e ) ) );
		$this->assertSame( 1, count( array_filter( $events, fn( $e ) => 'completed' === $e ) ) );

		$failed_logs = array_filter( $logs, fn( $l ) => 'failed' === $l['event'] );
		foreach ( $failed_logs as $log ) {
			$this->assertStringContainsString( 'Simulated API failure', $log['error_message'] );
			$this->assertSame( 'RuntimeException', $log['error_class'] );
			$this->assertNotEmpty( $log['error_trace'] );
		}
	}

	/**
	 * Flush all pending jobs with available_at reset for a workflow.
	 */
	private function reset_available_at_for_workflow( int $wf_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET available_at = NOW() WHERE workflow_id = :wf_id AND status = 'pending'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
	}
}
