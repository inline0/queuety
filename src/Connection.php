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
			$dsn       = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
			$this->pdo = new PDO(
				$dsn,
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
