<?php

namespace Queuety\Tests\Unit;

use Queuety\ConfigParser;
use Queuety\Tests\QueuetyTestCase;

class ConfigParserTest extends QueuetyTestCase {

	public function test_parses_standard_wp_config(): void {
		$config = <<<'PHP'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'secret' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'wp_';
PHP;

		$path = $this->tmp_dir . '/wp-config.php';
		file_put_contents( $path, $config );

		$result = ConfigParser::from_wp_config( $path );

		$this->assertSame( 'localhost', $result['host'] );
		$this->assertSame( 'wordpress', $result['name'] );
		$this->assertSame( 'root', $result['user'] );
		$this->assertSame( 'secret', $result['password'] );
		$this->assertSame( 'wp_', $result['prefix'] );
	}

	public function test_parses_double_quotes(): void {
		$config = <<<'PHP'
<?php
define("DB_NAME", "mydb");
define("DB_USER", "admin");
define("DB_PASSWORD", "pass123");
define("DB_HOST", "127.0.0.1");
$table_prefix = "myapp_";
PHP;

		$path = $this->tmp_dir . '/wp-config.php';
		file_put_contents( $path, $config );

		$result = ConfigParser::from_wp_config( $path );

		$this->assertSame( '127.0.0.1', $result['host'] );
		$this->assertSame( 'mydb', $result['name'] );
		$this->assertSame( 'admin', $result['user'] );
		$this->assertSame( 'pass123', $result['password'] );
		$this->assertSame( 'myapp_', $result['prefix'] );
	}

	public function test_defaults_prefix_to_wp(): void {
		$config = <<<'PHP'
<?php
define('DB_NAME', 'wp');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
PHP;

		$path = $this->tmp_dir . '/wp-config.php';
		file_put_contents( $path, $config );

		$result = ConfigParser::from_wp_config( $path );
		$this->assertSame( 'wp_', $result['prefix'] );
	}

	public function test_throws_on_missing_file(): void {
		$this->expectException( \RuntimeException::class );
		ConfigParser::from_wp_config( $this->tmp_dir . '/nonexistent.php' );
	}

	public function test_throws_on_missing_constant(): void {
		$config = <<<'PHP'
<?php
define('DB_NAME', 'wp');
PHP;

		$path = $this->tmp_dir . '/wp-config.php';
		file_put_contents( $path, $config );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'DB_HOST' );
		ConfigParser::from_wp_config( $path );
	}
}
