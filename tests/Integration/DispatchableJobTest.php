<?php
/**
 * Integration tests for dispatchable job classes.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\HandlerRegistry;
use Queuety\JobSerializer;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SendEmailJob;

/**
 * Full lifecycle tests: create job class, dispatch, claim, process.
 */
class DispatchableJobTest extends IntegrationTestCase {

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
		SendEmailJob::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_dispatch_job_class_via_facade(): void {
		$job = new SendEmailJob( 'user@example.com', 'Hello', 'World' );
		$pending = Queuety::dispatch( $job );
		$id      = $pending->id();

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$queued = $this->queue->find( $id );
		$this->assertSame( SendEmailJob::class, $queued->handler );
		$this->assertSame( 'user@example.com', $queued->payload['to'] );
		$this->assertSame( 'Hello', $queued->payload['subject'] );
		$this->assertSame( 'World', $queued->payload['body'] );
	}

	public function test_dispatch_job_class_with_options(): void {
		$job = new SendEmailJob( 'admin@example.com', 'Test' );
		$id  = Queuety::dispatch( $job )
			->on_queue( 'emails' )
			->with_priority( Priority::High )
			->delay( 60 )
			->max_attempts( 5 )
			->id();

		$queued = $this->queue->find( $id );
		$this->assertSame( 'emails', $queued->queue );
		$this->assertSame( Priority::High, $queued->priority );
		$this->assertSame( 5, $queued->max_attempts );
	}

	public function test_process_dispatched_job_class(): void {
		$id = $this->queue->dispatch(
			SendEmailJob::class,
			array(
				'to'      => 'worker@example.com',
				'subject' => 'Worker Test',
				'body'    => 'Body content',
			),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );

		$this->assertCount( 1, SendEmailJob::$processed );
		$this->assertSame( 'worker@example.com', SendEmailJob::$processed[0]['to'] );
		$this->assertSame( 'Worker Test', SendEmailJob::$processed[0]['subject'] );
		$this->assertSame( 'Body content', SendEmailJob::$processed[0]['body'] );
	}

	public function test_full_lifecycle_dispatch_claim_process(): void {
		$job     = new SendEmailJob( 'full@test.com', 'Full Lifecycle' );
		$pending = Queuety::dispatch( $job );
		$id      = $pending->id();

		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );
		$this->assertSame( $id, $claimed->id );
		$this->assertSame( SendEmailJob::class, $claimed->handler );

		$this->worker->process_job( $claimed );

		$final = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $final->status );

		$this->assertCount( 1, SendEmailJob::$processed );
		$this->assertSame( 'full@test.com', SendEmailJob::$processed[0]['to'] );
	}

	public function test_job_with_default_parameter(): void {
		$id = $this->queue->dispatch(
			SendEmailJob::class,
			array(
				'to'      => 'default@test.com',
				'subject' => 'Defaults',
			),
		);

		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$this->assertCount( 1, SendEmailJob::$processed );
		$this->assertSame( 'Default body', SendEmailJob::$processed[0]['body'] );
	}

	public function test_flush_processes_multiple_job_classes(): void {
		$this->queue->dispatch(
			SendEmailJob::class,
			array( 'to' => 'a@test.com', 'subject' => 'A' ),
		);
		$this->queue->dispatch(
			SendEmailJob::class,
			array( 'to' => 'b@test.com', 'subject' => 'B' ),
		);

		$count = $this->worker->flush();

		$this->assertSame( 2, $count );
		$this->assertCount( 2, SendEmailJob::$processed );
	}

	public function test_serializer_roundtrip(): void {
		$original   = new SendEmailJob( 'rt@test.com', 'Roundtrip', 'Body' );
		$serialized = JobSerializer::serialize( $original );

		$this->assertSame( SendEmailJob::class, $serialized['handler'] );
		$this->assertSame( 'rt@test.com', $serialized['payload']['to'] );

		$restored = JobSerializer::deserialize( $serialized['handler'], $serialized['payload'] );
		$this->assertInstanceOf( SendEmailJob::class, $restored );
		$this->assertSame( 'rt@test.com', $restored->to );
		$this->assertSame( 'Roundtrip', $restored->subject );
		$this->assertSame( 'Body', $restored->body );
	}

	public function test_handler_registry_recognizes_job_class(): void {
		$this->assertTrue( $this->registry->is_job_class( SendEmailJob::class ) );
		$this->assertFalse( $this->registry->is_job_class( 'NonExistent\\Class' ) );
	}
}
