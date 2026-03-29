<?php
/**
 * Minimal worker bootstrap. No WordPress.
 *
 * This file is used by WP-CLI workers to connect to the database
 * without booting WordPress. It parses wp-config.php via regex
 * to extract database credentials.
 *
 * Usage: This is loaded automatically by the worker process.
 *
 * @package Queuety
 */

$queuety_dir       = __DIR__;
$queuety_wp_config = null;

$queuety_search_dir = dirname( $queuety_dir );
for ( $queuety_i = 0; $queuety_i < 10; $queuety_i++ ) {
	if ( file_exists( $queuety_search_dir . '/wp-config.php' ) ) {
		$queuety_wp_config = $queuety_search_dir . '/wp-config.php';
		break;
	}
	$queuety_parent = dirname( $queuety_search_dir );
	if ( $queuety_parent === $queuety_search_dir ) {
		break;
	}
	$queuety_search_dir = $queuety_parent;
}

if ( null === $queuety_wp_config ) {
	fwrite( STDERR, "Queuety: Could not find wp-config.php\n" );
	exit( 1 );
}

require_once $queuety_dir . '/vendor/autoload.php';

$queuety_db = Queuety\ConfigParser::from_wp_config( $queuety_wp_config );

$queuety_conn = new Queuety\Connection(
	host: $queuety_db['host'],
	dbname: $queuety_db['name'],
	user: $queuety_db['user'],
	password: $queuety_db['password'],
	prefix: $queuety_db['prefix'],
);

Queuety\Queuety::init( $queuety_conn );

$queuety_handlers_file = dirname( $queuety_wp_config ) . '/queuety-handlers.php';
if ( file_exists( $queuety_handlers_file ) ) {
	require_once $queuety_handlers_file;
}
