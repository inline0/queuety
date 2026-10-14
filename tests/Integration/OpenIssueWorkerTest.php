<?php

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Queuety\Enums\JobStatus;
use Queuety\Config;
use Queuety\Handler;
use Queuety\Queuety;
use Queuety\Enums\WorkflowStatus;
use Queuety\Tests\IntegrationTestCase;

/** Persisted dispatch options and handler retry configuration. */
class OpenIssueWorkerTest extends IntegrationTestCase {

	/** Initialize a fresh facade for each test. */
	protected function setUp(): void {
		parent::setUp();
		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/** Release facade state before removing test tables. */
	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	/** A fresh worker receives limits persisted by the dispatch process. */
	public function test_dispatch_rate_limits_survive_a_fresh_runtime(): void {
		$first  = Queuety::dispatch( OpenIssueSuccessHandler::class, array( 'value' => 1 ) )->rate_limit( 1, 60 )->id();
		$second = Queuety::dispatch( OpenIssueSuccessHandler::class, array( 'value' => 2 ) )->rate_limit( 1, 60 )->id();
		Queuety::reset();
		Queuety::init( $this->conn );
		Queuety::run_worker( 'default', true );
		Queuety::run_worker( 'default', true );
		$this->assertSame( JobStatus::Completed, Queuety::queue()->find( $first )->status );
		$this->assertSame( JobStatus::Pending, Queuety::queue()->find( $second )->status );
	}

	/** Every failed attempt uses the corresponding configured delay. */
	public function test_list_backoff_controls_actual_retry_availability(): void {
		$id = Queuety::queue()->dispatch( OpenIssueRetryHandler::class, max_attempts: 4 );
		foreach ( array( 5, 30, 120 ) as $delay ) {
			$job = Queuety::queue()->claim();
			$this->assertNotNull( $job );
			$before = time();
			Queuety::worker()->process_job( $job );
			$retry = Queuety::queue()->find( $id );
			$this->assertSame( JobStatus::Pending, $retry->status );
			$this->assertGreaterThanOrEqual( $before + $delay, $retry->available_at->getTimestamp() );
			$this->assertLessThanOrEqual( time() + $delay, $retry->available_at->getTimestamp() );
			$this->raw_update( Config::table_jobs(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
		}
	}

	/** One-shot workers tick due schedules before claiming their first job. */
	public function test_once_runs_due_schedules(): void {
		$id = Queuety::schedule( OpenIssueSuccessHandler::class )->every( '1 minute' )->id();
		$this->raw_update( Config::table_schedules(), array( 'next_run' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		Queuety::run_worker( 'default', true );
		$this->assertSame( 1, Queuety::queue()->stats()['completed'] );
	}

	/** Flush ticks schedules even when no job was pending when it started. */
	public function test_flush_runs_due_schedules(): void {
		$id = Queuety::schedule( OpenIssueSuccessHandler::class )->every( '1 minute' )->id();
		$this->raw_update( Config::table_schedules(), array( 'next_run' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		$this->assertSame( 1, Queuety::worker()->flush() );
		$this->assertSame( 1, Queuety::queue()->stats()['completed'] );
	}

	/** Recovery happens in one-shot workers without waiting a minute. */
	public function test_once_recovers_stale_jobs(): void {
		$id = Queuety::queue()->dispatch( OpenIssueSuccessHandler::class, max_attempts: 3 );
		$this->assertNotNull( Queuety::queue()->claim() );
		$this->raw_update( Config::table_jobs(), array( 'reserved_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		Queuety::run_worker( 'default', true );
		$this->assertSame( JobStatus::Pending, Queuety::queue()->find( $id )->status );
	}

	/** Expired deadlines are checked before a one-shot worker claims work. */
	public function test_once_checks_workflow_deadlines(): void {
		$id = Queuety::workflow( 'expired' )->then( 'unused' )->must_complete_within( seconds: 1 )->dispatch();
		$this->raw_update( Config::table_workflows(), array( 'deadline_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		Queuety::run_worker( 'default', true );
		$this->assertSame( WorkflowStatus::Failed, Queuety::workflow_status( $id )->status );
		$stmt = $this->conn->pdo()->prepare( 'SELECT error_message FROM ' . $this->conn->table( Config::table_workflows() ) . ' WHERE id = :id' );
		$stmt->execute( array( 'id' => $id ) );
		$this->assertSame( 'Deadline exceeded', $stmt->fetchColumn() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_positive_log_retention_removes_only_expired_entries(): void {
		define( 'QUEUETY_LOG_RETENTION_DAYS', 1 );
		$logger = new \Queuety\Logger( $this->conn );
		$old = $logger->log( \Queuety\Enums\LogEvent::Completed );
		$recent = $logger->log( \Queuety\Enums\LogEvent::Completed );
		$this->raw_update( Config::table_logs(), array( 'created_at' => '2000-01-01 00:00:00' ), array( 'id' => $old ) );
		Queuety::run_worker( 'default', true );
		$stmt = $this->conn->pdo()->query( 'SELECT id FROM ' . $this->conn->table( Config::table_logs() ) . ' ORDER BY id' );
		$this->assertSame( array( $recent ), array_map( 'intval', $stmt->fetchAll( \PDO::FETCH_COLUMN ) ) );
	}

	/** Automatic retention keeps completed jobs that still unblock a chain. */
	public function test_retention_preserves_live_dependencies(): void {
		$first = Queuety::queue()->dispatch( OpenIssueSuccessHandler::class );
		Queuety::queue()->complete( $first );
		$this->raw_update( Config::table_jobs(), array( 'completed_at' => '2000-01-01 00:00:00' ), array( 'id' => $first ) );
		$second = Queuety::queue()->dispatch( OpenIssueSuccessHandler::class, depends_on: $first, delay: 300 );
		$unused = Queuety::queue()->dispatch( OpenIssueSuccessHandler::class );
		Queuety::queue()->complete( $unused );
		$this->raw_update( Config::table_jobs(), array( 'completed_at' => '2000-01-01 00:00:00' ), array( 'id' => $unused ) );
		Queuety::run_worker( 'default', true );
		$this->assertNotNull( Queuety::queue()->find( $first ) );
		$this->assertNotNull( Queuety::queue()->find( $second ) );
		$this->assertNull( Queuety::queue()->find( $unused ) );
	}
}

/** Handler without a configured rate limit. */
class OpenIssueSuccessHandler implements Handler {

	/**
	 * Execute one job.
	 *
	 * @param array<string, mixed> $payload Job data.
	 */
	public function handle( array $payload ): void {}

	/** Runtime configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array();
	}
}

/** Failing handler with configured retry delays. */
class OpenIssueRetryHandler extends OpenIssueSuccessHandler {

	/**
	 * Fail one attempt.
	 *
	 * @param array<string, mixed> $payload Job data.
	 */
	public function handle( array $payload ): void {
		throw new \RuntimeException( 'retry' );
	}

	/** Runtime configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array( 'backoff' => array( 5, 30, 120 ) );
	}
}
