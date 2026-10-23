<?php
/**
 * Database transport failure handling.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Exceptions\CommitOutcomeUnknownException;

/** Prevents reuse of a transport whose writes may have reached the server. */
final class DatabaseFailureGuard {

	/**
	 * Whether this transport can still be used.
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * Construct the guard shared by a connection and its statements.
	 *
	 * @param \Closure|null $discard Callback that poisons the owning Connection.
	 */
	public function __construct( private readonly ?\Closure $discard = null ) {}

	/** Whether this transport failed. */
	public function failed(): bool {
		return $this->failed;
	}

	/** Invalidate retained handles and notify the owning connection once. */
	public function discard(): void {
		if ( $this->failed ) {
			return;
		}
		$this->failed = true;
		if ( null !== $this->discard ) {
			( $this->discard )();
		}
	}

	/**
	 * Refuse use after a transport failure.
	 *
	 * @throws \RuntimeException When durable-state reconciliation is required.
	 */
	public function check(): void {
		if ( $this->failed ) {
			throw new \RuntimeException( 'Database transport is discarded; reconcile durable state before replaying writes.' );
		}
	}

	/**
	 * Preserve ordinary SQL errors and poison uncertain write outcomes.
	 *
	 * @param \Throwable $error Original database error.
	 * @param bool       $commit Whether the failed command was COMMIT.
	 * @throws CommitOutcomeUnknownException When COMMIT was not acknowledged.
	 */
	public function handle( \Throwable $error, bool $commit = false ): never {
		if ( $error instanceof \mysqli_sql_exception ) {
			$converted = new \PDOException( $error->getMessage(), $error->getCode(), $error );
			$converted->errorInfo = array( $error->getSqlState(), $error->getCode(), $error->getMessage() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO's public exception contract.
			$error = $converted;
		}
		$info = $error instanceof \PDOException ? $error->errorInfo : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO's public exception contract.
		$code = is_array( $info ) && isset( $info[1] ) && is_scalar( $info[1] ) ? (int) $info[1] : (int) $error->getCode();
		$state = is_array( $info ) && isset( $info[0] ) && is_string( $info[0] ) ? $info[0] : '';
		if ( $commit || in_array( $code, array( 2006, 2013, 2055 ), true ) || str_starts_with( $state, '08' ) ) {
			$this->discard();
		}
		if ( $commit ) {
			throw new CommitOutcomeUnknownException( 'COMMIT acknowledgement failed. The transaction may have committed; use a fresh connection to reconcile durable state before retrying.', 0, $error );
		}
		throw $error;
	}
}
