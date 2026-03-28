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
	 * @param Connection       $conn         Database connection.
	 * @param Queue            $queue        Queue operations.
	 * @param Logger           $logger       Logger instance.
	 * @param Workflow         $workflow     Workflow manager.
	 * @param HandlerRegistry  $registry     Handler registry.
	 * @param Config           $config       Configuration.
	 * @param RateLimiter|null $rate_limiter Optional rate limiter.
	 * @param Scheduler|null   $scheduler    Optional scheduler for recurring jobs.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
		private readonly Workflow $workflow,
		private readonly HandlerRegistry $registry,
		private readonly Config $config,
		private readonly ?RateLimiter $rate_limiter = null,
		private readonly ?Scheduler $scheduler = null,
	) {}

	/**
	 * Run the worker loop.
	 *
	 * @param string $queue_name Queue to process.
	 * @param bool   $once       If true, process one batch and exit.
	 */
	public function run( string $queue_name = 'default', bool $once = false ): void {
		$jobs_processed       = 0;
		$stale_check_timer    = time();
		$schedule_check_timer = time();

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

				// Check for due schedules periodically.
				if ( null !== $this->scheduler && time() - $schedule_check_timer >= 60 ) {
					$this->scheduler->tick();
					$schedule_check_timer = time();
				}

				continue;
			}

			// Check rate limiting before processing.
			if ( null !== $this->rate_limiter ) {
				$this->register_handler_rate_limit( $job->handler );

				if ( $this->rate_limiter->is_limited( $job->handler ) ) {
					$this->queue->unclaim( $job->id );
					if ( $once ) {
						break;
					}
					sleep( Config::worker_sleep() );
					continue;
				}
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

			// Periodic schedule check.
			if ( null !== $this->scheduler && time() - $schedule_check_timer >= 60 ) {
				$this->scheduler->tick();
				$schedule_check_timer = time();
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
		$count        = 0;
		$last_skipped = null;
		$skip_streak  = 0;

		while ( true ) {
			$job = $this->queue->claim( $queue_name );
			if ( null === $job ) {
				break;
			}

			// Check rate limiting before processing.
			if ( null !== $this->rate_limiter ) {
				$this->register_handler_rate_limit( $job->handler );

				if ( $this->rate_limiter->is_limited( $job->handler ) ) {
					$this->queue->unclaim( $job->id );

					// Prevent infinite loop: if we keep skipping the same job, break.
					if ( $last_skipped === $job->id ) {
						++$skip_streak;
						if ( $skip_streak >= 2 ) {
							break;
						}
					} else {
						$last_skipped = $job->id;
						$skip_streak  = 1;
					}
					continue;
				}
			}

			$last_skipped = null;
			$skip_streak  = 0;

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

			// Register rate limit from handler config if available.
			if ( null !== $this->rate_limiter && $handler instanceof Handler ) {
				$config = $handler->config();
				if ( isset( $config['rate_limit'] ) && is_array( $config['rate_limit'] ) ) {
					$this->rate_limiter->register(
						$job->handler,
						(int) $config['rate_limit'][0],
						(int) $config['rate_limit'][1],
					);
				}
			}

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

			// Record successful execution for rate limiting.
			if ( null !== $this->rate_limiter ) {
				$this->rate_limiter->record( $job->handler );
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
	 * Register rate limit for a handler from its config, if available.
	 *
	 * @param string $handler Handler name or class.
	 */
	private function register_handler_rate_limit( string $handler ): void {
		if ( null === $this->rate_limiter ) {
			return;
		}

		if ( ! $this->registry->has( $handler ) ) {
			return;
		}

		try {
			$instance = $this->registry->resolve( $handler );
		} catch ( \Throwable ) {
			return;
		}

		if ( $instance instanceof Handler ) {
			$config = $instance->config();
			if ( isset( $config['rate_limit'] ) && is_array( $config['rate_limit'] ) ) {
				$this->rate_limiter->register(
					$handler,
					(int) $config['rate_limit'][0],
					(int) $config['rate_limit'][1],
				);
			}
		}
	}

	/**
	 * Signal the worker to stop after the current job.
	 */
	public function stop(): void {
		$this->should_stop = true;
	}
}
