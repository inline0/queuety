<?php
/**
 * Schedule value object.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\ExpressionType;
use Queuety\Enums\OverlapPolicy;

/**
 * Immutable value object representing a scheduled recurring job.
 */
readonly class Schedule {

	/**
	 * Constructor.
	 *
	 * @param int                     $id              Schedule ID.
	 * @param string                  $handler         Handler name or class.
	 * @param array<string, mixed>    $payload         Job payload.
	 * @param string                  $queue           Queue name.
	 * @param string                  $expression      Cron or interval expression.
	 * @param ExpressionType          $expression_type Type of expression.
	 * @param \DateTimeImmutable|null $last_run        When the schedule last ran.
	 * @param \DateTimeImmutable      $next_run        When the schedule should next run.
	 * @param bool                    $enabled         Whether the schedule is active.
	 * @param \DateTimeImmutable      $created_at      When the schedule was created.
	 * @param OverlapPolicy           $overlap_policy  Overlap policy for concurrent runs.
	 */
	public function __construct(
		public int $id,
		public string $handler,
		public array $payload,
		public string $queue,
		public string $expression,
		public ExpressionType $expression_type,
		public ?\DateTimeImmutable $last_run,
		public \DateTimeImmutable $next_run,
		public bool $enabled,
		public \DateTimeImmutable $created_at,
		public OverlapPolicy $overlap_policy = OverlapPolicy::Allow,
	) {}

	/**
	 * Hydrate a Schedule from a database row.
	 *
	 * @param array<string, mixed> $row Associative array from PDO fetch.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$id              = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (int) $row['id'] : 0;
		$handler         = isset( $row['handler'] ) && is_scalar( $row['handler'] ) ? (string) $row['handler'] : '';
		$payload_json    = isset( $row['payload'] ) && is_string( $row['payload'] ) ? $row['payload'] : '';
		$payload_decoded = '' !== $payload_json ? json_decode( $payload_json, true ) : array();
		$payload         = array();
		if ( is_array( $payload_decoded ) ) {
			foreach ( $payload_decoded as $payload_key => $payload_value ) {
				$payload[ (string) $payload_key ] = $payload_value;
			}
		}
		$queue           = isset( $row['queue'] ) && is_scalar( $row['queue'] ) ? (string) $row['queue'] : '';
		$expression      = isset( $row['expression'] ) && is_scalar( $row['expression'] ) ? (string) $row['expression'] : '';
		$expression_type = $row['expression_type'] ?? '';
		if ( ! is_int( $expression_type ) && ! is_string( $expression_type ) ) {
			$expression_type = '';
		}
		$last_run_raw   = $row['last_run'] ?? null;
		$next_run_raw   = $row['next_run'] ?? null;
		$created_at_raw = $row['created_at'] ?? null;
		$overlap_raw    = $row['overlap_policy'] ?? 'allow';
		if ( ! is_int( $overlap_raw ) && ! is_string( $overlap_raw ) ) {
			$overlap_raw = 'allow';
		}

		return new self(
			id: $id,
			handler: $handler,
			payload: $payload,
			queue: $queue,
			expression: $expression,
			expression_type: ExpressionType::from( $expression_type ),
			last_run: is_string( $last_run_raw ) && '' !== $last_run_raw ? new \DateTimeImmutable( $last_run_raw ) : null,
			next_run: new \DateTimeImmutable( is_string( $next_run_raw ) ? $next_run_raw : 'now' ),
			enabled: (bool) ( $row['enabled'] ?? false ),
			created_at: new \DateTimeImmutable( is_string( $created_at_raw ) ? $created_at_raw : 'now' ),
			overlap_policy: OverlapPolicy::tryFrom( $overlap_raw ) ?? OverlapPolicy::Allow,
		);
	}
}
