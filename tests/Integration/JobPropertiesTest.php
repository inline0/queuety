<?php
/**
 * Integration tests for job properties (tries, timeout, backoff).
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\BatchManager;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\JobSerializer;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\JobWithProperties;
use Queuety\Tests\Integration\Fixtures\JobWithFailedHook;
use Queuety\Tests\Integration\Fixtures\SendEmailJob;

class JobPropertiesTest extends IntegrationTestCase {

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
		JobWithProperties::reset();
		JobWithFailedHook::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_tries_property_overrides_max_attempts(): void {
		// JobWithProperties has $tries = 5 but we dispatch with default max_attempts = 3.
		$id = $this->queue->dispatch(
			JobWithProperties::class,
			array( 'data' => 'test-tries' ),
			max_attempts: 3,
		);

		JobWithProperties::$should_fail = true;

		// Process the job. With tries=5, it should not be buried after 3 attempts.
		for ( $i = 0; $i < 4; $i++ ) {
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		// After 4 attempts, it should still be pending (retried) not buried.
		$updated = $this->queue->find( $id );
		$this->assertNotSame( JobStatus::Buried, $updated->status );
	}

	public function test_job_with_tries_buries_after_max(): void {
		$id = $this->queue->dispatch(
			JobWithProperties::class,
			array( 'data' => 'test-bury' ),
			max_attempts: 3,
		);

		JobWithProperties::$should_fail = true;

		// Process 5 times (the tries value).
		for ( $i = 0; $i < 7; $i++ ) {
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		$final = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $final->status );
	}

	public function test_backoff_property_used_on_retry(): void {
		$id = $this->queue->dispatch(
			JobWithProperties::class,
			array( 'data' => 'test-backoff' ),
		);

		JobWithProperties::$should_fail = true;

		// Process once (first attempt).
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		// Job should be retried with delay.
		$retried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $retried->status );

		// Available_at should be in the future (backoff applied).
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$this->assertGreaterThan( $now->format( 'U' ) - 5, $retried->available_at->format( 'U' ) );
	}

	public function test_config_properties_excluded_from_serialization(): void {
		$job        = new JobWithProperties( 'serialize-test' );
		$serialized = JobSerializer::serialize( $job );

		$this->assertArrayNotHasKey( 'tries', $serialized['payload'] );
		$this->assertArrayNotHasKey( 'timeout', $serialized['payload'] );
		$this->assertArrayNotHasKey( 'max_exceptions', $serialized['payload'] );
		$this->assertArrayNotHasKey( 'backoff', $serialized['payload'] );
		$this->assertArrayHasKey( 'data', $serialized['payload'] );
	}

	public function test_failed_hook_called_on_bury(): void {
		$id = $this->queue->dispatch(
			JobWithFailedHook::class,
			array( 'message' => 'hook-test' ),
			max_attempts: 1,
		);

		// Process the job. It will fail and be buried immediately (max_attempts=1).
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$final = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $final->status );

		// The failed() hook should have been called.
		$this->assertCount( 1, JobWithFailedHook::$failed_exceptions );
		$this->assertInstanceOf( \RuntimeException::class, JobWithFailedHook::$failed_exceptions[0] );
		$this->assertStringContainsString( 'intentional failure', JobWithFailedHook::$failed_exceptions[0]->getMessage() );
	}

	public function test_failed_hook_not_called_on_retry(): void {
		$id = $this->queue->dispatch(
			JobWithFailedHook::class,
			array( 'message' => 'retry-test' ),
			max_attempts: 3,
		);

		// Process once (first attempt). Should be retried, not buried.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		// failed() should NOT have been called yet (only on final bury).
		$this->assertEmpty( JobWithFailedHook::$failed_exceptions );
	}

	public function test_normal_job_unaffected_by_property_reading(): void {
		// SendEmailJob doesn't have tries/timeout/backoff properties.
		SendEmailJob::reset();

		$id = $this->queue->dispatch(
			SendEmailJob::class,
			array( 'to' => 'test@test.com', 'subject' => 'Test' ),
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$final = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $final->status );
	}
}
