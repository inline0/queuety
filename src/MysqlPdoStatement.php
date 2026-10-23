<?php
/**
 * Guarded native PDO statement.
 *
 * @package Queuety
 */

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDOStatement -- Standalone transport intentionally uses native PDO.

namespace Queuety;

/** Shares transport failure state with its native PDO connection. */
class MysqlPdoStatement extends \PDOStatement {

	/**
	 * Constructed by PDO using ATTR_STATEMENT_CLASS.
	 *
	 * @param DatabaseFailureGuard $guard Connection failure state.
	 */
	protected function __construct( private readonly DatabaseFailureGuard $guard ) {}

	/**
	 * Execute once; failed transport writes must be reconciled before replay.
	 *
	 * @param array<string|int, mixed>|null $params Bound parameters.
	 * @return bool
	 */
	public function execute( ?array $params = null ): bool {
		$this->guard->check();
		try {
			return parent::execute( $params );
		} catch ( \Throwable $error ) {
			$this->guard->handle( $error, 1 === preg_match( '/^\s*COMMIT\b/i', $this->queryString ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO's public statement contract.
		}
	}
}
