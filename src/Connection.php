<?php
/**
 * Database connection handler.
 *
 * @package Queuety
 */

namespace Queuety;

use PDO;

/**
 * Database connection that chooses the best available MySQL transport.
 *
 * @param string $host     Database host.
 * @param string $dbname   Database name.
 * @param string $user     Database user.
 * @param string $password Database password.
 * @param string      $prefix       WordPress table prefix (e.g. 'wp_').
 * @param string|null $table_prefix Queuety table prefix after the WordPress prefix.
 * @param string|null $driver       Optional driver override: auto, mysqli, or pdo.
 */
class Connection {

	/**
	 * Lazy-loaded PDO instance.
	 *
	 * @var PDO|null
	 */
	private ?PDO $pdo = null;

	/**
	 * Whether a transport failure requires explicit durable-state reconciliation.
	 *
	 * @var bool
	 */
	private bool $discarded = false;

	/**
	 * Constructor.
	 *
	 * @param string             $host     Database host.
	 * @param string             $dbname   Database name.
	 * @param string             $user     Database user.
	 * @param string             $password Database password.
	 * @param string             $prefix       WordPress table prefix.
	 * @param string|null        $table_prefix Queuety table prefix after the WordPress prefix. Defaults to Config::table_prefix().
	 * @param string|null        $driver       Optional driver override.
	 * @param array<string, int> $options Optional connect_timeout, read_timeout, lock_wait_timeout and metadata_lock_timeout in seconds.
	 * @throws \InvalidArgumentException When a timeout option is invalid.
	 */
	public function __construct(
		private readonly string $host,
		private readonly string $dbname,
		private readonly string $user,
		private readonly string $password,
		private readonly string $prefix = 'wp_',
		private readonly ?string $table_prefix = null,
		private readonly ?string $driver = null,
		private readonly array $options = array(),
	) {
		foreach ( $this->options as $name => $value ) {
			if ( ! in_array( $name, array( 'connect_timeout', 'read_timeout', 'lock_wait_timeout', 'metadata_lock_timeout' ), true ) || ! is_int( $value ) || $value < 1 ) {
				throw new \InvalidArgumentException( 'Connection timeouts must use supported option names and positive integer seconds.' );
			}
		}
	}

	/**
	 * Get the PDO instance, creating it on first access.
	 *
	 * @return PDO
	 * @throws \RuntimeException When the server version is unsupported or cannot be identified.
	 */
	public function pdo(): PDO {
		if ( $this->discarded ) {
			throw new \RuntimeException( 'The Queuety connection was discarded after a database failure. Reconcile durable state using a new Connection before replaying writes.' );
		}
		if ( null === $this->pdo ) {
			$pdo = $this->create_pdo();
			$statement = $pdo->query( 'SELECT VERSION()' );
			$version = false === $statement ? false : $statement->fetchColumn();
			if ( ! is_string( $version ) ) {
				throw new \RuntimeException( 'Queuety could not determine the database server version.' );
			}
			self::assert_server_version( $version );
			$pdo->exec( "SET SESSION time_zone = '+00:00'" );
			if ( isset( $this->options['lock_wait_timeout'] ) ) {
				$pdo->exec( 'SET SESSION innodb_lock_wait_timeout = ' . $this->options['lock_wait_timeout'] );
			}
			if ( isset( $this->options['metadata_lock_timeout'] ) ) {
				$pdo->exec( 'SET SESSION lock_wait_timeout = ' . $this->options['metadata_lock_timeout'] );
			}
			$this->pdo = $pdo;
		}
		return $this->pdo;
	}

	/**
	 * Check the server requirements for SKIP LOCKED claims.
	 *
	 * @param string $version Database server version string.
	 * @throws \RuntimeException When the server cannot support concurrent claims.
	 */
	public static function assert_server_version( string $version ): void {
		$is_mariadb = false !== stripos( $version, 'mariadb' );
		$number = $is_mariadb ? preg_replace( '/^5\.5\.5-/', '', $version ) : $version;
		$minimum = $is_mariadb ? '10.6.0' : '8.0.0';
		if ( null === $number || ! preg_match( '/^(\d+\.\d+\.\d+)/', $number, $matches ) || version_compare( $matches[1], $minimum, '<' ) ) {
			throw new \RuntimeException( 'Queuety requires MySQL 8.0+ or MariaDB 10.6+ for SKIP LOCKED claims. Server version: ' . $version );
		}
	}

	/**
	 * Active DB driver that will be used by this connection.
	 *
	 * @return string
	 */
	public function driver(): string {
		return $this->resolve_driver();
	}

	/**
	 * Connection settings for fresh worker processes.
	 *
	 * @return array{host: string, dbname: string, user: string, password: string, prefix: string, table_prefix: string|null, driver: string, options: array<string, int>}
	 */
	public function worker_options(): array {
		return array(
			'host'         => $this->host,
			'dbname'       => $this->dbname,
			'user'         => $this->user,
			'password'     => $this->password,
			'prefix'       => $this->prefix,
			'table_prefix' => $this->table_prefix,
			'driver'       => $this->driver(),
			'options'      => $this->options,
		);
	}

	/**
	 * Stable installation identity for cache and migration isolation.
	 *
	 * @return string
	 */
	public function namespace(): string {
		return hash( 'sha256', implode( "\0", array( $this->host, $this->dbname, $this->table( Config::table_schema() ), $this->table( Config::table_jobs() ) ) ) );
	}

	/**
	 * Report driver controls without promising a hard wall-clock deadline.
	 *
	 * Connect controls limit socket connection attempts, not handshake reads.
	 * PDO MySQL cannot set a supported per-connection read timeout here.
	 * Session lock limits affect their respective lock waits on either driver.
	 *
	 * @return array{connect_timeout: bool, read_timeout: bool, lock_wait_timeout: bool, metadata_lock_timeout: bool, hard_deadline: bool}
	 */
	public function timeout_capabilities(): array {
		return array(
			'connect_timeout' => true,
			'read_timeout' => 'mysqli' === $this->driver() && MysqliPdo::supports_read_timeout(),
			'lock_wait_timeout' => true,
			'metadata_lock_timeout' => true,
			'hard_deadline' => false,
		);
	}

	/** Discard an uncertain transport without silently reconnecting or retrying. */
	public function discard(): void {
		$this->discarded = true;
		$pdo = $this->pdo;
		$this->pdo = null;
		if ( $pdo instanceof MysqliPdo || $pdo instanceof MysqlPdo ) {
			$pdo->discard();
		}
	}

	/** Whether this connection requires a new instance and durable-state reconciliation. */
	public function is_discarded(): bool {
		return $this->discarded;
	}

	/**
	 * Close the queue transport before a fork so children inherit no live handle.
	 *
	 * @throws \RuntimeException When an active transaction would be lost.
	 */
	public function disconnect(): void {
		if ( null !== $this->pdo && $this->pdo->inTransaction() ) {
			throw new \RuntimeException( 'Cannot disconnect the queue connection during an active transaction.' );
		}
		$this->pdo = null;
	}

	/**
	 * Whether this runtime has at least one supported DB driver.
	 *
	 * @return bool
	 */
	public static function has_available_driver(): bool {
		return extension_loaded( 'pdo_mysql' ) || MysqliPdo::available();
	}

	/**
	 * Build the PDO-compatible transport.
	 *
	 * @return PDO
	 * @throws \InvalidArgumentException When PDO is asked to enforce a read timeout.
	 */
	private function create_pdo(): PDO {
		$driver = $this->resolve_driver();
		if ( 'mysqli' === $driver ) {
			return new MysqliPdo( $this->host, $this->dbname, $this->user, $this->password, $this->options, $this->failure_callback() );
		}
		if ( isset( $this->options['read_timeout'] ) ) {
			throw new \InvalidArgumentException( 'PDO MySQL cannot guarantee a read timeout. Select mysqli/mysqlnd explicitly for read_timeout.' );
		}
		$options = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		);
		if ( isset( $this->options['connect_timeout'] ) ) {
			$options[ PDO::ATTR_TIMEOUT ] = $this->options['connect_timeout'];
		}
		return new MysqlPdo(
			$this->build_dsn(),
			$this->user,
			$this->password,
			$options,
			$this->failure_callback(),
		);
	}

	/**
	 * Notify the owner without retaining the connection through a reference cycle.
	 *
	 * @return \Closure
	 */
	private function failure_callback(): \Closure {
		$owner = \WeakReference::create( $this );
		return static function () use ( $owner ): void {
			$conn = $owner->get();
			if ( $conn instanceof self ) {
				$conn->discard();
			}
		};
	}

	/**
	 * Resolve the requested driver.
	 *
	 * @return string
	 * @throws \RuntimeException When the requested DB driver is unavailable.
	 * @throws \InvalidArgumentException When the requested DB driver is not supported.
	 */
	private function resolve_driver(): string {
		$source = $this->driver ?? ( defined( 'QUEUETY_DB_DRIVER' ) ? constant( 'QUEUETY_DB_DRIVER' ) : 'auto' );
		$driver = is_scalar( $source ) ? strtolower( trim( (string) $source ) ) : 'auto';
		if ( '' === $driver ) {
			$driver = 'auto';
		}

		if ( 'auto' === $driver ) {
			if ( defined( 'ABSPATH' ) && MysqliPdo::available() ) {
				return 'mysqli';
			}

			if ( extension_loaded( 'pdo_mysql' ) ) {
				return 'pdo';
			}

			if ( MysqliPdo::available() ) {
				return 'mysqli';
			}

			throw new \RuntimeException( 'Queuety requires either mysqli or pdo_mysql for MySQL access.' );
		}

		if ( 'pdo' === $driver ) {
			if ( ! extension_loaded( 'pdo_mysql' ) ) {
				throw new \RuntimeException( 'Queuety DB driver pdo requires the pdo_mysql PHP extension.' );
			}
			return 'pdo';
		}

		if ( 'mysqli' === $driver ) {
			if ( ! MysqliPdo::available() ) {
				throw new \RuntimeException( 'Queuety DB driver mysqli requires the mysqli PHP extension.' );
			}
			return 'mysqli';
		}

		throw new \InvalidArgumentException( sprintf( 'Unsupported Queuety DB driver "%s".', $driver ) );
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
	 * Get the Queuety table prefix used after the WordPress prefix.
	 *
	 * @return string
	 */
	public function table_prefix(): string {
		if ( null === $this->table_prefix ) {
			return Config::table_prefix();
		}

		return self::normalize_table_prefix( $this->table_prefix );
	}

	/**
	 * Get a fully prefixed table name.
	 *
	 * @param string $name Base table name (e.g. 'queuety_jobs').
	 * @return string Prefixed table name (e.g. 'wp_queuety_jobs').
	 */
	public function table( string $name ): string {
		return $this->prefix . $this->resolve_table_name( $name );
	}

	/**
	 * Resolve a Queuety base table name through the connection-level table prefix.
	 *
	 * @param string $name Base table name.
	 * @return string
	 */
	private function resolve_table_name( string $name ): string {
		if ( null === $this->table_prefix ) {
			return $name;
		}

		$default_prefix = Config::table_prefix();
		if ( '' !== $default_prefix && str_starts_with( $name, $default_prefix ) ) {
			$name = substr( $name, strlen( $default_prefix ) );
		}

		return $this->table_prefix() . $name;
	}

	/**
	 * Normalize one Queuety table prefix.
	 *
	 * @param string $prefix Table prefix.
	 * @return string
	 */
	private static function normalize_table_prefix( string $prefix ): string {
		$prefix = trim( $prefix );
		if ( '' === $prefix ) {
			return '';
		}

		return rtrim( $prefix, '_' ) . '_';
	}
}
