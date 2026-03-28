<?php
/**
 * Job worker process.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\BackoffStrategy;
use Queuety\Enums\LogEvent;
use Queuety\Exceptions\TimeoutException;

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
	 * @param Connection           $conn              Database connection.
	 * @param Queue                $queue             Queue operations.
	 * @param Logger               $logger            Logger instance.
	 * @param Workflow             $workflow          Workflow manager.
	 * @param HandlerRegistry      $registry          Handler registry.
	 * @param Config               $config            Configuration.
	 * @param RateLimiter|null     $rate_limiter      Optional rate limiter.
	 * @param Scheduler|null       $scheduler         Optional scheduler for recurring jobs.
	 * @param WebhookNotifier|null $webhook_notifier  Optional webhook notifier.
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
		private readonly ?WebhookNotifier $webhook_notifier = null,
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
			// Check if queue is paused before claiming.
			if ( $this->queue->is_queue_paused( $queue_name ) ) {
				if ( $once ) {
					break;
				}
				$this->logger->log(
					LogEvent::Started,
					array(
						'handler' => '_worker',
						'queue'   => $queue_name,
						'context' => array( 'message' => "Queue '{$queue_name}' is paused, skipping." ),
					)
				);
				sleep( Config::worker_sleep() );
				continue;
			}

			$this->debug_log( 'Attempting to claim job from queue.', $queue_name );

			$job = $this->queue->claim( $queue_name );

			if ( null === $job ) {
				$this->debug_log( 'No job claimed, queue is empty.', $queue_name );

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
					$this->debug_log( 'Running scheduler tick.', $queue_name );
					$this->scheduler->tick();
					$schedule_check_timer = time();
				}

				continue;
			}

			// Check rate limiting before processing.
			if ( null !== $this->rate_limiter ) {
				$this->register_handler_rate_limit( $job->handler );

				$this->debug_log( "Checking rate limit for handler: {$job->handler}", $queue_name );

				if ( $this->rate_limiter->is_limited( $job->handler ) ) {
					$this->debug_log( "Handler {$job->handler} is rate-limited, unclaiming job #{$job->id}.", $queue_name );
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
			// Check if queue is paused before claiming.
			if ( $this->queue->is_queue_paused( $queue_name ) ) {
				break;
			}

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
	 * @throws TimeoutException If the job exceeds max execution time (caught internally).
	 */
	public function process_job( Job $job ): void {
		$start_time       = hrtime( true );
		$timeout_seconds  = Config::max_execution_time();
		$pcntl_available  = function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' );
		$previous_handler = null;

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

		// Install timeout alarm if pcntl is available.
		if ( $pcntl_available ) {
			$alarm_callback   = static function () use ( $timeout_seconds ): void {
				throw new TimeoutException( $timeout_seconds );
			};
			$previous_handler = pcntl_signal( SIGALRM, $alarm_callback );
			pcntl_alarm( $timeout_seconds );
		}

		try {
			// Handle sub-workflow placeholder jobs directly.
			if ( $job->is_workflow_step() && '__queuety_sub_workflow' === $job->handler ) {
				$state = $this->workflow->get_state( $job->workflow_id ) ?? array();

				$this->workflow->handle_sub_workflow_step(
					workflow_id: $job->workflow_id,
					job_id: $job->id,
					step_index: $job->step_index,
					workflow_state: $state,
				);

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->logger->log(
					LogEvent::Completed,
					array(
						'job_id'         => $job->id,
						'workflow_id'    => $job->workflow_id,
						'step_index'     => $job->step_index,
						'handler'        => $job->handler,
						'queue'          => $job->queue,
						'duration_ms'    => $duration_ms,
						'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
					)
				);
			} else {

				$this->debug_log( "Resolving handler: {$job->handler}", $job->queue );
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

					$this->notify_webhook(
						'job.completed',
						array(
							'job_id'      => $job->id,
							'handler'     => $job->handler,
							'queue'       => $job->queue,
							'duration_ms' => $duration_ms,
						)
					);
				}
			} // End of sub-workflow else block.

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

				$this->notify_webhook(
					'job.buried',
					array(
						'job_id'        => $job->id,
						'handler'       => $job->handler,
						'queue'         => $job->queue,
						'error_message' => $e->getMessage(),
					)
				);

				// If part of a workflow, mark the workflow as failed.
				if ( $job->is_workflow_step() ) {
					$this->workflow->fail( $job->workflow_id, $job->id, $e->getMessage() );

					$this->notify_webhook(
						'workflow.failed',
						array(
							'workflow_id'   => $job->workflow_id,
							'job_id'        => $job->id,
							'handler'       => $job->handler,
							'error_message' => $e->getMessage(),
						)
					);
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

				$this->notify_webhook(
					'job.failed',
					array(
						'job_id'        => $job->id,
						'handler'       => $job->handler,
						'queue'         => $job->queue,
						'attempt'       => $job->attempts,
						'error_message' => $e->getMessage(),
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
		} finally {
			// Reset the alarm and restore previous signal handler.
			if ( $pcntl_available ) {
				pcntl_alarm( 0 );
				if ( null !== $previous_handler ) {
					pcntl_signal( SIGALRM, $previous_handler );
				} else {
					pcntl_signal( SIGALRM, SIG_DFL );
				}
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

		// Don't overwrite an explicitly registered rate limit.
		if ( $this->rate_limiter->is_registered( $handler ) ) {
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
	 * Write a debug log entry if debug mode is enabled.
	 *
	 * @param string $message Debug message.
	 * @param string $queue   Queue name.
	 */
	private function debug_log( string $message, string $queue = 'default' ): void {
		if ( ! Config::debug() ) {
			return;
		}

		$this->logger->log(
			LogEvent::Debug,
			array(
				'handler' => '_worker',
				'queue'   => $queue,
				'context' => array( 'message' => $message ),
			)
		);
	}

	/**
	 * Send a webhook notification if a notifier is configured.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Payload data.
	 */
	private function notify_webhook( string $event, array $data ): void {
		if ( null === $this->webhook_notifier ) {
			return;
		}

		$this->webhook_notifier->notify( $event, $data );
	}

	/**
	 * Signal the worker to stop after the current job.
	 */
	public function stop(): void {
		$this->should_stop = true;
	}
}
