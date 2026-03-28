<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\LogEvent;
use Queuety\Logger;
use Queuety\Tests\IntegrationTestCase;

class LoggerTest extends IntegrationTestCase {

	private Logger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new Logger( $this->conn );
	}

	public function test_log_writes_entry_and_returns_id(): void {
		$id = $this->logger->log(
			LogEvent::Started,
			array(
				'job_id'  => 1,
				'handler' => 'test_handler',
				'queue'   => 'default',
				'attempt' => 1,
			)
		);

		$this->assertGreaterThan( 0, $id );
	}

	public function test_for_job_returns_entries_for_that_job(): void {
		$this->logger->log( LogEvent::Started, array( 'job_id' => 10, 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Completed, array( 'job_id' => 10, 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Started, array( 'job_id' => 20, 'handler' => 'h' ) );

		$entries = $this->logger->for_job( 10 );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'started', $entries[0]['event'] );
		$this->assertSame( 'completed', $entries[1]['event'] );
	}

	public function test_for_workflow_returns_entries_for_that_workflow(): void {
		$this->logger->log( LogEvent::WorkflowStarted, array( 'workflow_id' => 5, 'handler' => 'wf' ) );
		$this->logger->log( LogEvent::WorkflowCompleted, array( 'workflow_id' => 5, 'handler' => 'wf' ) );
		$this->logger->log( LogEvent::Started, array( 'workflow_id' => 6, 'handler' => 'h' ) );

		$entries = $this->logger->for_workflow( 5 );

		$this->assertCount( 2, $entries );
	}

	public function test_for_handler_filters_by_handler_name(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'alpha' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'beta' ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'alpha' ) );

		$entries = $this->logger->for_handler( 'alpha' );

		$this->assertCount( 2, $entries );
		foreach ( $entries as $entry ) {
			$this->assertSame( 'alpha', $entry['handler'] );
		}
	}

	public function test_for_handler_respects_limit(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Failed, array( 'handler' => 'h' ) );

		$entries = $this->logger->for_handler( 'h', limit: 2 );

		$this->assertCount( 2, $entries );
	}

	public function test_for_event_filters_by_event_type(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h' ) );
		$this->logger->log( LogEvent::Failed, array( 'handler' => 'h' ) );

		$entries = $this->logger->for_event( LogEvent::Failed );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'failed', $entries[0]['event'] );
	}

	public function test_for_event_respects_limit(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'a' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'b' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'c' ) );

		$entries = $this->logger->for_event( LogEvent::Started, limit: 1 );

		$this->assertCount( 1, $entries );
	}

	public function test_since_filters_by_timestamp(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'old' ) );

		// Backdate that entry.
		$table = $this->conn->table( \Queuety\Config::table_logs() );
		$this->conn->pdo()->exec(
			"UPDATE {$table} SET created_at = '2020-01-01 00:00:00'"
		);

		$this->logger->log( LogEvent::Started, array( 'handler' => 'new' ) );

		$since   = new \DateTimeImmutable( '2024-01-01 00:00:00' );
		$entries = $this->logger->since( $since );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'new', $entries[0]['handler'] );
	}

	public function test_since_respects_limit(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'a' ) );
		$this->logger->log( LogEvent::Started, array( 'handler' => 'b' ) );

		$since   = new \DateTimeImmutable( '2020-01-01' );
		$entries = $this->logger->since( $since, limit: 1 );

		$this->assertCount( 1, $entries );
	}

	public function test_purge_deletes_old_entries(): void {
		$this->logger->log( LogEvent::Started, array( 'handler' => 'old' ) );

		$table = $this->conn->table( \Queuety\Config::table_logs() );
		$this->conn->pdo()->exec(
			"UPDATE {$table} SET created_at = '2020-01-01 00:00:00'"
		);

		$this->logger->log( LogEvent::Started, array( 'handler' => 'recent' ) );

		$deleted = $this->logger->purge( 30 );

		$this->assertSame( 1, $deleted );

		$all = $this->logger->since( new \DateTimeImmutable( '2000-01-01' ) );
		$this->assertCount( 1, $all );
		$this->assertSame( 'recent', $all[0]['handler'] );
	}

	public function test_log_with_context_json(): void {
		$id = $this->logger->log(
			LogEvent::Started,
			array(
				'handler' => 'h',
				'context' => array( 'user_id' => 42, 'action' => 'export' ),
			)
		);

		$entries = $this->logger->for_event( LogEvent::Started );
		$this->assertNotEmpty( $entries );

		$context = json_decode( $entries[0]['context'], true );
		$this->assertSame( 42, $context['user_id'] );
		$this->assertSame( 'export', $context['action'] );
	}

	public function test_log_with_error_details(): void {
		$id = $this->logger->log(
			LogEvent::Failed,
			array(
				'handler'       => 'h',
				'job_id'        => 1,
				'error_message' => 'Connection timed out',
				'error_class'   => \RuntimeException::class,
				'error_trace'   => '#0 some trace line',
			)
		);

		$entries = $this->logger->for_job( 1 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Connection timed out', $entries[0]['error_message'] );
		$this->assertSame( \RuntimeException::class, $entries[0]['error_class'] );
		$this->assertSame( '#0 some trace line', $entries[0]['error_trace'] );
	}

	public function test_for_job_returns_empty_array_for_unknown_job(): void {
		$this->assertSame( array(), $this->logger->for_job( 99999 ) );
	}
}
