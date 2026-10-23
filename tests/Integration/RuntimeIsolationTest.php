<?php

namespace Queuety\Tests\Integration;

use Queuety\Connection;
use Queuety\Exceptions\CommitOutcomeUnknownException;
use Queuety\MysqliPdo;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\WorkerPool;

class RuntimeIsolationTest extends IntegrationTestCase {

	public function test_discard_invalidates_retained_handles_and_reconciled_reinit_clears_cache(): void {
		Queuety::reset();
		Queuety::init( $this->conn );
		Queuety::cache()->set( 'stale-state', 'unconfirmed' );
		$pdo = $this->conn->pdo();
		$options = $this->conn->worker_options();
		$this->conn->discard();
		try {
			$pdo->query( 'SELECT 1' );
			$this->fail( 'A retained discarded handle cannot be reused.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'discarded', $error->getMessage() );
		}
		$this->conn = new Connection( ...$options );
		try {
			Queuety::init( $this->conn );
			$this->assertNull( Queuety::cache()->get( 'stale-state' ) );
		} finally {
			Queuety::reset();
		}
	}

	public function test_short_lived_connections_close_without_cyclic_garbage_collection(): void {
		foreach ( array( 'mysqli', 'pdo' ) as $driver ) {
			$options = $this->conn->worker_options();
			$options['driver'] = $driver;
			for ( $index = 0; $index < 20; ++$index ) {
				$conn = new Connection( ...$options );
				$owner = \WeakReference::create( $conn );
				$transport = \WeakReference::create( $conn->pdo() );
				unset( $conn );
				$this->assertNull( $owner->get(), $driver . ' connection must not retain itself' );
				$this->assertNull( $transport->get(), $driver . ' transport must close promptly' );
			}
		}
	}

	public function test_mysqlnd_read_timeout_discards_transport_without_replay(): void {
		if ( ! MysqliPdo::supports_read_timeout() ) {
			$this->markTestSkipped( 'This control requires mysqlnd.' );
		}
		$options = $this->conn->worker_options();
		$options['driver'] = 'mysqli';
		$options['options'] = array( 'connect_timeout' => 3, 'read_timeout' => 1 );
		$conn = new Connection( ...$options );
		$pdo = $conn->pdo();
		$started = microtime( true );
		try {
			@$pdo->query( 'SELECT SLEEP(4)' );
			$this->fail( 'The configured read timeout must interrupt a long server read.' );
		} catch ( \PDOException $error ) {
			$this->assertContains( (int) $error->getCode(), array( 2006, 2013, 2055 ) );
		}
		$this->assertLessThan( 4.0, microtime( true ) - $started );
		$this->assertTrue( $conn->is_discarded() );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Reconcile durable state' );
		$conn->pdo();
	}

	public function test_mysqli_read_timeout_bounds_a_silent_server_handshake(): void {
		if ( ! MysqliPdo::supports_read_timeout() || ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'This transport probe needs mysqlnd and subprocess support.' );
		}
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $message );
		$this->assertIsResource( $server, $message );
		$address = stream_socket_get_name( $server, false );
		$script = 'require ' . var_export( dirname( __DIR__, 2 ) . '/vendor/autoload.php', true ) . ';'
			. '$options = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);'
			. '$start = microtime(true); try { $conn = new \\Queuety\\Connection(...$options); @$conn->pdo(); }'
			. 'catch (\\Throwable $error) { echo json_encode(["elapsed" => microtime(true) - $start, "error" => get_class($error)]); }';
		$process = proc_open( array( PHP_BINARY, '-r', $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite( $pipes[0], json_encode( array( 'host' => $address, 'dbname' => 'handshake_probe', 'user' => 'test', 'password' => '', 'driver' => 'mysqli', 'options' => array( 'connect_timeout' => 1, 'read_timeout' => 1 ) ), JSON_THROW_ON_ERROR ) );
		fclose( $pipes[0] );
		$peer = stream_socket_accept( $server, 3 );
		try {
			$this->assertIsResource( $peer );
			$deadline = microtime( true ) + 4.0;
			do {
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					break;
				}
				usleep( 10_000 );
			} while ( microtime( true ) < $deadline );
			$this->assertFalse( $status['running'], 'The handshake read must not wait indefinitely for a server greeting.' );
			$result = json_decode( stream_get_contents( $pipes[1] ), true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame( \PDOException::class, $result['error'] );
			$this->assertGreaterThanOrEqual( 0.5, $result['elapsed'] );
			$this->assertLessThan( 3.5, $result['elapsed'] );
		} finally {
			$status = proc_get_status( $process );
			if ( $status['running'] ) {
				proc_terminate( $process, 9 );
			}
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
			if ( is_resource( $peer ) ) {
				fclose( $peer );
			}
			fclose( $server );
		}
	}

	public function test_failed_commit_is_explicitly_unknown_for_both_transports(): void {
		foreach ( array( 'mysqli', 'pdo' ) as $driver ) {
			$options = $this->conn->worker_options();
			$options['driver'] = $driver;
			$conn = new Connection( ...$options );
			$pdo = $conn->pdo();
			$id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
			$pdo->beginTransaction();
			$this->conn->pdo()->exec( 'KILL CONNECTION ' . $id );
			try {
				@$pdo->commit();
				$this->fail( 'A lost COMMIT acknowledgement cannot be treated as success.' );
			} catch ( CommitOutcomeUnknownException $error ) {
				$this->assertInstanceOf( \PDOException::class, $error->getPrevious() );
			}
			$this->assertTrue( $conn->is_discarded(), $driver );
			$this->assertFalse( $pdo->inTransaction(), $driver );
		}
	}

	public function test_row_and_metadata_lock_limits_apply_to_both_transports(): void {
		foreach ( array( 'mysqli', 'pdo' ) as $driver ) {
			$options = $this->conn->worker_options();
			$options['driver'] = $driver;
			$options['options'] = array( 'connect_timeout' => 3, 'lock_wait_timeout' => 2, 'metadata_lock_timeout' => 3 );
			$conn = new Connection( ...$options );
			$row = $conn->pdo()->query( 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_limit, @@SESSION.lock_wait_timeout AS metadata_limit' )->fetch();
			$this->assertSame( 2, (int) $row['row_limit'], $driver );
			$this->assertSame( 3, (int) $row['metadata_limit'], $driver );
		}
	}

	public function test_blocked_row_updates_time_out_without_discarding_healthy_transport(): void {
		$this->assert_blocked_lock_times_out( false );
	}

	public function test_blocked_schema_changes_respect_metadata_lock_timeout(): void {
		$this->assert_blocked_lock_times_out( true );
	}

	private function assert_blocked_lock_times_out( bool $metadata ): void {
		$table = $this->conn->table( 'queuety_lock_timeout_probe' );
		$locker = $this->conn->pdo();
		$locker->exec( "CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB" );
		$locker->exec( "INSERT INTO {$table} VALUES (1, 0)" );
		try {
			foreach ( array( 'mysqli', 'pdo' ) as $driver ) {
				$options = $this->conn->worker_options();
				$options['driver'] = $driver;
				$options['options'] = array( 'connect_timeout' => 3, 'lock_wait_timeout' => 1, 'metadata_lock_timeout' => 1 );
				if ( 'mysqli' === $driver ) {
					$options['options']['read_timeout'] = 5;
				}
				$waiter = new Connection( ...$options );
				$pdo = $waiter->pdo();
				$locker->beginTransaction();
				if ( $metadata ) {
					$locker->query( "SELECT * FROM {$table}" )->fetchAll();
				} else {
					$locker->exec( "UPDATE {$table} SET value = 1 WHERE id = 1" );
				}
				$started = microtime( true );
				try {
					$pdo->exec( $metadata ? "ALTER TABLE {$table} ADD COLUMN blocked INT" : "UPDATE {$table} SET value = 2 WHERE id = 1" );
					$this->fail( 'The second session must wait for the first session lock.' );
				} catch ( \PDOException $error ) {
					$this->assertSame( 1205, (int) $error->errorInfo[1], $driver );
				}
				$elapsed = microtime( true ) - $started;
				$this->assertGreaterThanOrEqual( 0.5, $elapsed, $driver );
				$this->assertLessThan( 4.0, $elapsed, $driver );
				$this->assertFalse( $waiter->is_discarded(), $driver );
				$this->assertSame( 1, (int) $pdo->query( 'SELECT 1' )->fetchColumn(), $driver );
				$locker->rollBack();
				$waiter->disconnect();
			}
		} finally {
			if ( $locker->inTransaction() ) {
				$locker->rollBack();
			}
			$locker->exec( "DROP TABLE {$table}" );
		}
	}

	public function test_mysqli_does_not_change_host_application_report_mode(): void {
		$driver = new \mysqli_driver();
		$original = $driver->report_mode;
		try {
			mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
			$options = $this->conn->worker_options();
			$options['driver'] = 'mysqli';
			$conn = new Connection( ...$options );
			$conn->pdo()->query( 'SELECT 1' );
			$this->assertSame( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, $driver->report_mode );
		} finally {
			mysqli_report( $original );
		}
	}

	public function test_worker_pool_fresh_child_preserves_timeout_configuration(): void {
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'stream_socket_pair' ) ) {
			$this->markTestSkipped( 'Fork and socket-pair support are required.' );
		}
		$options = $this->conn->worker_options();
		$pool = new WorkerPool( 1, $options['host'], $options['dbname'], $options['user'], $options['password'], $options['prefix'], null, $options['table_prefix'], 'mysqli', array( 'connect_timeout' => 3, 'read_timeout' => 2, 'lock_wait_timeout' => 2, 'metadata_lock_timeout' => 3 ) );
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		$this->assertIsArray( $pair );
		$this->conn->disconnect();
		$pid = pcntl_fork();
		$this->assertNotSame( -1, $pid );
		if ( 0 === $pid ) {
			fclose( $pair[0] );
			try {
				$method = new \ReflectionMethod( $pool, 'connection' );
				$conn = $method->invoke( $pool );
				$row = $conn->pdo()->query( 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_limit, @@SESSION.lock_wait_timeout AS metadata_limit' )->fetch();
				fwrite( $pair[1], json_encode( array( 'limits' => $row, 'options' => $conn->worker_options()['options'] ), JSON_THROW_ON_ERROR ) );
				fclose( $pair[1] );
				exit( 0 );
			} catch ( \Throwable $error ) {
				fwrite( $pair[1], $error->getMessage() );
				exit( 1 );
			}
		}
		fclose( $pair[1] );
		$result = stream_get_contents( $pair[0] );
		fclose( $pair[0] );
		pcntl_waitpid( $pid, $status );
		$this->assertSame( 0, pcntl_wexitstatus( $status ), (string) $result );
		$data = json_decode( $result, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 2, (int) $data['limits']['row_limit'] );
		$this->assertSame( 3, (int) $data['limits']['metadata_limit'] );
		$this->assertSame( array( 'connect_timeout' => 3, 'read_timeout' => 2, 'lock_wait_timeout' => 2, 'metadata_lock_timeout' => 3 ), $data['options'] );
	}

	public function test_standalone_boot_has_no_wordpress_constants_or_functions(): void {
		$script = 'require ' . var_export( dirname( __DIR__, 2 ) . '/vendor/autoload.php', true ) . ';'
			. '$options = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);'
			. '$conn = new \\Queuety\\Connection(...$options);'
			. '\\Queuety\\Queuety::init($conn);'
			. 'echo json_encode([\\Queuety\\Queuety::is_initialized(), defined("ABSPATH"), defined("DB_HOST"), function_exists("add_action"), get_class(\\Queuety\\Queuety::cache())]);';
		$process = proc_open( array( PHP_BINARY, '-r', $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fwrite( $pipes[0], json_encode( $this->conn->worker_options(), JSON_THROW_ON_ERROR ) );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( array( true, false, false, false, 'Queuety\\Cache\\MemoryCache' ), json_decode( $output, true, 512, JSON_THROW_ON_ERROR ) );
	}
}
