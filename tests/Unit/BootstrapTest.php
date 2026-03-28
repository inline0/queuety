<?php
/**
 * Bootstrap file tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use Queuety\ConfigParser;
use Queuety\Tests\QueuetyTestCase;

/**
 * Tests for the bootstrap.php file and ConfigParser.
 */
class BootstrapTest extends QueuetyTestCase {

	// -- ConfigParser extracts credentials correctly -------------------------

	public function test_config_parser_extracts_credentials(): void {
		$wp_config = $this->create_wp_config(
			host: 'localhost',
			name: 'wp_database',
			user: 'wp_user',
			password: 'secret_pass',
			prefix: 'wp_',
		);

		$result = ConfigParser::from_wp_config( $wp_config );

		$this->assertSame( 'localhost', $result['host'] );
		$this->assertSame( 'wp_database', $result['name'] );
		$this->assertSame( 'wp_user', $result['user'] );
		$this->assertSame( 'secret_pass', $result['password'] );
		$this->assertSame( 'wp_', $result['prefix'] );
	}

	// -- ConfigParser with custom prefix -------------------------------------

	public function test_config_parser_custom_prefix(): void {
		$wp_config = $this->create_wp_config(
			host: '127.0.0.1',
			name: 'mydb',
			user: 'root',
			password: '',
			prefix: 'custom_',
		);

		$result = ConfigParser::from_wp_config( $wp_config );

		$this->assertSame( '127.0.0.1', $result['host'] );
		$this->assertSame( 'custom_', $result['prefix'] );
	}

	// -- ConfigParser with double quotes -------------------------------------

	public function test_config_parser_double_quotes(): void {
		$content = <<<'PHP'
<?php
define( "DB_HOST", "db.example.com" );
define( "DB_NAME", "prod_db" );
define( "DB_USER", "admin" );
define( "DB_PASSWORD", "p@ssword123" );
$table_prefix = "prod_";
PHP;

		$path = $this->tmp_dir . '/wp-config-dq.php';
		file_put_contents( $path, $content );

		$result = ConfigParser::from_wp_config( $path );

		$this->assertSame( 'db.example.com', $result['host'] );
		$this->assertSame( 'prod_db', $result['name'] );
		$this->assertSame( 'prod_', $result['prefix'] );
	}

	// -- ConfigParser defaults prefix to wp_ when not present ----------------

	public function test_config_parser_default_prefix(): void {
		$content = <<<'PHP'
<?php
define( 'DB_HOST', 'localhost' );
define( 'DB_NAME', 'wpdb' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
PHP;

		$path = $this->tmp_dir . '/wp-config-noprefix.php';
		file_put_contents( $path, $content );

		$result = ConfigParser::from_wp_config( $path );

		$this->assertSame( 'wp_', $result['prefix'] );
	}

	// -- ConfigParser throws on missing constant -----------------------------

	public function test_config_parser_throws_on_missing_constant(): void {
		$content = <<<'PHP'
<?php
define( 'DB_HOST', 'localhost' );
define( 'DB_NAME', 'wpdb' );
// Missing DB_USER and DB_PASSWORD
PHP;

		$path = $this->tmp_dir . '/wp-config-incomplete.php';
		file_put_contents( $path, $content );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'DB_USER' );

		ConfigParser::from_wp_config( $path );
	}

	// -- ConfigParser throws on unreadable file ------------------------------

	public function test_config_parser_throws_on_unreadable_file(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot read' );

		ConfigParser::from_wp_config( '/nonexistent/path/wp-config.php' );
	}

	// -- Bootstrap finds wp-config.php at various depths ---------------------

	public function test_bootstrap_finds_wp_config_one_level_up(): void {
		$this->assert_wp_config_found_at_depth( 1 );
	}

	public function test_bootstrap_finds_wp_config_two_levels_up(): void {
		$this->assert_wp_config_found_at_depth( 2 );
	}

	public function test_bootstrap_finds_wp_config_three_levels_up(): void {
		$this->assert_wp_config_found_at_depth( 3 );
	}

	// -- Bootstrap fails when no wp-config.php exists ------------------------

	public function test_bootstrap_fails_when_no_wp_config(): void {
		// Create a deep directory structure with no wp-config.php anywhere.
		$deep_dir = $this->tmp_dir . '/a/b/c/d/e/f/g/h/i/j/k';
		mkdir( $deep_dir, 0755, true );

		$found = $this->simulate_wp_config_search( $deep_dir );
		$this->assertNull( $found );
	}

	// -- Helpers -------------------------------------------------------------

	/**
	 * Create a wp-config.php file with given credentials.
	 */
	private function create_wp_config(
		string $host,
		string $name,
		string $user,
		string $password,
		string $prefix,
	): string {
		$content = <<<PHP
<?php
define( 'DB_HOST', '{$host}' );
define( 'DB_NAME', '{$name}' );
define( 'DB_USER', '{$user}' );
define( 'DB_PASSWORD', '{$password}' );
\$table_prefix = '{$prefix}';
PHP;

		$path = $this->tmp_dir . '/wp-config.php';
		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * Assert that wp-config.php is found at the given depth from a plugin dir.
	 */
	private function assert_wp_config_found_at_depth( int $depth ): void {
		// Build directory: base/wp-content/plugins/queuety (depth=1)
		// or base/sub/wp-content/plugins/queuety (depth=2), etc.
		$base = $this->tmp_dir . '/depth_' . $depth;

		$plugin_parts = array();
		for ( $i = 1; $i < $depth; $i++ ) {
			$plugin_parts[] = 'level_' . $i;
		}
		$plugin_parts[] = 'wp-content';
		$plugin_parts[] = 'plugins';
		$plugin_parts[] = 'queuety';

		$plugin_dir = $base . '/' . implode( '/', $plugin_parts );
		mkdir( $plugin_dir, 0755, true );

		// Place wp-config.php at the base level.
		$wp_config_content = <<<'PHP'
<?php
define( 'DB_HOST', 'localhost' );
define( 'DB_NAME', 'testdb' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
$table_prefix = 'wp_';
PHP;
		file_put_contents( $base . '/wp-config.php', $wp_config_content );

		// Simulate the bootstrap search.
		$found = $this->simulate_wp_config_search( $plugin_dir );

		$this->assertNotNull( $found, "wp-config.php should be found at depth {$depth}." );
		$this->assertSame( $base . '/wp-config.php', $found );
	}

	/**
	 * Simulate the bootstrap.php wp-config.php search logic.
	 *
	 * Mirrors the search in bootstrap.php: walk up from dirname($start_dir).
	 */
	private function simulate_wp_config_search( string $start_dir ): ?string {
		$search_dir = dirname( $start_dir );
		for ( $i = 0; $i < 10; $i++ ) {
			if ( file_exists( $search_dir . '/wp-config.php' ) ) {
				return $search_dir . '/wp-config.php';
			}
			$parent = dirname( $search_dir );
			if ( $parent === $search_dir ) {
				break;
			}
			$search_dir = $parent;
		}
		return null;
	}
}
