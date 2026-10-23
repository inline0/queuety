<?php
// phpcs:ignoreFile -- This PDO transport deliberately preserves native PDO method and parameter names.
/**
 * Guarded native PDO MySQL transport.
 *
 * @package Queuety
 */

namespace Queuety;

/** Native PDO with explicit connection-loss and unknown-COMMIT handling. */
class MysqlPdo extends \PDO {

	/** Failure state shared with prepared statements. */
	private readonly DatabaseFailureGuard $guard;

	/**
	 * Connect using native PDO MySQL.
	 *
	 * @param string $dsn Native PDO DSN.
	 * @param string $user Database user.
	 * @param string $password Database password.
	 * @param array<int, mixed> $options Native PDO options.
	 * @param \Closure|null $discard Callback that poisons the owning Connection.
	 */
	public function __construct( string $dsn, string $user, string $password, array $options, ?\Closure $discard = null ) {
		$this->guard = new DatabaseFailureGuard( $discard );
		$options[ self::ATTR_STATEMENT_CLASS ] = array( MysqlPdoStatement::class, array( $this->guard ) );
		parent::__construct( $dsn, $user, $password, $options );
	}

	/**
	 * Prepare a guarded statement.
	 *
	 * @param string $query SQL query.
	 * @param array<int, mixed> $options Native PDO statement options.
	 * @return \PDOStatement|false
	 */
	public function prepare( string $query, array $options = array() ): \PDOStatement|false {
		$this->guard->check();
		try {
			return parent::prepare( $query, $options );
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error );
		}
	}

	/**
	 * Run SQL directly.
	 *
	 * @param string $statement SQL statement.
	 * @return int|false
	 */
	public function exec( string $statement ): int|false {
		$this->guard->check();
		try {
			return parent::exec( $statement );
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error, 1 === preg_match( '/^\s*COMMIT\b/i', $statement ) );
		}
	}

	/**
	 * Run a query with a guarded result statement.
	 *
	 * @param string $query SQL query.
	 * @param int|null $fetchMode Native PDO fetch mode.
	 * @param mixed ...$fetchModeArgs Fetch-mode arguments.
	 * @return \PDOStatement|false
	 */
	public function query( string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs ): \PDOStatement|false {
		$this->guard->check();
		try {
			return parent::query( $query, $fetchMode, ...$fetchModeArgs );
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error, 1 === preg_match( '/^\s*COMMIT\b/i', $query ) );
		}
	}

	/** Begin a transaction on a healthy transport. */
	public function beginTransaction(): bool {
		$this->guard->check();
		try {
			return parent::beginTransaction();
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error );
		}
	}

	/** Commit without retrying an unknown acknowledgement. */
	public function commit(): bool {
		$this->guard->check();
		try {
			return parent::commit();
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error, true );
		}
	}

	/** Roll back only on a healthy transport. */
	public function rollBack(): bool {
		$this->guard->check();
		try {
			return parent::rollBack();
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error );
		}
	}

	/** A discarded transport has no transaction that can safely be rolled back. */
	public function inTransaction(): bool {
		return ! $this->guard->failed() && parent::inTransaction();
	}

	/** Prevent use of handles retained after their owner discards the connection. */
	public function discard(): void {
		$this->guard->discard();
	}
}
