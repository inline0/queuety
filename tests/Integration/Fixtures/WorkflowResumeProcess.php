<?php
/**
 * Independent process for the competing workflow resume regression test.
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
fwrite( STDOUT, "ready\n" );
fgets( STDIN );
try {
	$workflow->resume( (int) ( $argv[1] ?? 0 ) );
	fwrite( STDOUT, "resumed\n" );
} catch ( \RuntimeException $error ) {
	if ( ! str_contains( $error->getMessage(), 'is not paused' ) ) {
		throw $error;
	}
	fwrite( STDOUT, "superseded\n" );
}
