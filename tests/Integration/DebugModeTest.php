<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\LogEvent;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class DebugModeTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Worker $worker;
	private HandlerRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		// Enable debug mode for testing.
		if ( ! defined( 'QUEUETY_DEBUG' ) ) {
			define( 'QUEUETY_DEBUG', true );
		}

		$this->queue    = new Queue( $this->conn );
		$this->logger   = new Logger( $this->conn );
		$this->registry = new HandlerRegistry();
		$workflow        = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$workflow,
			$this->registry,
			new Config(),
		);

		SuccessHandler::reset();
	}

	public function test_debug_log_entries_written_when_enabled(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success', array( 'key' => 'val' ) );

		$this->worker->run( once: true );

		$logs = $this->logger->for_event( LogEvent::Debug );

		// When debug mode is on, we should have debug log entries.
		$this->assertNotEmpty( $logs );

		// Verify the entries have the expected structure.
		$first = $logs[0];
		$this->assertSame( 'debug', $first['event'] );
		$this->assertSame( '_worker', $first['handler'] );
	}

	public function test_debug_log_includes_claim_attempt_messages(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$this->queue->dispatch( 'success' );

		$this->worker->run( once: true );

		$logs = $this->logger->for_event( LogEvent::Debug );
		$messages = array();
		foreach ( $logs as $log ) {
			$context = json_decode( $log['context'] ?? '{}', true );
			if ( isset( $context['message'] ) ) {
				$messages[] = $context['message'];
			}
		}

		// Should have a claim attempt message.
		$has_claim_message = false;
		foreach ( $messages as $msg ) {
			if ( str_contains( $msg, 'claim' ) || str_contains( $msg, 'Resolving' ) ) {
				$has_claim_message = true;
				break;
			}
		}
		$this->assertTrue( $has_claim_message, 'Debug logs should contain claim or resolve messages.' );
	}

	public function test_debug_log_on_empty_queue(): void {
		// Run with nothing in the queue (once mode exits immediately).
		$this->worker->run( once: true );

		$logs = $this->logger->for_event( LogEvent::Debug );

		// Should have debug entries about claiming and finding nothing.
		$this->assertNotEmpty( $logs );
	}
}
