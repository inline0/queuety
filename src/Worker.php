<?php
/**
 * Job worker process.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Job as JobContract;
use Queuety\Contracts\StreamingStep;
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
	 * @param Connection            $conn              Database connection.
	 * @param Queue                 $queue             Queue operations.
	 * @param Logger                $logger            Logger instance.
	 * @param Workflow              $workflow          Workflow manager.
	 * @param HandlerRegistry       $registry          Handler registry.
	 * @param Config                $config            Configuration.
	 * @param RateLimiter|null      $rate_limiter      Optional rate limiter.
	 * @param Scheduler|null        $scheduler         Optional scheduler for recurring jobs.
	 * @param WebhookNotifier|null  $webhook_notifier  Optional webhook notifier.
	 * @param BatchManager|null     $batch_manager     Optional batch manager.
	 * @param ChunkStore|null       $chunk_store       Optional chunk store for streaming steps.
	 * @param WorkflowEventLog|null $event_log         Optional workflow event log.
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
		private readonly ?BatchManager $batch_manager = null,
		private readonly ?ChunkStore $chunk_store = null,
		private readonly ?WorkflowEventLog $event_log = null,
	) {}

	/**
	 * Parse a queue name parameter into an ordered list of queue names.
	 *
	 * Accepts a single queue name, a comma-separated string (e.g. "critical,default,low"),
	 * or an array of queue names. Returns an array in priority order.
	 *
	 * @param string|array $queue_name Queue name(s).
	 * @return string[] Ordered list of queue names.
	 */
	private function parse_queue_names( string|array $queue_name ): array {
		if ( is_array( $queue_name ) ) {
			return array_values( array_map( 'trim', $queue_name ) );
		}

		if ( str_contains( $queue_name, ',' ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $queue_name ) ) ) );
		}

		return array( $queue_name );
	}

	/**
	 * Try to claim a job from an ordered list of queues.
	 *
	 * Iterates through queues in priority order, skipping paused queues.
	 * Returns the first successfully claimed job, or null if all queues are empty.
	 *
	 * @param string[] $queues       Ordered list of queue names.
	 * @param bool     $log_paused   Whether to log paused queue messages.
	 * @return Job|null The claimed job, or null if no job is available.
	 */
	private function claim_from_queues( array $queues, bool $log_paused = false ): ?Job {
		foreach ( $queues as $q ) {
			if ( $this->queue->is_queue_paused( $q ) ) {
				if ( $log_paused ) {
					$this->logger->log(
						LogEvent::Started,
						array(
							'handler' => '_worker',
							'queue'   => $q,
							'context' => array( 'message' => "Queue '{$q}' is paused, skipping." ),
						)
					);
				}
				continue;
			}

			$this->debug_log( 'Attempting to claim job from queue.', $q );
			$job = $this->queue->claim( $q );

			if ( null !== $job ) {
				return $job;
			}

			$this->debug_log( 'No job claimed, queue is empty.', $q );
		}

		return null;
	}

	/**
	 * Run the worker loop.
	 *
	 * Supports processing multiple queues in priority order. When a comma-separated
	 * string or array is provided, the worker tries to claim from each queue in order,
	 * processing the first available job.
	 *
	 * @param string|array $queue_name Queue name(s) to process. Supports comma-separated
	 *                                 strings (e.g. "critical,default,low") or arrays.
	 * @param bool         $once       If true, process one batch and exit.
	 */
	public function run( string|array $queue_name = 'default', bool $once = false ): void {
		$queues               = $this->parse_queue_names( $queue_name );
		$jobs_processed       = 0;
		$stale_check_timer    = time();
		$schedule_check_timer = time();
		$deadline_check_timer = time();
		$primary_queue        = $queues[0];

		while ( ! $this->should_stop ) {
			$job = $this->claim_from_queues( $queues, log_paused: true );

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
					$this->debug_log( 'Running scheduler tick.', $primary_queue );
					$this->scheduler->tick();
					$schedule_check_timer = time();
				}

				// Check for expired workflow deadlines periodically.
				if ( time() - $deadline_check_timer >= 60 ) {
					$this->workflow->check_deadlines();
					$deadline_check_timer = time();
				}

				continue;
			}

			// Check rate limiting before processing.
			if ( null !== $this->rate_limiter ) {
				$this->register_handler_rate_limit( $job->handler );

				$this->debug_log( "Checking rate limit for handler: {$job->handler}", $job->queue );

				if ( $this->rate_limiter->is_limited( $job->handler ) ) {
					$this->debug_log( "Handler {$job->handler} is rate-limited, unclaiming job #{$job->id}.", $job->queue );
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

			// Periodic deadline check.
			if ( time() - $deadline_check_timer >= 60 ) {
				$this->workflow->check_deadlines();
				$deadline_check_timer = time();
			}
		}
	}

	/**
	 * Process all pending jobs in a queue (or multiple queues) until empty.
	 *
	 * When multiple queues are specified, they are processed in priority order.
	 * The worker claims from the highest-priority queue first and falls through
	 * to lower-priority queues only when higher ones are empty.
	 *
	 * @param string|array $queue_name Queue name(s) to flush. Supports comma-separated
	 *                                 strings (e.g. "critical,default,low") or arrays.
	 * @return int Total jobs processed.
	 */
	public function flush( string|array $queue_name = 'default' ): int {
		$queues       = $this->parse_queue_names( $queue_name );
		$count        = 0;
		$last_skipped = null;
		$skip_streak  = 0;

		while ( true ) {
			$job = $this->claim_from_queues( $queues );
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
		$max_attempts     = $job->max_attempts;
		$pcntl_available  = function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' );
		$previous_handler = null;
		$job_instance     = null;

		// Read job properties from the job class if it implements Contracts\Job.
		if ( $this->registry->is_job_class( $job->handler ) ) {
			$job_props = $this->read_job_properties( $job->handler );

			if ( null !== $job_props['timeout'] ) {
				$timeout_seconds = $job_props['timeout'];
			}
			if ( null !== $job_props['tries'] ) {
				$max_attempts = $job_props['tries'];
			}
		}

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

		// Record step_started event for workflow steps.
		if ( null !== $this->event_log && $job->is_workflow_step() && null !== $job->step_index ) {
			$this->event_log->record_step_started(
				workflow_id: $job->workflow_id,
				step_index: $job->step_index,
				handler: $job->handler,
			);
		}

		// Install timeout alarm if pcntl is available.
		if ( $pcntl_available ) {
			$alarm_callback   = static function () use ( $timeout_seconds ): void {
				throw new TimeoutException( $timeout_seconds );
			};
			$previous_handler = pcntl_signal( SIGALRM, $alarm_callback );
			pcntl_alarm( $timeout_seconds );
		}

		// Initialize heartbeat context for this job.
		Heartbeat::init( $job->id, $this->conn );

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
			} elseif ( $job->is_workflow_step() && '__queuety_timer' === $job->handler ) {
				// Handle durable timer: the delay already happened via available_at.
				// Mark the timer job as complete and advance the workflow.
				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->workflow->advance_step(
					workflow_id: $job->workflow_id,
					completed_job_id: $job->id,
					step_output: array(),
					duration_ms: $duration_ms,
				);

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
			} elseif ( $job->is_workflow_step() && '__queuety_signal' === $job->handler ) {
				// Handle signal step placeholder: trigger signal step processing in the workflow.
				$wf_state = $this->workflow->get_state( $job->workflow_id ) ?? array();
				$steps    = $wf_state['_steps'] ?? array();
				$step_def = $steps[ $job->step_index ] ?? null;

				// Mark the placeholder job as completed.
				$this->queue->complete( $job->id );

				if ( $step_def && 'signal' === ( $step_def['type'] ?? '' ) ) {
					// Delegate to the workflow's signal step handling.
					$this->workflow->handle_signal_step(
						workflow_id: $job->workflow_id,
						step_def: $step_def,
						step_index: $job->step_index,
					);
				}

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
			} elseif ( $this->registry->is_job_class( $job->handler ) ) {
				// Contracts\Job class: deserialize, run through middleware pipeline.
				$this->debug_log( "Deserializing job class: {$job->handler}", $job->queue );
				$job_instance = JobSerializer::deserialize( $job->handler, $job->payload );

				// Collect middleware from the job if it defines a middleware() method.
				$middleware = array();
				if ( method_exists( $job_instance, 'middleware' ) ) {
					$middleware = $job_instance->middleware();
				}

				// Build the core closure that calls handle().
				$queue_ref   = $this->queue;
				$logger_ref  = $this->logger;
				$webhook_ref = $this;
				$job_record  = $job;
				$start_ref   = $start_time;
				$core        = function ( object $job_obj ) use ( $queue_ref, $logger_ref, $job_record, $start_ref ): void {
					$job_obj->handle();

					$duration_ms = (int) ( ( hrtime( true ) - $start_ref ) / 1_000_000 );

					$queue_ref->complete( $job_record->id );

					$logger_ref->log(
						LogEvent::Completed,
						array(
							'job_id'         => $job_record->id,
							'handler'        => $job_record->handler,
							'queue'          => $job_record->queue,
							'attempt'        => $job_record->attempts,
							'duration_ms'    => $duration_ms,
							'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
						)
					);
				};

				if ( ! empty( $middleware ) ) {
					$pipeline = new MiddlewarePipeline();
					$pipeline->run( $job_instance, $middleware, $core );
				} else {
					$core( $job_instance );
				}

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->notify_webhook(
					'job.completed',
					array(
						'job_id'      => $job->id,
						'handler'     => $job->handler,
						'queue'       => $job->queue,
						'duration_ms' => $duration_ms,
					)
				);

				// Record batch completion if part of a batch.
				$this->record_batch_completion( $job );
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

				if ( $job->is_workflow_step() && $handler instanceof StreamingStep ) {
					// Streaming workflow step: stream chunks with persistence.
					$this->process_streaming_step( $job, $handler, $start_time );
				} elseif ( $job->is_workflow_step() && $handler instanceof Step ) {
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

					// Record batch completion if part of a batch.
					$this->record_batch_completion( $job );
				}
			} // End of handler/step else block.

			// Record successful execution for rate limiting.
			if ( null !== $this->rate_limiter ) {
				$this->rate_limiter->record( $job->handler );
			}
		} catch ( \Throwable $e ) {
			$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

			// Record step_failed event for workflow steps.
			if ( null !== $this->event_log && $job->is_workflow_step() && null !== $job->step_index ) {
				$this->event_log->record_step_failed(
					workflow_id: $job->workflow_id,
					step_index: $job->step_index,
					handler: $job->handler,
					error: $e->getMessage(),
					duration_ms: $duration_ms,
				);
			}

			// Determine effective max_attempts (job property overrides DB value).
			$effective_max = $max_attempts;

			// Calculate backoff: check job class properties for escalating backoff.
			$custom_backoff = null;
			if ( isset( $job_props ) && ! empty( $job_props['backoff'] ) ) {
				$backoff_array  = $job_props['backoff'];
				$attempt_index  = min( $job->attempts - 1, count( $backoff_array ) - 1 );
				$custom_backoff = $backoff_array[ max( 0, $attempt_index ) ];
			}

			if ( $job->attempts >= $effective_max ) {
				// Max attempts reached, bury the job.
				$this->queue->bury( $job->id, $e->getMessage() );

				// Call failed() hook on the job instance if available.
				$this->call_failed_hook( $job, $job_instance, $e );

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

				// Record batch failure if part of a batch.
				$this->record_batch_failure( $job );
			} else {
				// Retry with backoff.
				$backoff = $custom_backoff ?? Queue::calculate_backoff( $job->attempts, Config::retry_backoff() );
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
			// Clear heartbeat context.
			Heartbeat::clear();

			// Reset the alarm and restore previous signal handler.
			if ( $pcntl_available ) {
				pcntl_alarm( 0 );
				if ( is_callable( $previous_handler ) || SIG_DFL === $previous_handler || SIG_IGN === $previous_handler ) {
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
	 * Read job properties (tries, timeout, backoff) from a Contracts\Job class via reflection.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return array{tries: int|null, timeout: int|null, max_exceptions: int|null, backoff: array|null}
	 */
	private function read_job_properties( string $handler_class ): array {
		$result = array(
			'tries'          => null,
			'timeout'        => null,
			'max_exceptions' => null,
			'backoff'        => null,
		);

		try {
			$reflection = new \ReflectionClass( $handler_class );
		} catch ( \ReflectionException ) {
			return $result;
		}

		foreach ( array( 'tries', 'timeout', 'max_exceptions' ) as $prop_name ) {
			if ( $reflection->hasProperty( $prop_name ) ) {
				$prop = $reflection->getProperty( $prop_name );
				if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
					$result[ $prop_name ] = $prop->getDefaultValue();
				}
			}
		}

		if ( $reflection->hasProperty( 'backoff' ) ) {
			$prop = $reflection->getProperty( 'backoff' );
			if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
				$value = $prop->getDefaultValue();
				if ( is_array( $value ) ) {
					$result['backoff'] = $value;
				}
			}
		}

		return $result;
	}

	/**
	 * Call the failed() hook on a job instance if it defines one.
	 *
	 * @param Job              $job          The job record.
	 * @param JobContract|null $job_instance The deserialized job instance, if available.
	 * @param \Throwable       $exception    The exception that caused the failure.
	 */
	private function call_failed_hook( Job $job, ?JobContract $job_instance, \Throwable $exception ): void {
		// Try to use the already-deserialized instance first.
		if ( null === $job_instance && $this->registry->is_job_class( $job->handler ) ) {
			try {
				$job_instance = JobSerializer::deserialize( $job->handler, $job->payload );
			} catch ( \Throwable ) {
				return;
			}
		}

		if ( null === $job_instance ) {
			return;
		}

		if ( method_exists( $job_instance, 'failed' ) ) {
			try {
				$job_instance->failed( $exception );
			} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Failed hook errors are non-fatal.
			}
		}
	}

	/**
	 * Process a streaming workflow step.
	 *
	 * Loads any existing chunks from a previous (failed) attempt, calls the
	 * handler's stream() generator, persists each yielded chunk immediately,
	 * sends heartbeats per chunk, and finally calls on_complete() to merge
	 * results into the workflow state.
	 *
	 * @param Job           $job        The claimed job.
	 * @param StreamingStep $handler    The streaming step handler.
	 * @param int|float     $start_time High-resolution start time from hrtime(true).
	 * @throws \RuntimeException If no ChunkStore is configured.
	 */
	private function process_streaming_step( Job $job, StreamingStep $handler, int|float $start_time ): void {
		if ( null === $this->chunk_store ) {
			throw new \RuntimeException( 'ChunkStore is required for streaming steps. Pass it to the Worker constructor.' );
		}

		$state = $this->workflow->get_state( $job->workflow_id ) ?? array();

		// Load existing chunks from a previous (possibly failed) attempt.
		$existing_chunks = $this->chunk_store->get_chunks( $job->id );
		$chunk_index     = count( $existing_chunks );

		// Call the handler's stream generator.
		$generator = $handler->stream( $state, $existing_chunks );

		foreach ( $generator as $chunk ) {
			$content = (string) $chunk;

			// Persist the chunk immediately.
			$this->chunk_store->append_chunk(
				job_id: $job->id,
				chunk_index: $chunk_index,
				content: $content,
				workflow_id: $job->workflow_id,
				step_index: $job->step_index,
			);

			++$chunk_index;

			// Send heartbeat so the stale detector does not kill us.
			Heartbeat::beat( array( 'streaming_chunks' => $chunk_index ) );
		}

		// Gather all chunks (existing + new).
		$all_chunks = $this->chunk_store->get_chunks( $job->id );

		// Call on_complete to get the data to merge into workflow state.
		$output = $handler->on_complete( $all_chunks, $state );

		$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

		// Clean up chunks now that on_complete has the data.
		$this->chunk_store->clear_chunks( $job->id );

		// Advance the workflow with the on_complete result.
		$this->workflow->advance_step(
			workflow_id: $job->workflow_id,
			completed_job_id: $job->id,
			step_output: $output,
			duration_ms: $duration_ms,
		);
	}

	/**
	 * Record a batch completion for a job if it belongs to a batch.
	 *
	 * @param Job $job The completed job record.
	 */
	private function record_batch_completion( Job $job ): void {
		if ( null === $this->batch_manager || null === $job->batch_id ) {
			return;
		}

		$this->batch_manager->record_completion( $job->batch_id );
	}

	/**
	 * Record a batch failure for a job if it belongs to a batch.
	 *
	 * @param Job $job The failed job record.
	 */
	private function record_batch_failure( Job $job ): void {
		if ( null === $this->batch_manager || null === $job->batch_id ) {
			return;
		}

		$this->batch_manager->record_failure( $job->batch_id, $job->id );
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
