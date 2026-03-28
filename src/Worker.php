<?php
/**
 * Job worker process.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\BackoffStrategy;
use Queuety\Enums\LogEvent;

/**
 * Worker process loop. Claims jobs, executes handlers, manages lifecycle.
 */
class Worker {

	/**
	 * Whether the worker should stop after the current iteration.
	 *
	 * @var bool
	 */
	private bool $should_stop = false;

	/**
	 * Constructor.
	 *
	 * @param Connection      $conn     Database connection.
	 * @param Queue           $queue    Queue operations.
	 * @param Logger          $logger   Logger instance.
	 * @param Workflow        $workflow Workflow manager.
	 * @param HandlerRegistry $registry Handler registry.
	 * @param Config          $config   Configuration.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
		private readonly Workflow $workflow,
		private readonly HandlerRegistry $registry,
		private readonly Config $config,
	) {}

	/**
	 * Run the worker loop.
	 *
	 * @param string $queue_name Queue to process.
	 * @param bool   $once       If true, process one batch and exit.
	 */
	public function run( string $queue_name = 'default', bool $once = false ): void {
		$jobs_processed    = 0;
		$stale_check_timer = time();

		while ( ! $this->should_stop ) {
			$job = $this->queue->claim( $queue_name );

			if ( null === $job ) {
				if ( $once ) {
					break;
				}
				sleep( Config::worker_sleep() );

				// Check for stale jobs periodically.
				if ( time() - $stale_check_timer >= 60 ) {
					$this->recover_stale();
					$stale_check_timer = time();
				}

				continue;
			}

			$this->process_job( $job );
			++$jobs_processed;

			if ( $once ) {
				break;
			}

			// Memory and job count safety limits.
			$memory_mb = memory_get_usage( true ) / 1024 / 1024;
			if ( $memory_mb >= Config::worker_max_memory() ) {
				break;
			}
			if ( $jobs_processed >= Config::worker_max_jobs() ) {
				break;
			}

			// Periodic stale check.
			if ( time() - $stale_check_timer >= 60 ) {
				$this->recover_stale();
				$stale_check_timer = time();
			}
		}
	}

	/**
	 * Process all pending jobs in a queue until empty.
	 *
	 * @param string $queue_name Queue to flush.
	 * @return int Total jobs processed.
	 */
	public function flush( string $queue_name = 'default' ): int {
		$count = 0;
		while ( true ) {
			$job = $this->queue->claim( $queue_name );
			if ( null === $job ) {
				break;
			}
			$this->process_job( $job );
			++$count;
		}
		return $count;
	}

	/**
	 * Execute a single job.
	 *
	 * @param Job $job The claimed job.
	 */
	public function process_job( Job $job ): void {
		$start_time = hrtime( true );

		$this->logger->log(
			LogEvent::Started,
			array(
				'job_id'      => $job->id,
				'workflow_id' => $job->workflow_id,
				'step_index'  => $job->step_index,
				'handler'     => $job->handler,
				'queue'       => $job->queue,
				'attempt'     => $job->attempts,
			)
		);

		try {
			$handler = $this->registry->resolve( $job->handler );

			if ( $job->is_workflow_step() && $handler instanceof Step ) {
				// Workflow step: load accumulated state and pass to handler.
				$state  = $this->workflow->get_state( $job->workflow_id ) ?? array();
				$output = $handler->handle( $state );

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->workflow->advance_step(
					workflow_id: $job->workflow_id,
					completed_job_id: $job->id,
					step_output: $output,
					duration_ms: $duration_ms,
				);
			} else {
				// Simple job.
				$handler->handle( $job->payload );

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->queue->complete( $job->id );

				$this->logger->log(
					LogEvent::Completed,
					array(
						'job_id'         => $job->id,
						'handler'        => $job->handler,
						'queue'          => $job->queue,
						'attempt'        => $job->attempts,
						'duration_ms'    => $duration_ms,
						'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

			if ( $job->attempts >= $job->max_attempts ) {
				// Max attempts reached, bury the job.
				$this->queue->bury( $job->id, $e->getMessage() );

				$this->logger->log(
					LogEvent::Buried,
					array(
						'job_id'        => $job->id,
						'workflow_id'   => $job->workflow_id,
						'step_index'    => $job->step_index,
						'handler'       => $job->handler,
						'queue'         => $job->queue,
						'attempt'       => $job->attempts,
						'duration_ms'   => $duration_ms,
						'error_message' => $e->getMessage(),
						'error_class'   => get_class( $e ),
						'error_trace'   => $e->getTraceAsString(),
					)
				);

				// If part of a workflow, mark the workflow as failed.
				if ( $job->is_workflow_step() ) {
					$this->workflow->fail( $job->workflow_id, $job->id, $e->getMessage() );
				}
			} else {
				// Retry with backoff.
				$backoff = Queue::calculate_backoff( $job->attempts, Config::retry_backoff() );
				$this->queue->retry( $job->id, $backoff );

				$this->logger->log(
					LogEvent::Failed,
					array(
						'job_id'        => $job->id,
						'workflow_id'   => $job->workflow_id,
						'step_index'    => $job->step_index,
						'handler'       => $job->handler,
						'queue'         => $job->queue,
						'attempt'       => $job->attempts,
						'duration_ms'   => $duration_ms,
						'error_message' => $e->getMessage(),
						'error_class'   => get_class( $e ),
						'error_trace'   => $e->getTraceAsString(),
					)
				);

				$this->logger->log(
					LogEvent::Retried,
					array(
						'job_id'      => $job->id,
						'workflow_id' => $job->workflow_id,
						'step_index'  => $job->step_index,
						'handler'     => $job->handler,
						'queue'       => $job->queue,
						'attempt'     => $job->attempts,
					)
				);
			}
		}
	}

	/**
	 * Find and recover stale jobs.
	 *
	 * @return int Number of stale jobs recovered.
	 */
	public function recover_stale(): int {
		$stale_jobs = $this->queue->find_stale( Config::stale_timeout() );
		$count      = 0;

		foreach ( $stale_jobs as $job ) {
			if ( $job->attempts >= $job->max_attempts ) {
				$this->queue->bury( $job->id, 'Stale: worker died without completing.' );

				$this->logger->log(
					LogEvent::Buried,
					array(
						'job_id'        => $job->id,
						'workflow_id'   => $job->workflow_id,
						'step_index'    => $job->step_index,
						'handler'       => $job->handler,
						'queue'         => $job->queue,
						'error_message' => 'Stale: worker died without completing.',
					)
				);

				if ( $job->is_workflow_step() ) {
					$this->workflow->fail( $job->workflow_id, $job->id, 'Stale: worker died without completing.' );
				}
			} else {
				$backoff = Queue::calculate_backoff( $job->attempts, Config::retry_backoff() );
				$this->queue->retry( $job->id, $backoff );

				$this->logger->log(
					LogEvent::Retried,
					array(
						'job_id'      => $job->id,
						'workflow_id' => $job->workflow_id,
						'step_index'  => $job->step_index,
						'handler'     => $job->handler,
						'queue'       => $job->queue,
						'attempt'     => $job->attempts,
					)
				);
			}

			++$count;
		}

		return $count;
	}

	/**
	 * Signal the worker to stop after the current job.
	 */
	public function stop(): void {
		$this->should_stop = true;
	}
}
