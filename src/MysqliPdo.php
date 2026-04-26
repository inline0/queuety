<?php
// phpcs:ignoreFile -- Queuety intentionally provides a direct MySQL driver that is not routed through wpdb.
/**
 * PDO-compatible mysqli connection.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Minimal PDO-compatible mysqli driver for Queuety's MySQL runtime.
 */
class MysqliPdo extends \PDO {

	/**
	 * Active mysqli connection.
	 *
	 * @var \mysqli
	 */
	private \mysqli $mysqli;

	/**
	 * Whether a transaction is currently open.
	 *
	 * @var bool
	 */
	private bool $in_transaction = false;

	/**
	 * Initialize the mysqli-backed PDO facade.
	 *
	 * @param string $host     WordPress-style DB host.
	 * @param string $dbname   Database name.
	 * @param string $user     Database user.
	 * @param string $password Database password.
	 */
	public function __construct( string $host, string $dbname, string $user, string $password ) {
		$parts  = self::parse_host( $host );
		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			throw new \RuntimeException( 'Could not initialize mysqli.' );
		}

		mysqli_report( MYSQLI_REPORT_OFF );

		$connected = @mysqli_real_connect(
			$mysqli,
			$parts['host'],
			$user,
			$password,
			$dbname,
			$parts['port'],
			$parts['socket']
		);

		if ( false === $connected ) {
			throw self::exception_from_mysqli( $mysqli, 'Could not connect to MySQL through mysqli.' );
		}

		if ( false === mysqli_set_charset( $mysqli, 'utf8mb4' ) ) {
			throw self::exception_from_mysqli( $mysqli, 'Could not set MySQL charset.' );
		}

		$this->mysqli = $mysqli;
	}

	/**
	 * Whether mysqli can be used by this runtime.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return extension_loaded( 'mysqli' ) && function_exists( 'mysqli_init' );
	}

	/**
	 * Prepare a statement.
	 *
	 * @param string               $query   SQL query.
	 * @param array<string, mixed> $options Driver options.
	 * @return \PDOStatement|false
	 */
	public function prepare( string $query, array $options = array() ): \PDOStatement|false {
		unset( $options );

		return new MysqliPdoStatement( $this->mysqli, $query );
	}

	/**
	 * Run a raw SQL statement.
	 *
	 * @param string $statement SQL statement.
	 * @return int|false
	 */
	public function exec( string $statement ): int|false {
		$result = mysqli_query( $this->mysqli, $statement );
		if ( false === $result ) {
			throw self::exception_from_mysqli( $this->mysqli, 'MySQL statement failed.' );
		}

		if ( $result instanceof \mysqli_result ) {
			mysqli_free_result( $result );
		}

		return max( 0, (int) mysqli_affected_rows( $this->mysqli ) );
	}

	/**
	 * Run a raw SQL query and return a statement facade.
	 *
	 * @param string   $query         SQL query.
	 * @param int|null $fetchMode     Optional fetch mode.
	 * @param mixed    ...$fetchModeArgs Optional fetch-mode args.
	 * @return \PDOStatement|false
	 */
	public function query( string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs ): \PDOStatement|false {
		unset( $fetchMode, $fetchModeArgs );

		$statement = new MysqliPdoStatement( $this->mysqli, $query );
		$statement->execute();

		return $statement;
	}

	/**
	 * Last generated auto-increment ID.
	 *
	 * @param string|null $name Sequence name.
	 * @return string|false
	 */
	public function lastInsertId( ?string $name = null ): string|false {
		unset( $name );

		return (string) mysqli_insert_id( $this->mysqli );
	}

	/**
	 * Start a transaction.
	 *
	 * @return bool
	 */
	public function beginTransaction(): bool {
		$this->exec( 'START TRANSACTION' );
		$this->in_transaction = true;
		return true;
	}

	/**
	 * Commit the current transaction.
	 *
	 * @return bool
	 */
	public function commit(): bool {
		if ( ! $this->in_transaction ) {
			return false;
		}

		$this->exec( 'COMMIT' );
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Roll back the current transaction.
	 *
	 * @return bool
	 */
	public function rollBack(): bool {
		if ( ! $this->in_transaction ) {
			return false;
		}

		$this->exec( 'ROLLBACK' );
		$this->in_transaction = false;
		return true;
	}

	/**
	 * Whether a transaction is open.
	 *
	 * @return bool
	 */
	public function inTransaction(): bool {
		return $this->in_transaction;
	}

	/**
	 * Build a PDO-style exception from a mysqli failure.
	 *
	 * @param \mysqli $mysqli  Mysqli connection.
	 * @param string  $message Fallback message.
	 * @return \PDOException
	 */
	public static function exception_from_mysqli( \mysqli $mysqli, string $message ): \PDOException {
		$error = mysqli_connect_error() ?: '';
		$errno = mysqli_connect_errno();
		$state = '';

		try {
			$error = mysqli_error( $mysqli ) ?: $error;
			$errno = mysqli_errno( $mysqli ) ?: $errno;
			$state = mysqli_sqlstate( $mysqli ) ?: $state;
		} catch ( \Throwable ) {
			$state = 'HY000';
		}

		if ( '' !== $error ) {
			$message = $error;
		}

		$exception            = new \PDOException( $message, $errno );
		$exception->errorInfo = array( $state ?: 'HY000', $errno, $message ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Mirrors PDOException.

		return $exception;
	}

	/**
	 * Parse a WordPress-style DB_HOST value.
	 *
	 * @param string $host Raw DB_HOST value.
	 * @return array{host: string|null, port: int|null, socket: string|null}
	 */
	private static function parse_host( string $host ): array {
		$parts = array(
			'host'   => $host,
			'port'   => null,
			'socket' => null,
		);

		if ( str_starts_with( $host, '/' ) ) {
			$parts['host']   = 'localhost';
			$parts['socket'] = $host;
			return $parts;
		}

		if ( preg_match( '/^\[(.+)\](?::(\d+))?$/', $host, $matches ) ) {
			$parts['host'] = $matches[1];
			if ( ! empty( $matches[2] ) ) {
				$parts['port'] = (int) $matches[2];
			}
			return $parts;
		}

		if ( 1 === substr_count( $host, ':' ) ) {
			list( $base_host, $suffix ) = explode( ':', $host, 2 );
			if ( '' !== $suffix ) {
				if ( ctype_digit( $suffix ) ) {
					$parts['host'] = $base_host;
					$parts['port'] = (int) $suffix;
					return $parts;
				}

				if ( str_starts_with( $suffix, '/' ) ) {
					$parts['host']   = $base_host;
					$parts['socket'] = $suffix;
					return $parts;
				}
			}
		}

		return $parts;
	}
}
