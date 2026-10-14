<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Queuety\Connection;
use Queuety\Exceptions\TimeoutException;
use Queuety\Middleware\Timeout;
use Queuety\Tests\QueuetyTestCase;

class OpenIssueRuntimeTest extends QueuetyTestCase {

	public static function server_versions(): iterable {
		yield 'MySQL minimum' => array( '8.0.0', true );
		yield 'MySQL suffix' => array( '8.4.2-commercial', true );
		yield 'MariaDB minimum' => array( '10.6.0-MariaDB', true );
		yield 'MariaDB compatibility prefix' => array( '5.5.5-10.6.22-MariaDB-0ubuntu0', true );
		yield 'old MySQL' => array( '5.7.44', false );
		yield 'old MariaDB' => array( '5.5.5-10.5.29-MariaDB', false );
		yield 'unknown server' => array( 'unknown', false );
	}

	#[DataProvider( 'server_versions' )]
	public function test_server_requirements( string $version, bool $supported ): void {
		if ( ! $supported ) {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'requires MySQL 8.0+ or MariaDB 10.6+' );
		}
		Connection::assert_server_version( $version );
		$this->addToAssertionCount( 1 );
	}

	public function test_missing_autoloader_reports_the_installation_problem(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process support is required.' );
		}
		copy( dirname( __DIR__, 2 ) . '/queuety.php', $this->tmp_dir . '/queuety.php' );
		$code = <<<'PHP'
<?php
define('ABSPATH', __DIR__ . '/');
function plugin_dir_path($file) { return dirname($file) . '/'; }
function add_action($hook, $callback) { $GLOBALS['actions'][$hook][] = $callback; }
function current_user_can($capability) { return true; }
function esc_html($text) { return htmlspecialchars($text); }
require __DIR__ . '/queuety.php';
foreach ($GLOBALS['actions']['admin_notices'] as $callback) { $callback(); }
PHP;
		$script = $this->tmp_dir . '/check.php';
		file_put_contents( $script, $code );
		$process = proc_open( array( PHP_BINARY, '-n', $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertStringContainsString( 'Composer autoloader', $output );
		$this->assertStringContainsString( 'composer install', $output );
		$this->assertStringNotContainsString( 'requires either mysqli', $output );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_timeout_interrupts_cpu_work_with_async_signals_initially_disabled(): void {
		if ( ! function_exists( 'pcntl_alarm' ) ) {
			$this->markTestSkipped( 'pcntl is required.' );
		}
		pcntl_async_signals( false );
		$started = hrtime( true );
		$exception = null;
		try {
			( new Timeout( 1 ) )->handle( new \stdClass(), static function (): void {
				$end = hrtime( true ) + 3_000_000_000;
				while ( hrtime( true ) < $end ) {}
			} );
		} catch ( TimeoutException $error ) {
			$exception = $error;
		}
		$this->assertInstanceOf( TimeoutException::class, $exception );
		$this->assertLessThan( 2.5, ( hrtime( true ) - $started ) / 1e9 );
		$this->assertFalse( pcntl_async_signals() );
		$this->assertSame( 0, pcntl_alarm( 0 ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_nested_middleware_keeps_the_earlier_outer_deadline(): void {
		if ( ! function_exists( 'pcntl_alarm' ) ) {
			$this->markTestSkipped( 'pcntl is required.' );
		}
		pcntl_async_signals( false );
		$exception = null;
		try {
			( new Timeout( 1 ) )->handle( new \stdClass(), static function ( object $job ): void {
				( new Timeout( 5 ) )->handle( $job, static function (): void {
					$end = hrtime( true ) + 3_000_000_000;
					while ( hrtime( true ) < $end ) {}
				} );
			} );
		} catch ( TimeoutException $error ) {
			$exception = $error;
		}
		$this->assertInstanceOf( TimeoutException::class, $exception );
		$this->assertStringContainsString( '1 seconds', $exception->getMessage() );
		$this->assertFalse( pcntl_async_signals() );
		$this->assertSame( 0, pcntl_alarm( 0 ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_middleware_restores_an_external_alarm_handler_and_deadline(): void {
		if ( ! function_exists( 'pcntl_alarm' ) ) {
			$this->markTestSkipped( 'pcntl is required.' );
		}
		pcntl_async_signals( false );
		$handler = static function (): void {};
		pcntl_signal( SIGALRM, $handler );
		pcntl_alarm( 10 );
		try {
			( new Timeout( 1 ) )->handle( new \stdClass(), static function (): void {} );
			$this->assertSame( $handler, pcntl_signal_get_handler( SIGALRM ) );
			$this->assertGreaterThanOrEqual( 9, pcntl_alarm( 0 ) );
			$this->assertFalse( pcntl_async_signals() );
		} finally {
			pcntl_alarm( 0 );
			pcntl_signal( SIGALRM, SIG_DFL );
		}
	}
}
