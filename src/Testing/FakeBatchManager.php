<?php
/**
 * Batch manager for QueueFake.
 *
 * @package Queuety
 */

namespace Queuety\Testing;

use Queuety\Batch;
use Queuety\BatchManager;

/**
 * Stores synthetic batch state in memory for tests.
 */
class FakeBatchManager extends BatchManager {

	/**
	 * Stored fake batches.
	 *
	 * @var array<int, Batch>
	 */
	private array $batches = array();

	/**
	 * Next synthetic batch ID.
	 *
	 * @var int
	 */
	private int $next_batch_id = 1;

	/**
	 * Constructor.
	 *
	 * @param QueueFake $fake Recorder instance.
	 */
	public function __construct(
		private readonly QueueFake $fake,
	) {}

	/**
	 * Create a fake batch.
	 *
	 * @param int         $total_jobs Total number of jobs.
	 * @param string|null $name       Optional name.
	 * @param array       $options    Batch options.
	 * @return int
	 */
	public function create( int $total_jobs, ?string $name = null, array $options = array() ): int {
		unset( $this->fake );

		$batch_id                = $this->next_batch_id++;
		$this->batches[ $batch_id ] = new Batch(
			id: $batch_id,
			name: $name,
			total_jobs: $total_jobs,
			pending_jobs: $total_jobs,
			failed_jobs: 0,
			failed_job_ids: array(),
			options: $options,
			cancelled_at: null,
			created_at: new \DateTimeImmutable(),
			finished_at: null,
		);

		return $batch_id;
	}

	/**
	 * Find a fake batch by ID.
	 *
	 * @param int $id Batch ID.
	 * @return Batch|null
	 */
	public function find( int $id ): ?Batch {
		return $this->batches[ $id ] ?? null;
	}

	/**
	 * Record a fake completion.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function record_completion( int $batch_id ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$pending_jobs = max( 0, $batch->pending_jobs - 1 );

		$this->batches[ $batch_id ] = new Batch(
			id: $batch->id,
			name: $batch->name,
			total_jobs: $batch->total_jobs,
			pending_jobs: $pending_jobs,
			failed_jobs: $batch->failed_jobs,
			failed_job_ids: $batch->failed_job_ids,
			options: $batch->options,
			cancelled_at: $batch->cancelled_at,
			created_at: $batch->created_at,
			finished_at: 0 === $pending_jobs ? new \DateTimeImmutable() : $batch->finished_at,
		);
	}

	/**
	 * Record a fake failure.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $job_id   Job ID.
	 */
	public function record_failure( int $batch_id, int $job_id ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$pending_jobs   = max( 0, $batch->pending_jobs - 1 );
		$failed_job_ids = $batch->failed_job_ids;
		$failed_job_ids[] = $job_id;

		$this->batches[ $batch_id ] = new Batch(
			id: $batch->id,
			name: $batch->name,
			total_jobs: $batch->total_jobs,
			pending_jobs: $pending_jobs,
			failed_jobs: $batch->failed_jobs + 1,
			failed_job_ids: $failed_job_ids,
			options: $batch->options,
			cancelled_at: $batch->cancelled_at,
			created_at: $batch->created_at,
			finished_at: 0 === $pending_jobs ? new \DateTimeImmutable() : $batch->finished_at,
		);
	}

	/**
	 * Cancel a fake batch.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function cancel( int $batch_id ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$now = new \DateTimeImmutable();

		$this->batches[ $batch_id ] = new Batch(
			id: $batch->id,
			name: $batch->name,
			total_jobs: $batch->total_jobs,
			pending_jobs: $batch->pending_jobs,
			failed_jobs: $batch->failed_jobs,
			failed_job_ids: $batch->failed_job_ids,
			options: $batch->options,
			cancelled_at: $now,
			created_at: $batch->created_at,
			finished_at: $now,
		);
	}

	/**
	 * Prune finished fake batches.
	 *
	 * @param int $days Unused.
	 * @return int
	 */
	public function prune( int $days ): int {
		unset( $days );

		$count         = count( $this->batches );
		$this->batches = array();

		return $count;
	}
}
