<?php
/**
 * Job value object.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;

/**
 * Immutable value object representing a job row.
 */
readonly class Job {

	/**
	 * Constructor.
	 *
	 * @param int                       $id                Job ID.
	 * @param string                    $queue             Queue name.
	 * @param string                    $handler           Handler name.
	 * @param array<string, mixed>      $payload           Job payload.
	 * @param Priority                  $priority          Priority level.
	 * @param JobStatus                 $status            Current status.
	 * @param int                       $attempts          Number of attempts so far.
	 * @param int                       $max_attempts      Maximum retry attempts.
	 * @param \DateTimeImmutable        $available_at      When the job becomes available.
	 * @param \DateTimeImmutable|null   $reserved_at       When the job was reserved.
	 * @param \DateTimeImmutable|null   $completed_at      When the job completed.
	 * @param \DateTimeImmutable|null   $failed_at         When the job failed.
	 * @param string|null               $error_message     Error message if failed.
	 * @param int|null                  $workflow_id       Parent workflow ID.
	 * @param int|null                  $step_index        Step index in workflow.
	 * @param \DateTimeImmutable        $created_at        When the job was created.
	 * @param string|null               $payload_hash      SHA-256 hash of the payload for unique job detection.
	 * @param int|null                  $depends_on        ID of the job this job depends on.
	 * @param int|null                  $batch_id          Batch ID if part of a batch.
	 * @param array<string, mixed>|null $heartbeat_data    Progress data from heartbeats.
	 * @param string|null               $concurrency_group Optional global concurrency group name.
	 * @param int|null                  $concurrency_limit Optional maximum concurrent jobs for the group.
	 * @param int                       $cost_units        Relative execution cost units for guardrails.
	 */
	public function __construct(
		public int $id,
		public string $queue,
		public string $handler,
		public array $payload,
		public Priority $priority,
		public JobStatus $status,
		public int $attempts,
		public int $max_attempts,
		public \DateTimeImmutable $available_at,
		public ?\DateTimeImmutable $reserved_at,
		public ?\DateTimeImmutable $completed_at,
		public ?\DateTimeImmutable $failed_at,
		public ?string $error_message,
		public ?int $workflow_id,
		public ?int $step_index,
		public \DateTimeImmutable $created_at,
		public ?string $payload_hash = null,
		public ?int $depends_on = null,
		public ?int $batch_id = null,
		public ?array $heartbeat_data = null,
		public ?string $concurrency_group = null,
		public ?int $concurrency_limit = null,
		public int $cost_units = 1,
	) {}

	/**
	 * Hydrate a Job from a database row.
	 *
	 * @param array<string, mixed> $row Associative array from PDO fetch.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$payload_raw   = $row['payload'] ?? '';
		$payload_json  = is_string( $payload_raw ) ? $payload_raw : '';
		$payload       = json_decode( $payload_json, true );
		$status_value  = $row['status'] ?? '';
		$error_message = $row['error_message'] ?? null;
		$payload_hash  = $row['payload_hash'] ?? null;

		return new self(
			id: self::row_int( $row, 'id' ),
			queue: self::row_string( $row, 'queue' ),
			handler: self::row_string( $row, 'handler' ),
			payload: is_array( $payload ) ? $payload : array(),
			priority: Priority::from( self::row_int( $row, 'priority' ) ),
			status: JobStatus::from( is_int( $status_value ) || is_string( $status_value ) ? $status_value : '' ),
			attempts: self::row_int( $row, 'attempts' ),
			max_attempts: self::row_int( $row, 'max_attempts' ),
			available_at: new \DateTimeImmutable( self::row_string( $row, 'available_at' ) ),
			reserved_at: self::row_datetime( $row, 'reserved_at' ),
			completed_at: self::row_datetime( $row, 'completed_at' ),
			failed_at: self::row_datetime( $row, 'failed_at' ),
			error_message: is_string( $error_message ) ? $error_message : null,
			workflow_id: self::row_nullable_int( $row, 'workflow_id' ),
			step_index: self::row_nullable_int( $row, 'step_index' ),
			created_at: new \DateTimeImmutable( self::row_string( $row, 'created_at' ) ),
			payload_hash: is_string( $payload_hash ) ? $payload_hash : null,
			depends_on: self::row_nullable_int( $row, 'depends_on' ),
			batch_id: self::row_nullable_int( $row, 'batch_id' ),
			heartbeat_data: self::row_heartbeat_data( $row ),
			concurrency_group: self::row_nullable_string( $row, 'concurrency_group' ),
			concurrency_limit: self::row_nullable_int( $row, 'concurrency_limit' ),
			cost_units: array_key_exists( 'cost_units', $row ) ? max( 0, self::row_int( $row, 'cost_units' ) ) : 1,
		);
	}

	/**
	 * Read an int from a PDO row, coercing scalar values safely.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_int( array $row, string $key ): int {
		$value = $row[ $key ] ?? 0;
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Read a string from a PDO row, coercing scalar values safely.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_string( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read a nullable int from a PDO row.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_nullable_int( array $row, string $key ): ?int {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
			return null;
		}
		$value = $row[ $key ];
		return is_scalar( $value ) ? (int) $value : null;
	}

	/**
	 * Read a nullable string from a PDO row.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_nullable_string( array $row, string $key ): ?string {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
			return null;
		}
		$value = $row[ $key ];
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Build a DateTimeImmutable from a nullable row column.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_datetime( array $row, string $key ): ?\DateTimeImmutable {
		$value = $row[ $key ] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return new \DateTimeImmutable( $value );
	}

	/**
	 * Decode optional heartbeat JSON into an array.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @return array<string, mixed>|null
	 */
	private static function row_heartbeat_data( array $row ): ?array {
		if ( ! array_key_exists( 'heartbeat_data', $row ) || null === $row['heartbeat_data'] ) {
			return null;
		}
		$raw = $row['heartbeat_data'];
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$normalized = array();
		foreach ( $decoded as $k => $v ) {
			$normalized[ (string) $k ] = $v;
		}
		return $normalized;
	}

	/**
	 * Whether this job is part of a workflow.
	 *
	 * @phpstan-assert-if-true !null $this->workflow_id
	 * @phpstan-assert-if-true !null $this->step_index
	 * @return bool
	 */
	public function is_workflow_step(): bool {
		return null !== $this->workflow_id;
	}
}
