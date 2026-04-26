<?php
/**
 * Plugin Name:  Queuety
 * Plugin URI:   https://github.com/inline0/queuety
 * Description:  A job queue and durable workflow engine for WordPress.
 * Version:      0.17.1
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

define( 'QUEUETY_VERSION', '0.17.1' );
define( 'QUEUETY_PLUGIN_FILE', __FILE__ );
define( 'QUEUETY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$queuety_autoloader = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $queuety_autoloader ) ) {
	$queuety_autoloader = dirname( __DIR__, 2 ) . '/autoload.php';
}
if ( file_exists( $queuety_autoloader ) ) {
	require_once $queuety_autoloader;
}

$queuety_runtime_error = null;

if ( ! extension_loaded( 'pdo_mysql' ) ) {
	$queuety_runtime_error = 'Queuety requires the pdo_mysql PHP extension on the PHP runtime that loads WordPress and WP-CLI.';
}

if ( null !== $queuety_runtime_error ) {
	$queuety_render_notice = static function () use ( $queuety_runtime_error ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $queuety_runtime_error )
		);
	};

	add_action( 'admin_notices', $queuety_render_notice );
	add_action( 'network_admin_notices', $queuety_render_notice );

	return;
}

/**
 * Build a Queuety connection from the current WordPress database credentials.
 *
 * @return Queuety\Connection
 */
$queuety_make_connection = static function () {
	global $wpdb;

	return new Queuety\Connection(
		host: DB_HOST,
		dbname: DB_NAME,
		user: DB_USER,
		password: DB_PASSWORD,
		prefix: $wpdb->prefix,
	);
};

add_action(
	'plugins_loaded',
	static function () use ( $queuety_make_connection ) {
		$conn = $queuety_make_connection();

		if ( ! Queuety\Schema::table_exists( $conn, $conn->table( Queuety\Config::table_jobs() ) ) ) {
			Queuety\Schema::install( $conn );
		}

		Queuety\Queuety::init( $conn );
	}
);

register_activation_hook(
	__FILE__,
	static function () use ( $queuety_make_connection ) {
		Queuety\Schema::install( $queuety_make_connection() );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'queuety_cron_process' );
	}
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'queuety_cron_process' ) ) {
			wp_schedule_event( time(), 'every_minute', 'queuety_cron_process' );
		}
	}
);

// phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval -- The plugin ships a one-minute cron fallback so jobs continue without a dedicated worker.
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
// phpcs:enable WordPress.WP.CronInterval.CronSchedulesInterval

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

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$queuety_cli_command = Queuety\Queuety::cli_command();

	\WP_CLI::add_command( $queuety_cli_command, Queuety\CLI\QueuetyCommand::class );
	\WP_CLI::add_command( $queuety_cli_command . ' workflow', Queuety\CLI\WorkflowCommand::class );
	\WP_CLI::add_command( $queuety_cli_command . ' log', Queuety\CLI\LogCommand::class );
	\WP_CLI::add_command( $queuety_cli_command . ' schedule', Queuety\CLI\ScheduleCommand::class );
	\WP_CLI::add_command( $queuety_cli_command . ' webhook', Queuety\CLI\WebhookCommand::class );
}
