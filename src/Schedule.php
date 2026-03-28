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
	 * @param array                   $payload         Job payload.
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
	 * @param array $row Associative array from PDO fetch.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			handler: $row['handler'],
			payload: json_decode( $row['payload'], true ) ?: array(),
			queue: $row['queue'],
			expression: $row['expression'],
			expression_type: ExpressionType::from( $row['expression_type'] ),
			last_run: ! empty( $row['last_run'] ) ? new \DateTimeImmutable( $row['last_run'] ) : null,
			next_run: new \DateTimeImmutable( $row['next_run'] ),
			enabled: (bool) $row['enabled'],
			created_at: new \DateTimeImmutable( $row['created_at'] ),
			overlap_policy: OverlapPolicy::tryFrom( $row['overlap_policy'] ?? 'allow' ) ?? OverlapPolicy::Allow,
		);
	}
}
