<?php
/**
 * Plugin Name:  Queuety
 * Plugin URI:   https://github.com/inline0/queuety
 * Description:  A job queue and durable workflow engine for WordPress.
 * Version:      0.12.0
 * Author:       Queuety
 * License:      GPL-2.0-or-later
 * Requires PHP: 8.2
 * Requires at least: 6.4
 * Text Domain:  queuety
 *
 * @package Queuety
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUEUETY_VERSION', '0.12.0' );
define( 'QUEUETY_PLUGIN_FILE', __FILE__ );
define( 'QUEUETY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Autoloader: plugin mode or Composer package mode.
$queuety_autoloader = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $queuety_autoloader ) ) {
	$queuety_autoloader = dirname( __DIR__, 2 ) . '/autoload.php';
}
if ( file_exists( $queuety_autoloader ) ) {
	require_once $queuety_autoloader;
}

// Initialize Queuety with WordPress database credentials.
add_action(
	'plugins_loaded',
	function () {
		global $wpdb;

		$conn = new Queuety\Connection(
			host: DB_HOST,
			dbname: DB_NAME,
			user: DB_USER,
			password: DB_PASSWORD,
			prefix: $wpdb->prefix,
		);

		Queuety\Queuety::init( $conn );
	}
);

// Activation: create tables.
register_activation_hook(
	__FILE__,
	function () {
		global $wpdb;

		$conn = new Queuety\Connection(
			host: DB_HOST,
			dbname: DB_NAME,
			user: DB_USER,
			password: DB_PASSWORD,
			prefix: $wpdb->prefix,
		);

		Queuety\Schema::install( $conn );
	}
);

// Default processing via wp_cron: works on every host, no shell access needed.
// WP-CLI workers are the upgrade path for better performance.
add_action(
	'init',
	function () {
		// Register the cron schedule if not already.
		if ( ! wp_next_scheduled( 'queuety_cron_process' ) ) {
			wp_schedule_event( time(), 'every_minute', 'queuety_cron_process' );
		}
	}
);

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => 'Every Minute',
		);
		return $schedules;
	}
);

add_action(
	'queuety_cron_process',
	function () {
		try {
			$worker = Queuety\Queuety::worker();
			$worker->run( 'default', once: true );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Cron errors are non-fatal.
			unset( $e );
		}
	}
);

// WP-CLI commands (upgrade path for dedicated workers).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'queuety', Queuety\CLI\QueuetyCommand::class );
	\WP_CLI::add_command( 'queuety workflow', Queuety\CLI\WorkflowCommand::class );
	\WP_CLI::add_command( 'queuety log', Queuety\CLI\LogCommand::class );
	\WP_CLI::add_command( 'queuety schedule', Queuety\CLI\ScheduleCommand::class );
}
