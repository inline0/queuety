<?php
// phpcs:ignoreFile -- Queuety intentionally provides a direct MySQL driver that is not routed through wpdb.
/**
 * PDOStatement-compatible mysqli statement.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Minimal PDOStatement facade for the mysqli Queuety driver.
 */
class MysqliPdoStatement extends \PDOStatement {

	/**
	 * Active mysqli connection.
	 *
	 * @var \mysqli
	 */
	private \mysqli $mysqli;

	/**
	 * Original SQL query.
	 *
	 * @var string
	 */
	private string $query;

	/**
	 * Result rows from the last execution.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Current fetch cursor.
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * Affected rows from the last execution.
	 *
	 * @var int
	 */
	private int $row_count = 0;

	/**
	 * Initialize the statement facade.
	 *
	 * @param \mysqli $mysqli Active mysqli connection.
	 * @param string  $query  SQL query.
	 */
	public function __construct( \mysqli $mysqli, string $query ) {
		$this->mysqli = $mysqli;
		$this->query  = $query;
	}

	/**
	 * Execute the statement.
	 *
	 * @param array<string|int, mixed>|null $params Bound parameters.
	 * @return bool
	 */
	public function execute( ?array $params = null ): bool {
		$params = $params ?? array();

		$this->rows      = array();
		$this->cursor    = 0;
		$this->row_count = 0;

		if ( empty( $params ) && ! $this->has_placeholders( $this->query ) ) {
			$this->execute_raw_query( $this->query );
			return true;
		}

		$compiled  = $this->compile_query( $this->query, $params );
		$statement = mysqli_prepare( $this->mysqli, $compiled['sql'] );
		if ( false === $statement ) {
			throw MysqliPdo::exception_from_mysqli( $this->mysqli, 'Could not prepare MySQL statement.' );
		}

		$values = $this->normalize_params( $compiled['params'] );
		if ( ! empty( $values ) ) {
			$types      = $this->parameter_types( $values );
			$references = array();
			foreach ( $values as $index => $value ) {
				$references[ $index ] = &$values[ $index ];
				unset( $value );
			}

			if ( false === mysqli_stmt_bind_param( $statement, $types, ...$references ) ) {
				throw MysqliPdo::exception_from_mysqli( $this->mysqli, 'Could not bind MySQL statement parameters.' );
			}
		}

		if ( false === mysqli_stmt_execute( $statement ) ) {
			$exception = $this->exception_from_statement( $statement, 'Could not execute MySQL statement.' );
			mysqli_stmt_close( $statement );
			throw $exception;
		}

		$this->store_statement_result( $statement );
		mysqli_stmt_close( $statement );

		return true;
	}

	/**
	 * Fetch the next row.
	 *
	 * @param int $mode              Fetch mode.
	 * @param int $cursorOrientation Cursor orientation.
	 * @param int $cursorOffset      Cursor offset.
	 * @return mixed
	 */
	public function fetch( int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0 ): mixed {
		unset( $cursorOrientation, $cursorOffset );

		if ( ! isset( $this->rows[ $this->cursor ] ) ) {
			return false;
		}

		$row = $this->rows[ $this->cursor ];
		++$this->cursor;

		return $this->format_row( $row, $mode );
	}

	/**
	 * Fetch all rows.
	 *
	 * @param int   $mode Fetch mode.
	 * @param mixed ...$args Fetch-mode args.
	 * @return array<int, mixed>
	 */
	public function fetchAll( int $mode = \PDO::FETCH_DEFAULT, mixed ...$args ): array {
		unset( $args );

		$rows = array_slice( $this->rows, $this->cursor );
		$this->cursor = count( $this->rows );

		return array_map(
			fn( array $row ) => $this->format_row( $row, $mode ),
			$rows
		);
	}

	/**
	 * Fetch one column from the next row.
	 *
	 * @param int $column Column offset.
	 * @return mixed
	 */
	public function fetchColumn( int $column = 0 ): mixed {
		$row = $this->fetch( \PDO::FETCH_NUM );
		if ( false === $row ) {
			return false;
		}

		return $row[ $column ] ?? false;
	}

	/**
	 * Number of affected or selected rows from the last execution.
	 *
	 * @return int
	 */
	public function rowCount(): int {
		return $this->row_count;
	}

	/**
	 * Execute a query without bound parameters.
	 *
	 * @param string $query SQL query.
	 * @return void
	 */
	private function execute_raw_query( string $query ): void {
		$result = mysqli_query( $this->mysqli, $query );
		if ( false === $result ) {
			throw MysqliPdo::exception_from_mysqli( $this->mysqli, 'Could not execute MySQL query.' );
		}

		if ( $result instanceof \mysqli_result ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				$this->rows[] = $row;
			}
			$this->row_count = mysqli_num_rows( $result );
			mysqli_free_result( $result );
			return;
		}

		$this->row_count = max( 0, (int) mysqli_affected_rows( $this->mysqli ) );
	}

	/**
	 * Store the result from an executed prepared statement.
	 *
	 * @param \mysqli_stmt $statement Prepared statement.
	 * @return void
	 */
	private function store_statement_result( \mysqli_stmt $statement ): void {
		$result = mysqli_stmt_get_result( $statement );
		if ( $result instanceof \mysqli_result ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				$this->rows[] = $row;
			}
			$this->row_count = mysqli_num_rows( $result );
			mysqli_free_result( $result );
			return;
		}

		if ( mysqli_stmt_field_count( $statement ) > 0 ) {
			$this->rows      = $this->fetch_statement_rows_without_mysqlnd( $statement );
			$this->row_count = count( $this->rows );
			return;
		}

		$this->row_count = max( 0, (int) mysqli_stmt_affected_rows( $statement ) );
	}

	/**
	 * Build a PDO-style exception from a statement failure.
	 *
	 * @param \mysqli_stmt $statement Prepared statement.
	 * @param string       $message   Fallback message.
	 * @return \PDOException
	 */
	private function exception_from_statement( \mysqli_stmt $statement, string $message ): \PDOException {
		$error = mysqli_stmt_error( $statement );
		$errno = mysqli_stmt_errno( $statement );
		$state = mysqli_stmt_sqlstate( $statement );

		if ( '' !== $error ) {
			$message = $error;
		}

		$exception            = new \PDOException( $message, $errno );
		$exception->errorInfo = array( $state ?: 'HY000', $errno, $message ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Mirrors PDOException.

		return $exception;
	}

	/**
	 * Fetch rows when mysqli_stmt_get_result() is unavailable.
	 *
	 * @param \mysqli_stmt $statement Prepared statement.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_statement_rows_without_mysqlnd( \mysqli_stmt $statement ): array {
		$metadata = mysqli_stmt_result_metadata( $statement );
		if ( false === $metadata ) {
			return array();
		}

		$fields = mysqli_fetch_fields( $metadata );
		$row    = array();
		$refs   = array();
		foreach ( $fields as $field ) {
			$row[ $field->name ] = null;
			$refs[]              = &$row[ $field->name ];
		}

		mysqli_stmt_store_result( $statement );
		mysqli_stmt_bind_result( $statement, ...$refs );

		$rows = array();
		while ( mysqli_stmt_fetch( $statement ) ) {
			$rows[] = array_map(
				static fn( $value ) => $value,
				$row
			);
		}

		mysqli_free_result( $metadata );

		return $rows;
	}

	/**
	 * Compile PDO-style placeholders into mysqli placeholders.
	 *
	 * @param string                   $sql    SQL query.
	 * @param array<string|int, mixed> $params Bound parameters.
	 * @return array{sql: string, params: array<int, mixed>}
	 */
	private function compile_query( string $sql, array $params ): array {
		$ordered = array();
		$offset  = 0;

		$sql = (string) preg_replace_callback(
			'/:([A-Za-z_][A-Za-z0-9_]*)/',
			function ( array $matches ) use ( $params, &$ordered ): string {
				$name = $matches[1];
				if ( array_key_exists( $name, $params ) ) {
					$ordered[] = $params[ $name ];
					return '?';
				}

				if ( array_key_exists( ':' . $name, $params ) ) {
					$ordered[] = $params[ ':' . $name ];
					return '?';
				}

				throw new \InvalidArgumentException( sprintf( 'Missing SQL parameter :%s.', $name ) );
			},
			$sql
		);

		$sql = (string) preg_replace_callback(
			'/\?/',
			function () use ( $params, &$ordered, &$offset ): string {
				if ( array_key_exists( $offset, $params ) ) {
					$ordered[] = $params[ $offset ];
					++$offset;
				}

				return '?';
			},
			$sql
		);

		return array(
			'sql'    => $sql,
			'params' => $ordered,
		);
	}

	/**
	 * Whether a query has placeholders.
	 *
	 * @param string $sql SQL query.
	 * @return bool
	 */
	private function has_placeholders( string $sql ): bool {
		return str_contains( $sql, '?' ) || 1 === preg_match( '/:[A-Za-z_][A-Za-z0-9_]*/', $sql );
	}

	/**
	 * Normalize parameter values before binding.
	 *
	 * @param array<int, mixed> $params Bound parameters.
	 * @return array<int, mixed>
	 */
	private function normalize_params( array $params ): array {
		foreach ( $params as $index => $value ) {
			if ( is_bool( $value ) ) {
				$params[ $index ] = $value ? 1 : 0;
			}
		}

		return array_values( $params );
	}

	/**
	 * Build the mysqli bind type string.
	 *
	 * @param array<int, mixed> $params Bound parameters.
	 * @return string
	 */
	private function parameter_types( array $params ): string {
		$types = '';
		foreach ( $params as $value ) {
			if ( is_int( $value ) ) {
				$types .= 'i';
			} elseif ( is_float( $value ) ) {
				$types .= 'd';
			} else {
				$types .= 's';
			}
		}

		return $types;
	}

	/**
	 * Format a row for the requested fetch mode.
	 *
	 * @param array<string, mixed> $row  Associative row.
	 * @param int                  $mode Fetch mode.
	 * @return mixed
	 */
	private function format_row( array $row, int $mode ): mixed {
		return match ( $mode ) {
			\PDO::FETCH_NUM => array_values( $row ),
			\PDO::FETCH_BOTH => array_merge( $row, array_values( $row ) ),
			\PDO::FETCH_COLUMN => reset( $row ),
			default => $row,
		};
	}
}
