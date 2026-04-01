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
	 * @param int                     $id            Job ID.
	 * @param string                  $queue         Queue name.
	 * @param string                  $handler       Handler name.
	 * @param array                   $payload       Job payload.
	 * @param Priority                $priority      Priority level.
	 * @param JobStatus               $status        Current status.
	 * @param int                     $attempts      Number of attempts so far.
	 * @param int                     $max_attempts  Maximum retry attempts.
	 * @param \DateTimeImmutable      $available_at  When the job becomes available.
	 * @param \DateTimeImmutable|null $reserved_at   When the job was reserved.
	 * @param \DateTimeImmutable|null $completed_at  When the job completed.
	 * @param \DateTimeImmutable|null $failed_at     When the job failed.
	 * @param string|null             $error_message Error message if failed.
	 * @param int|null                $workflow_id   Parent workflow ID.
	 * @param int|null                $step_index    Step index in workflow.
	 * @param \DateTimeImmutable      $created_at    When the job was created.
	 * @param string|null             $payload_hash  SHA-256 hash of the payload for unique job detection.
	 * @param int|null                $depends_on    ID of the job this job depends on.
	 * @param int|null                $batch_id      Batch ID if part of a batch.
	 * @param array|null              $heartbeat_data Progress data from heartbeats.
	 * @param string|null             $concurrency_group Optional global concurrency group name.
	 * @param int|null                $concurrency_limit Optional maximum concurrent jobs for the group.
	 * @param int                     $cost_units    Relative execution cost units for guardrails.
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
	 * @param array $row Associative array from PDO fetch.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			queue: $row['queue'],
			handler: $row['handler'],
			payload: json_decode( $row['payload'], true ) ?: array(),
			priority: Priority::from( (int) $row['priority'] ),
			status: JobStatus::from( $row['status'] ),
			attempts: (int) $row['attempts'],
			max_attempts: (int) $row['max_attempts'],
			available_at: new \DateTimeImmutable( $row['available_at'] ),
			reserved_at: $row['reserved_at'] ? new \DateTimeImmutable( $row['reserved_at'] ) : null,
			completed_at: $row['completed_at'] ? new \DateTimeImmutable( $row['completed_at'] ) : null,
			failed_at: $row['failed_at'] ? new \DateTimeImmutable( $row['failed_at'] ) : null,
			error_message: $row['error_message'],
			workflow_id: $row['workflow_id'] ? (int) $row['workflow_id'] : null,
			step_index: $row['step_index'] !== null ? (int) $row['step_index'] : null,
			created_at: new \DateTimeImmutable( $row['created_at'] ),
			payload_hash: $row['payload_hash'] ?? null,
			depends_on: array_key_exists( 'depends_on', $row ) && null !== $row['depends_on'] ? (int) $row['depends_on'] : null,
			batch_id: array_key_exists( 'batch_id', $row ) && null !== $row['batch_id'] ? (int) $row['batch_id'] : null,
			heartbeat_data: array_key_exists( 'heartbeat_data', $row ) && null !== $row['heartbeat_data']
				? ( json_decode( $row['heartbeat_data'], true ) ?: null )
				: null,
			concurrency_group: array_key_exists( 'concurrency_group', $row ) && null !== $row['concurrency_group']
				? (string) $row['concurrency_group']
				: null,
			concurrency_limit: array_key_exists( 'concurrency_limit', $row ) && null !== $row['concurrency_limit']
				? (int) $row['concurrency_limit']
				: null,
			cost_units: array_key_exists( 'cost_units', $row ) ? max( 0, (int) $row['cost_units'] ) : 1,
		);
	}

	/**
	 * Whether this job is part of a workflow.
	 *
	 * @return bool
	 */
	public function is_workflow_step(): bool {
		return null !== $this->workflow_id;
	}
}
