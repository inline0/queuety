<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\AlphaCompensation;
use Queuety\Tests\Integration\Fixtures\BetaCompensation;
use Queuety\Tests\Integration\Fixtures\CompensationLog;
use Queuety\Tests\Integration\Fixtures\FailingStep;
use Queuety\Tests\Integration\Fixtures\MarkerStepAlpha;
use Queuety\Tests\Integration\Fixtures\MarkerStepBeta;
use Queuety\Tests\Integration\Fixtures\StructuredCancelHandler;
use Queuety\Tests\Integration\Fixtures\StructuredCompensation;
use Queuety\Tests\Integration\Fixtures\StructuredWorkflowHandlers;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;

class WorkflowCompensationTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			new HandlerRegistry(),
			new Config(),
		);

		CompensationLog::reset();
		StructuredWorkflowHandlers::reset();
	}

	private function process_one(): void {
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );
	}

	public function test_cancel_runs_step_compensations_in_reverse_order(): void {
		$builder = new WorkflowBuilder( 'comp_cancel', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( MarkerStepAlpha::class )
			->compensate_with( AlphaCompensation::class )
			->then( MarkerStepBeta::class )
			->compensate_with( BetaCompensation::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'workflow_ref' => 'cancelled' ) );

		$this->process_one();
		$this->process_one();

		$this->workflow->cancel( $wf_id );

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Cancelled, $status->status );
		$this->assertSame( array( 'beta', 'alpha' ), array_column( CompensationLog::$entries, 'label' ) );
		$this->assertArrayNotHasKey( '_steps', CompensationLog::$entries[0]['state'] );
	}

	public function test_compensate_on_failure_runs_and_blocks_retry(): void {
		$builder = new WorkflowBuilder( 'comp_fail', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( MarkerStepAlpha::class )
			->compensate_with( AlphaCompensation::class )
			->then( MarkerStepBeta::class )
			->compensate_with( BetaCompensation::class )
			->then( FailingStep::class )
			->compensate_on_failure()
			->max_attempts( 1 )
			->dispatch( array( 'workflow_ref' => 'failed' ) );

		$this->process_one();
		$this->process_one();
		$this->process_one();

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
		$this->assertSame( array( 'beta', 'alpha' ), array_column( CompensationLog::$entries, 'label' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already been compensated' );
		$this->workflow->retry( $wf_id );
	}

	public function test_failure_without_opt_in_does_not_compensate_and_can_retry(): void {
		$builder = new WorkflowBuilder( 'comp_optional', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->then( MarkerStepAlpha::class )
			->compensate_with( AlphaCompensation::class )
			->then( MarkerStepBeta::class )
			->compensate_with( BetaCompensation::class )
			->then( FailingStep::class )
			->max_attempts( 1 )
			->dispatch( array( 'workflow_ref' => 'retryable' ) );

		$this->process_one();
		$this->process_one();
		$this->process_one();

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );
		$this->assertSame( array(), CompensationLog::$entries );

		$this->workflow->retry( $wf_id );

		$retry = $this->queue->claim();
		$this->assertNotNull( $retry );
		$this->assertSame( FailingStep::class, $retry->handler );
	}

	public function test_signal_step_compensation_uses_resumed_state(): void {
		$builder = new WorkflowBuilder( 'comp_signal', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->wait_for_signal( 'approved' )
			->compensate_with( AlphaCompensation::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'workflow_ref' => 'signal' ) );

		$this->process_one();

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingForSignal, $status->status );

		$this->workflow->handle_signal(
			$wf_id,
			'approved',
			array(
				'approved_by' => 'ops@example.com',
			)
		);

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 'ops@example.com', $status->state['approved_by'] );

		$this->workflow->cancel( $wf_id );

		$this->assertSame( array( 'alpha' ), array_column( CompensationLog::$entries, 'label' ) );
		$this->assertSame( 'ops@example.com', CompensationLog::$entries[0]['state']['approved_by'] );
	}

	public function test_run_workflow_step_compensation_runs_after_child_completion(): void {
		$sub_builder = new WorkflowBuilder( 'comp_child', $this->conn, $this->queue, $this->logger );
		$sub_builder->then( AccumulatingStep::class );

		$builder = new WorkflowBuilder( 'comp_parent', $this->conn, $this->queue, $this->logger );
		$wf_id   = $builder
			->run_workflow( 'child', $sub_builder )
			->compensate_with( AlphaCompensation::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'workflow_ref' => 'sub' ) );

		$this->process_one(); // parent placeholder dispatches run-workflow
		$this->process_one(); // child completes and advances parent

		$status = $this->workflow->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 1, $status->state['counter'] );

		$this->workflow->cancel( $wf_id );

		$this->assertSame( array( 'alpha' ), array_column( CompensationLog::$entries, 'label' ) );
		$this->assertSame( 1, CompensationLog::$entries[0]['state']['counter'] );
	}

	public function test_structured_cancel_and_compensation_handlers_receive_payload(): void {
		$definition = array(
			'name'           => 'structured_handlers',
			'cancel_handler' => array(
				'class'   => StructuredCancelHandler::class,
				'payload' => array( 'reason' => 'user_requested' ),
			),
			'steps'          => array(
				array(
					'type'         => 'single',
					'class'        => MarkerStepAlpha::class,
					'name'         => 'alpha',
					'compensation' => array(
						'class'   => StructuredCompensation::class,
						'payload' => array( 'step' => 'alpha' ),
					),
				),
				array(
					'type'  => 'single',
					'class' => AccumulatingStep::class,
					'name'  => 'counter',
				),
			),
		);

		$wf_id = $this->workflow->dispatch_definition( $definition, array( 'workflow_ref' => 'structured' ) );

		$this->process_one();
		$this->workflow->cancel( $wf_id );

		$this->assertSame( 'alpha', StructuredWorkflowHandlers::$calls['compensation'][0]['payload']['step'] );
		$this->assertSame( 'user_requested', StructuredWorkflowHandlers::$calls['cancel'][0]['payload']['reason'] );
		$this->assertSame( 'structured', StructuredWorkflowHandlers::$calls['cancel'][0]['state']['workflow_ref'] );
	}
}
