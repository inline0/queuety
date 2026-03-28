<?php
/**
 * Batch value object.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Immutable value object representing a batch row.
 */
readonly class Batch {

	/**
	 * Constructor.
	 *
	 * @param int                     $id             Batch ID.
	 * @param string|null             $name           Optional batch name.
	 * @param int                     $total_jobs     Total number of jobs in the batch.
	 * @param int                     $pending_jobs   Number of jobs still pending.
	 * @param int                     $failed_jobs    Number of failed jobs.
	 * @param array                   $failed_job_ids IDs of failed jobs.
	 * @param array                   $options        Batch options (callbacks, etc.).
	 * @param \DateTimeImmutable|null $cancelled_at   When the batch was cancelled.
	 * @param \DateTimeImmutable      $created_at     When the batch was created.
	 * @param \DateTimeImmutable|null $finished_at    When the batch finished.
	 */
	public function __construct(
		public int $id,
		public ?string $name,
		public int $total_jobs,
		public int $pending_jobs,
		public int $failed_jobs,
		public array $failed_job_ids,
		public array $options,
		public ?\DateTimeImmutable $cancelled_at,
		public \DateTimeImmutable $created_at,
		public ?\DateTimeImmutable $finished_at,
	) {}

	/**
	 * Get the batch progress as a percentage (0-100).
	 *
	 * @return int
	 */
	public function progress(): int {
		if ( 0 === $this->total_jobs ) {
			return 100;
		}

		$completed = $this->total_jobs - $this->pending_jobs;
		return (int) floor( ( $completed / $this->total_jobs ) * 100 );
	}

	/**
	 * Whether the batch has finished (all jobs complete or failed).
	 *
	 * @return bool
	 */
	public function finished(): bool {
		return null !== $this->finished_at;
	}

	/**
	 * Whether the batch has been cancelled.
	 *
	 * @return bool
	 */
	public function cancelled(): bool {
		return null !== $this->cancelled_at;
	}

	/**
	 * Whether the batch has any failures.
	 *
	 * @return bool
	 */
	public function has_failures(): bool {
		return $this->failed_jobs > 0;
	}

	/**
	 * Hydrate a Batch from a database row.
	 *
	 * @param array $row Associative array from PDO fetch.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: (int) $row['id'],
			name: $row['name'],
			total_jobs: (int) $row['total_jobs'],
			pending_jobs: (int) $row['pending_jobs'],
			failed_jobs: (int) $row['failed_jobs'],
			failed_job_ids: json_decode( $row['failed_job_ids'], true ) ?: array(),
			options: json_decode( $row['options'], true ) ?: array(),
			cancelled_at: ! empty( $row['cancelled_at'] ) ? new \DateTimeImmutable( $row['cancelled_at'] ) : null,
			created_at: new \DateTimeImmutable( $row['created_at'] ),
			finished_at: ! empty( $row['finished_at'] ) ? new \DateTimeImmutable( $row['finished_at'] ) : null,
		);
	}
}
