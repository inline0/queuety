<?php

namespace Queuety\Tests\Integration;

use Queuety\Tests\IntegrationTestCase;
use Queuety\WebhookNotifier;

class WebhookTest extends IntegrationTestCase {

	private WebhookNotifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->notifier = new WebhookNotifier( $this->conn );
	}

	public function test_register_returns_id(): void {
		$id = $this->notifier->register( 'job.completed', 'https://example.com/hook' );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_list_returns_registered_webhooks(): void {
		$this->notifier->register( 'job.completed', 'https://example.com/hook1' );
		$this->notifier->register( 'job.failed', 'https://example.com/hook2' );

		$list = $this->notifier->list();

		$this->assertCount( 2, $list );
		$this->assertSame( 'job.completed', $list[0]['event'] );
		$this->assertSame( 'https://example.com/hook1', $list[0]['url'] );
		$this->assertSame( 'job.failed', $list[1]['event'] );
	}

	public function test_remove_deletes_webhook(): void {
		$id = $this->notifier->register( 'job.completed', 'https://example.com/hook' );
		$this->notifier->remove( $id );

		$list = $this->notifier->list();
		$this->assertCount( 0, $list );
	}

	public function test_remove_nonexistent_webhook_does_not_throw(): void {
		$this->notifier->remove( 99999 );
		$this->assertTrue( true );
	}

	public function test_list_returns_empty_when_no_webhooks(): void {
		$list = $this->notifier->list();
		$this->assertSame( array(), $list );
	}

	public function test_notify_does_not_throw_on_invalid_url(): void {
		$this->notifier->register( 'job.completed', 'http://192.0.2.1:1/invalid' );

		// Should not throw, fire-and-forget.
		$this->notifier->notify( 'job.completed', array( 'job_id' => 1 ) );
		$this->assertTrue( true );
	}

	public function test_notify_does_not_throw_when_no_webhooks_registered(): void {
		$this->notifier->notify( 'job.completed', array( 'job_id' => 1 ) );
		$this->assertTrue( true );
	}

	public function test_multiple_webhooks_for_same_event(): void {
		$this->notifier->register( 'job.failed', 'https://example.com/hook1' );
		$this->notifier->register( 'job.failed', 'https://example.com/hook2' );

		$list = $this->notifier->list();
		$this->assertCount( 2, $list );

		// Both should be for the same event.
		$events = array_column( $list, 'event' );
		$this->assertSame( array( 'job.failed', 'job.failed' ), $events );
	}
}
