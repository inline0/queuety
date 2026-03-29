<?php
/**
 * Database connection handler.
 *
 * @package Queuety
 */

namespace Queuety;

use PDO;

/**
 * Direct PDO database connection that bypasses WordPress.
 *
 * @param string $host     Database host.
 * @param string $dbname   Database name.
 * @param string $user     Database user.
 * @param string $password Database password.
 * @param string $prefix   Table prefix (e.g. 'wp_').
 */
class Connection {

	/**
	 * Lazy-loaded PDO instance.
	 *
	 * @var PDO|null
	 */
	private ?PDO $pdo = null;

	/**
	 * Constructor.
	 *
	 * @param string $host     Database host.
	 * @param string $dbname   Database name.
	 * @param string $user     Database user.
	 * @param string $password Database password.
	 * @param string $prefix   Table prefix.
	 */
	public function __construct(
		private readonly string $host,
		private readonly string $dbname,
		private readonly string $user,
		private readonly string $password,
		private readonly string $prefix = 'wp_',
	) {}

	/**
	 * Get the PDO instance, creating it on first access.
	 *
	 * @return PDO
	 */
	public function pdo(): PDO {
		if ( null === $this->pdo ) {
			$this->pdo = new PDO(
				$this->build_dsn(),
				$this->user,
				$this->password,
				array(
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				)
			);
		}
		return $this->pdo;
	}

	/**
	 * Build a PDO DSN from a WordPress-style DB_HOST value.
	 *
	 * Supports:
	 * - host
	 * - host:port
	 * - /path/to/mysql.sock
	 * - host:/path/to/mysql.sock
	 * - [ipv6-host]:port
	 *
	 * @return string
	 */
	private function build_dsn(): string {
		$options = array(
			'dbname'  => $this->dbname,
			'charset' => 'utf8mb4',
		);

		$host = $this->host;

		if ( str_starts_with( $host, '/' ) ) {
			$options['unix_socket'] = $host;
			return $this->stringify_dsn_options( $options );
		}

		if ( preg_match( '/^\[(.+)\](?::(\d+))?$/', $host, $matches ) ) {
			$options['host'] = $matches[1];
			if ( ! empty( $matches[2] ) ) {
				$options['port'] = $matches[2];
			}
			return $this->stringify_dsn_options( $options );
		}

		if ( 1 === substr_count( $host, ':' ) ) {
			list( $base_host, $suffix ) = explode( ':', $host, 2 );

			if ( '' !== $suffix ) {
				if ( ctype_digit( $suffix ) ) {
					$options['host'] = $base_host;
					$options['port'] = $suffix;
					return $this->stringify_dsn_options( $options );
				}

				if ( str_starts_with( $suffix, '/' ) ) {
					$options['unix_socket'] = $suffix;
					return $this->stringify_dsn_options( $options );
				}
			}
		}

		$options['host'] = $host;

		return $this->stringify_dsn_options( $options );
	}

	/**
	 * Convert DSN options into a mysql:... string.
	 *
	 * @param array<string, string> $options DSN options.
	 * @return string
	 */
	private function stringify_dsn_options( array $options ): string {
		$parts = array();

		foreach ( $options as $key => $value ) {
			$parts[] = "{$key}={$value}";
		}

		return 'mysql:' . implode( ';', $parts );
	}

	/**
	 * Get the table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Get a fully prefixed table name.
	 *
	 * @param string $name Base table name (e.g. 'queuety_jobs').
	 * @return string Prefixed table name (e.g. 'wp_queuety_jobs').
	 */
	public function table( string $name ): string {
		return $this->prefix . $name;
	}
}
