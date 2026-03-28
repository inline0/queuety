<?php
/**
 * Plugin Name:  Queuety
 * Plugin URI:   https://github.com/fabrikat/queuety
 * Description:  A job queue and durable workflow engine for WordPress.
 * Version:      0.1.0
 * Author:       Fabrikat
 * Author URI:   https://fabrikat.io
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.2
 * Requires at least: 6.4
 * Text Domain:  queuety
 *
 * @package Queuety
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUEUETY_VERSION', '0.1.0' );
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

// WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'queuety', Queuety\CLI\QueuetyCommand::class );
	\WP_CLI::add_command( 'queuety workflow', Queuety\CLI\WorkflowCommand::class );
	\WP_CLI::add_command( 'queuety log', Queuety\CLI\LogCommand::class );
}
