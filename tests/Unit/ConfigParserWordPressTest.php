<?php
/**
 * WordPress configuration parser tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use Queuety\ConfigParser;
use Queuety\Tests\QueuetyTestCase;

/**
 * Tests for ConfigParser with WordPress configuration files.
 */
class ConfigParserWordPressTest extends QueuetyTestCase {

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

}
