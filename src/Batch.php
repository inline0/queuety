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
	 * @param array<int, int>         $failed_job_ids IDs of failed jobs.
	 * @param array<string, mixed>    $options        Batch options (callbacks, etc.).
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
	 * @param array<string, mixed> $row Associative array from PDO fetch.
	 * @return self
	 * @throws \InvalidArgumentException If the row is missing a created_at timestamp.
	 */
	public static function from_row( array $row ): self {
		$id_raw           = $row['id'] ?? 0;
		$total_raw        = $row['total_jobs'] ?? 0;
		$pending_raw      = $row['pending_jobs'] ?? 0;
		$failed_raw       = $row['failed_jobs'] ?? 0;
		$name_raw         = $row['name'] ?? null;
		$failed_ids_json  = $row['failed_job_ids'] ?? '';
		$options_json     = $row['options'] ?? '';
		$cancelled_at_raw = $row['cancelled_at'] ?? null;
		$created_at_raw   = $row['created_at'] ?? null;
		$finished_at_raw  = $row['finished_at'] ?? null;

		$failed_ids   = is_string( $failed_ids_json ) ? json_decode( $failed_ids_json, true ) : array();
		$options_data = is_string( $options_json ) ? json_decode( $options_json, true ) : array();
		$options      = array();
		if ( is_array( $options_data ) ) {
			foreach ( $options_data as $key => $value ) {
				$options[ (string) $key ] = $value;
			}
		}

		if ( ! is_string( $created_at_raw ) ) {
			throw new \InvalidArgumentException( 'Batch row is missing a valid created_at timestamp.' );
		}

		return new self(
			id: is_numeric( $id_raw ) ? (int) $id_raw : 0,
			name: is_string( $name_raw ) ? $name_raw : null,
			total_jobs: is_numeric( $total_raw ) ? (int) $total_raw : 0,
			pending_jobs: is_numeric( $pending_raw ) ? (int) $pending_raw : 0,
			failed_jobs: is_numeric( $failed_raw ) ? (int) $failed_raw : 0,
			failed_job_ids: is_array( $failed_ids )
				? array_values(
					array_map(
						static fn( mixed $value ): int => is_numeric( $value ) ? (int) $value : 0,
						$failed_ids
					)
				)
				: array(),
			options: $options,
			cancelled_at: is_string( $cancelled_at_raw ) && '' !== $cancelled_at_raw
				? new \DateTimeImmutable( $cancelled_at_raw )
				: null,
			created_at: new \DateTimeImmutable( $created_at_raw ),
			finished_at: is_string( $finished_at_raw ) && '' !== $finished_at_raw
				? new \DateTimeImmutable( $finished_at_raw )
				: null,
		);
	}
}
