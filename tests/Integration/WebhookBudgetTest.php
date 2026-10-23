<?php

namespace Queuety\Tests\Integration;

use Queuety\ExecutionContext;
use Queuety\Exceptions\TimeoutException;
use Queuety\Heartbeat;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\WebhookNotifier;

class WebhookBudgetTest extends IntegrationTestCase {

	private WebhookNotifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->notifier = new WebhookNotifier( $this->conn );
		ExecutionContext::clear();
		WebhookBudgetStream::$mode = 'ordinary';
		WebhookBudgetStream::$timeouts = array();
		$this->assertTrue( stream_wrapper_register( 'queuetybudget', WebhookBudgetStream::class ) );
	}

	protected function tearDown(): void {
		stream_wrapper_unregister( 'queuetybudget' );
		ExecutionContext::clear();
		Heartbeat::clear();
		parent::tearDown();
	}

	public function test_fanout_uses_remaining_budget_and_stops_when_exhausted(): void {
		$this->register_webhooks( 10 );
		WebhookBudgetStream::$mode = 'slow';
		ExecutionContext::set_deadline( hrtime( true ) + 120_000_000 );
		$started = hrtime( true );
		try {
			$this->notifier->notify( 'job.completed', array( 'job_id' => 1 ) );
			$this->fail( 'Exhausted delivery must stop fanout and propagate timeout.' );
		} catch ( TimeoutException $error ) {
			$this->assertLessThanOrEqual( 3, count( WebhookBudgetStream::$timeouts ) );
			$this->assertNotEmpty( WebhookBudgetStream::$timeouts );
			$this->assertLessThanOrEqual( 0.12, WebhookBudgetStream::$timeouts[0] );
			$this->assertLessThan( 0.4, ( hrtime( true ) - $started ) / 1_000_000_000 );
		}
	}

	public function test_scoped_alarm_exception_is_not_swallowed(): void {
		$this->register_webhooks( 3 );
		WebhookBudgetStream::$mode = 'alarm';
		try {
			$this->notifier->notify( 'job.completed', array() );
			$this->fail( 'The action alarm must propagate through best-effort delivery.' );
		} catch ( TimeoutException $error ) {
			$this->assertCount( 1, WebhookBudgetStream::$timeouts );
			$this->assertSame( 1.0, WebhookBudgetStream::$timeouts[0] );
		}
	}

	public function test_ordinary_delivery_failures_remain_best_effort(): void {
		$this->register_webhooks( 3 );
		$this->notifier->notify( 'job.completed', array() );
		$this->assertSame( array( 1.0, 1.0, 1.0 ), WebhookBudgetStream::$timeouts );
	}

	public function test_expired_budget_does_not_start_any_delivery(): void {
		$this->register_webhooks( 3 );
		ExecutionContext::set_deadline( hrtime( true ) - 1 );
		try {
			$this->notifier->notify( 'job.completed', array() );
			$this->fail( 'Expired actions must not start webhooks.' );
		} catch ( TimeoutException $error ) {
			$this->assertSame( array(), WebhookBudgetStream::$timeouts );
		}
	}

	public function test_post_commit_delivery_observes_budget_without_requiring_completed_claim(): void {
		$queue = new Queue( $this->conn );
		$id = $queue->dispatch( 'handler' );
		$job = $queue->claim();
		$queue->complete( $id, $job->claim_token );
		ExecutionContext::enter( $id, job: $job );
		Heartbeat::init( $id, $this->conn, $job->claim_token );
		ExecutionContext::set_deadline( hrtime( true ) + 1_000_000_000 );
		$this->register_webhooks( 1 );
		$this->notifier->notify( 'job.completed', array( 'job_id' => $id ) );
		$this->assertCount( 1, WebhookBudgetStream::$timeouts );
		$this->assertGreaterThan( 0.0, WebhookBudgetStream::$timeouts[0] );
		$this->assertLessThanOrEqual( 1.0, WebhookBudgetStream::$timeouts[0] );
	}

	private function register_webhooks( int $count ): void {
		for ( $i = 0; $i < $count; ++$i ) {
			$this->notifier->register( 'job.completed', 'queuetybudget://receiver/' . $i );
		}
	}
}

class WebhookBudgetStream {

	public $context;
	public static string $mode = 'ordinary';
	public static array $timeouts = array();

	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		$timeout = stream_context_get_options( $this->context )['http']['timeout'];
		self::$timeouts[] = $timeout;
		if ( 'alarm' === self::$mode ) {
			throw new TimeoutException( 1 );
		}
		if ( 'slow' === self::$mode ) {
			usleep( (int) ceil( min( $timeout, 0.05 ) * 1_000_000 ) + 1000 );
			return false;
		}
		throw new \RuntimeException( 'Simulated ordinary transport failure.' );
	}
}
