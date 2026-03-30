<?php
/**
 * PHPUnit bootstrap file.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Capture registered WordPress actions for tests.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Registered callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 * @return bool
	 */
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $_queuety_test_actions;

		if ( ! is_array( $_queuety_test_actions ) ) {
			$_queuety_test_actions = array();
		}

		$_queuety_test_actions[] = array(
			'hook'          => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Remove a captured WordPress action for tests.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Registered callback.
	 * @param int      $priority  Hook priority.
	 * @return bool
	 */
	function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
		global $_queuety_test_actions;

		if ( ! is_array( $_queuety_test_actions ) ) {
			return false;
		}

		foreach ( $_queuety_test_actions as $index => $action ) {
			if (
				$hook_name === ( $action['hook'] ?? null )
				&& $priority === ( $action['priority'] ?? null )
				&& $callback === ( $action['callback'] ?? null )
			) {
				unset( $_queuety_test_actions[ $index ] );
				$_queuety_test_actions = array_values( $_queuety_test_actions );
				return true;
			}
		}

		return false;
	}
}

global $_queuety_test_actions;
$_queuety_test_actions = array();

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
