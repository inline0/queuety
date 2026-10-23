<?php

// This executable contract deliberately needs no PHPUnit, WordPress or shell API.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\ExecutionContext;
use Queuety\Exceptions\LostClaimException;
use Queuety\Exceptions\TerminalFailureException;
use Queuety\Exceptions\WaitException;
use Queuety\Handler;
use Queuety\Job;
use Queuety\Queuety;
use Queuety\Schema;
use Queuety\Step;

function verify_runtime( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

class RuntimeSmokeHandler implements Handler {

	public function handle( array $payload ): void {
		verify_runtime( ExecutionContext::remaining_seconds() > 0, 'A finite remaining budget is required.' );
		ExecutionContext::assert_active();
		if ( 'wait' === ( $payload['outcome'] ?? '' ) ) {
			throw new WaitException( new DateTimeImmutable( '+1 minute' ) );
		}
		if ( 'fail' === ( $payload['outcome'] ?? '' ) ) {
			throw new TerminalFailureException( 'Invalid authority.' );
		}
		ExecutionContext::on_completion( static function ( Connection $conn, Job $job ): void {
			$stmt = $conn->pdo()->prepare( 'INSERT INTO ' . $conn->table( 'receipts' ) . ' (id) VALUES (:id)' );
			$stmt->execute( array( 'id' => $job->id ) );
		} );
	}

	public function config(): array {
		return array();
	}
}

class RuntimeSmokeStep implements Step {

	public function handle( array $state ): array {
		return array( 'count' => ( $state['count'] ?? 0 ) + 1 );
	}

	public function config(): array {
		return array();
	}
}

verify_runtime( ! function_exists( 'add_action' ) && ! defined( 'ABSPATH' ) && ! defined( 'DB_NAME' ), 'Standalone startup loaded WordPress.' );
verify_runtime( ! function_exists( 'pcntl_alarm' ) && ! function_exists( 'proc_open' ), 'Run this proof with pcntl and process launching disabled.' );

foreach ( array( 'pdo', 'mysqli' ) as $driver ) {
	$conn = new Connection(
		host: getenv( 'QUEUETY_TEST_DB_HOST' ) ?: '127.0.0.1',
		dbname: getenv( 'QUEUETY_TEST_DB_NAME' ) ?: 'queuety_test',
		user: getenv( 'QUEUETY_TEST_DB_USER' ) ?: 'root',
		password: getenv( 'QUEUETY_TEST_DB_PASS' ) ?: '',
		prefix: ( getenv( 'QUEUETY_TEST_DB_PREFIX' ) ?: 'runtime_' ) . $driver . '_',
		driver: $driver,
		options: array( 'connect_timeout' => 3, 'lock_wait_timeout' => 1, 'metadata_lock_timeout' => 1 ) + ( 'mysqli' === $driver ? array( 'read_timeout' => 2 ) : array() ),
	);
	Schema::uninstall( $conn );
	Schema::install( $conn );
	$conn->pdo()->exec( 'CREATE TABLE ' . $conn->table( 'receipts' ) . ' (id BIGINT PRIMARY KEY) ENGINE=InnoDB' );
	try {
		Queuety::init( $conn );
		Queuety::register( 'smoke', RuntimeSmokeHandler::class );
		$queue = Queuety::queue();
		$id = $queue->dispatch( 'smoke', dispatch_key: 'one-action' );
		verify_runtime( $id === $queue->dispatch( 'smoke', dispatch_key: 'one-action' ), 'Durable deduplication failed.' );
		$second = $queue->dispatch( 'smoke', array( 'outcome' => 'wait' ) );
		Queuety::tick();
		verify_runtime( JobStatus::Completed === $queue->find( $id )->status, 'Completion failed.' );
		verify_runtime( JobStatus::Pending === $queue->find( $second )->status, 'Tick executed more than one action.' );
		verify_runtime( 1 === (int) $conn->pdo()->query( 'SELECT COUNT(*) FROM ' . $conn->table( 'receipts' ) )->fetchColumn(), 'Checkpoint did not commit with completion.' );
		Queuety::tick();
		verify_runtime( 0 === $queue->find( $second )->attempts, 'Wait consumed an attempt.' );
		$failed = $queue->dispatch( 'smoke', array( 'outcome' => 'fail' ) );
		Queuety::tick();
		verify_runtime( JobStatus::Buried === $queue->find( $failed )->status, 'Permanent failure was retried.' );

		$lease_id = $queue->dispatch( 'smoke', queue: 'leases' );
		$old = $queue->claim( 'leases', 30, 20 );
		$conn->pdo()->exec( 'UPDATE ' . $conn->table( Config::table_jobs() ) . ' SET lease_expires_at = NOW(6) - INTERVAL 1 SECOND WHERE id = ' . $lease_id );
		verify_runtime( $queue->recover( $old ), 'Expired lease was not recovered.' );
		$new = $queue->claim( 'leases', 150, 120 );
		verify_runtime( $old->claim_token !== $new->claim_token, 'Replacement reused its token.' );
		try {
			$queue->complete( $old->id, $old->claim_token );
			throw new RuntimeException( 'A stale owner completed a replacement claim.' );
		} catch ( LostClaimException $expected ) {
		}
		$queue->complete( $new->id, $new->claim_token );
		$workflow = Queuety::workflow( 'standalone-smoke' )->then( RuntimeSmokeStep::class )->then( RuntimeSmokeStep::class )->dispatch();
		Queuety::tick();
		Queuety::tick();
		verify_runtime( WorkflowStatus::Completed === Queuety::workflow_status( $workflow )->status, 'Standalone workflow failed.' );
		verify_runtime( 2 === Queuety::workflow_status( $workflow )->state['count'], 'Workflow state did not advance atomically.' );
		verify_runtime( array( 'cursor' => 1, 'processed' => 0 ) === Queuety::maintenance_batch( 2 ), 'Bounded maintenance contract failed.' );
		echo 'PASS standalone PHP ' . PHP_VERSION . ' ' . $driver . ' DB ' . $conn->pdo()->query( 'SELECT VERSION()' )->fetchColumn() . PHP_EOL;
	} finally {
		Queuety::reset();
		$conn->pdo()->exec( 'DROP TABLE ' . $conn->table( 'receipts' ) );
		Schema::uninstall( $conn );
		$conn->disconnect();
	}
}
