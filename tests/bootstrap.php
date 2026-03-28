<?php
/**
 * PHPUnit bootstrap file.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constant stubs.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
}

// Integration test database configuration (env vars with defaults).
define( 'QUEUETY_TEST_DB_HOST', getenv( 'QUEUETY_TEST_DB_HOST' ) ?: '127.0.0.1' );
define( 'QUEUETY_TEST_DB_NAME', getenv( 'QUEUETY_TEST_DB_NAME' ) ?: 'queuety_test' );
define( 'QUEUETY_TEST_DB_USER', getenv( 'QUEUETY_TEST_DB_USER' ) ?: 'root' );
define( 'QUEUETY_TEST_DB_PASS', getenv( 'QUEUETY_TEST_DB_PASS' ) ?: '' );
define( 'QUEUETY_TEST_DB_PREFIX', getenv( 'QUEUETY_TEST_DB_PREFIX' ) ?: 'test_' );

// Queuety test constants.
define( 'QUEUETY_TEST_TMPDIR', sys_get_temp_dir() . '/queuety-test-' . getmypid() );
@mkdir( QUEUETY_TEST_TMPDIR, 0755, true );

register_shutdown_function(
	function () {
		$dir = QUEUETY_TEST_TMPDIR;
		if ( is_dir( $dir ) ) {
			$it    = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
			$files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getRealPath() );
				} else {
					unlink( $file->getRealPath() );
				}
			}
			rmdir( $dir );
		}
	}
);
