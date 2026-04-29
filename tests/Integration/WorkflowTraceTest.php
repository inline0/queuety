<?php
/**
 * Workflow trace integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowEventLog;
use Queuety\Worker;
use Queuety\Tests\Integration\Fixtures\TraceableStep;
use Queuety\Tests\Integration\Fixtures\TraceFailingStep;
use Queuety\Tests\IntegrationTestCase;

class WorkflowTraceTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private WorkflowEventLog $event_log;
	private Workflow $workflow;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		Queuety::reset();
		Queuety::init( $this->conn );

		$this->queue     = new Queue( $this->conn );
		$this->logger    = new Logger( $this->conn );
		$this->event_log = new WorkflowEventLog( $this->conn );
		$this->workflow  = new Workflow( $this->conn, $this->queue, $this->logger, null, $this->event_log );
		$this->worker    = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			new HandlerRegistry(),
			new Config(),
			event_log: $this->event_log,
		);
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_worker_records_complete_trace_for_step(): void {
		$workflow_id = ( new WorkflowBuilder( 'traceable', $this->conn, $this->queue, $this->logger ) )
			->then( TraceableStep::class, 'traceableNode' )
			->dispatch( array( 'source' => 'fixture-source' ) );

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$timeline = $this->event_log->get_timeline( $workflow_id );
		$this->assertSame( array( 'step_started', 'step_completed' ), array_column( $timeline, 'event' ) );

		$completed = $timeline[1];
		$this->assertSame( 'traceableNode', $completed['step_name'] );
		$this->assertSame( 'single', $completed['step_type'] );
		$this->assertSame( array( 'resolved' => 'fixture-source' ), $completed['input'] );
		$this->assertSame( array( 'result' => 'done' ), $completed['output'] );
		$this->assertSame( array( 'source' => 'fixture-source' ), $completed['state_before'] );
		$this->assertSame( array( 'source' => 'fixture-source', 'result' => 'done' ), $completed['state_after'] );
		$this->assertSame( 'tests/traceable-step', $completed['context']['ability'] );
		$this->assertSame( 'fixture', $completed['artifacts'][0]['key'] );

		$state = $this->workflow->get_state( $workflow_id );
		$this->assertArrayNotHasKey( '_queuety_trace', $state );

		$trace = Queuety::workflow_trace( $workflow_id );
		$this->assertSame( $workflow_id, (int) $trace['workflow']['id'] );
		$this->assertCount( 1, $trace['steps'] );
		$this->assertSame( 'step_completed', $trace['steps'][0]['latest']['event'] );
		$this->assertNotEmpty( $trace['jobs'] );
		$this->assertNotEmpty( $trace['logs'] );
	}

	public function test_worker_records_failure_trace_for_step(): void {
		$workflow_id = ( new WorkflowBuilder( 'trace_failure', $this->conn, $this->queue, $this->logger ) )
			->then( TraceFailingStep::class, 'failingNode' )
			->max_attempts( 1 )
			->dispatch( array( 'source' => 'failure-source' ) );

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$failed = array_values(
			array_filter(
				$this->event_log->get_timeline( $workflow_id ),
				static fn( array $event ): bool => 'step_failed' === $event['event']
			)
		);

		$this->assertCount( 1, $failed );
		$this->assertSame( 'failingNode', $failed[0]['step_name'] );
		$this->assertSame( array( 'resolved' => 'failure-source' ), $failed[0]['input'] );
		$this->assertSame( 'tests/failing-step', $failed[0]['context']['ability'] );
		$this->assertSame( 'Trace failure', $failed[0]['error']['message'] );
		$this->assertSame( \RuntimeException::class, $failed[0]['error']['class'] );
		$this->assertSame( 'Trace failure', $failed[0]['error_message'] );
	}
}
