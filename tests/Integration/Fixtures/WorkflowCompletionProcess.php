<?php
/**
 * Independent producer for the concurrent dependency completion regression.
 *
 * @package Queuety
 */

if ( __FILE__ !== realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
	return;
}

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

$conn = new \Queuety\Connection(
	getenv( 'QUEUETY_TEST_DB_HOST' ) ?: '127.0.0.1',
	getenv( 'QUEUETY_TEST_DB_NAME' ) ?: 'queuety_test',
	getenv( 'QUEUETY_TEST_DB_USER' ) ?: 'root',
	getenv( 'QUEUETY_TEST_DB_PASS' ) ?: '',
	getenv( 'QUEUETY_TEST_DB_PREFIX' ) ?: 'test_',
);
$queue = new \Queuety\Queue( $conn );
$workflow = new \Queuety\Workflow( $conn, $queue, new \Queuety\Logger( $conn ) );
$job = $queue->find( (int) ( $argv[1] ?? 0 ) );
if ( null === $job || null === $job->workflow_id ) {
	throw new \RuntimeException( 'Expected a claimed workflow producer.' );
}
fwrite( STDOUT, "ready\n" );
fgets( STDIN );
if ( isset( $argv[2] ) ) {
	fwrite( STDOUT, "started\n" );
}
$workflow->advance_step( $job->workflow_id, $job->id, array( 'result' => $job->id ), job: $job );
fwrite( STDOUT, "completed\n" );
