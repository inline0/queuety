<?php

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SignalHandler;

class WorkerSignalRegressionTest extends IntegrationTestCase {

	public static function modes(): iterable {
		if ( ! defined( 'SIGTERM' ) || ! defined( 'SIGINT' ) ) {
			yield 'no signals' => array( 'single', 0 );
			return;
		}

		yield 'single term' => array( 'single', SIGTERM );
		yield 'single interrupt' => array( 'single', SIGINT );
		yield 'pool' => array( 'pool', SIGTERM );
		yield 'adaptive pool' => array( 'adaptive', SIGTERM );
	}

	#[DataProvider( 'modes' )]
	public function test_signal_finishes_the_active_job_and_exits( string $mode, int $signal ): void {
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'posix_kill' ) || ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process and signal support is required.' );
		}
		$marker = $this->tmp_dir . '/signal';
		$queue  = new Queue( $this->conn );
		$id     = $queue->dispatch( 'signal-test', array( 'marker' => $marker ) );
		$script = $this->tmp_dir . '/worker.php';
		$bootstrap = dirname( __DIR__ ) . '/bootstrap.php';
		$code = '<?php require ' . var_export( $bootstrap, true ) . ';' . <<<'PHP'
$connection = new Queuety\Connection(QUEUETY_TEST_DB_HOST, QUEUETY_TEST_DB_NAME, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS, QUEUETY_TEST_DB_PREFIX, driver: 'pdo');
Queuety\Queuety::init($connection);
Queuety\Queuety::register('signal-test', Queuety\Tests\Integration\Fixtures\SignalHandler::class);
$connection->pdo();
PHP;
		$code .= match ( $mode ) {
			'pool' => 'Queuety\\Queuety::run_worker_pool(1);',
			'adaptive' => 'Queuety\\Queuety::run_auto_scaling_worker_pool(1, 2);',
			default => 'Queuety\\Queuety::run_worker();',
		};
		file_put_contents( $script, $code );
		$process = proc_open(
			array( PHP_BINARY, '-d', 'xdebug.mode=off', $script ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $this->tmp_dir . '/stdout', 'w' ), 2 => array( 'file', $this->tmp_dir . '/stderr', 'w' ) ),
			$pipes,
		);
		$this->assertIsResource( $process );
		$status = proc_get_status( $process );
		try {
			$deadline = microtime( true ) + 5;
			while ( ! file_exists( $marker . '.started' ) && microtime( true ) < $deadline ) {
				clearstatcache();
				usleep( 10_000 );
			}
			$this->assertFileExists( $marker . '.started', (string) file_get_contents( $this->tmp_dir . '/stderr' ) );
			posix_kill( $status['pid'], $signal );
			do {
				usleep( 20_000 );
				$status = proc_get_status( $process );
			} while ( $status['running'] && microtime( true ) < $deadline );
			$this->assertFalse( $status['running'], 'Worker did not shut down within the deadline.' );
			$this->assertSame( 0, $status['exitcode'], (string) file_get_contents( $this->tmp_dir . '/stderr' ) );
			$this->assertFileExists( $marker . '.finished' );
			$this->assertSame( JobStatus::Completed, $queue->find( $id )->status );
		} finally {
			if ( $status['running'] ) {
				posix_kill( $status['pid'], SIGKILL );
			}
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $process );
		}
	}
}
