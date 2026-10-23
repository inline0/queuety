<?php
/**
 * Job worker process.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\ForEachHandler;
use Queuety\Contracts\Job as JobContract;
use Queuety\Contracts\StreamingStep;
use Queuety\Enums\BackoffStrategy;
use Queuety\Enums\LogEvent;
use Queuety\Exceptions\LostClaimException;
use Queuety\Exceptions\RetryAtException;
use Queuety\Exceptions\TerminalFailureException;
use Queuety\Exceptions\TimeoutException;
use Queuety\Exceptions\WaitException;
use Queuety\Exceptions\WorkflowConstraintViolationException;

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

	/** Last successful maintenance pass.
	 *
	 * @var int
	 */
	private int $last_maintenance_at = 0;

	/**
	 * External batch callbacks released only after durable bookkeeping commits.
	 *
	 * @var list<array{batch_id: int, type: string}>
	 */
	private array $batch_callbacks = array();

	/**
	 * Constructor.
	 *
	 * @param Connection            $conn              Database connection.
	 * @param Queue                 $queue             Queue operations.
	 * @param Logger                $logger            Logger instance.
	 * @param Workflow              $workflow          Workflow manager.
	 * @param HandlerRegistry       $registry          Handler registry.
	 * @param Config                $config            Configuration.
	 * @param RateLimiter|null      $rate_limiter     Optional rate limiter.
	 * @param Scheduler|null        $scheduler        Optional scheduler for recurring jobs.
	 * @param WebhookNotifier|null  $webhook_notifier Optional webhook notifier.
	 * @param BatchManager|null     $batch_manager    Optional batch manager.
	 * @param ChunkStore|null       $chunk_store      Optional chunk store for streaming steps.
	 * @param WorkflowEventLog|null $event_log        Optional workflow event log.
	 * @param StateMachine|null     $state_machines   Optional state machine manager.
	 * @param ResourceManager|null  $resource_manager Optional resource manager for admission and concurrency policies.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
		private readonly Workflow $workflow,
		private readonly HandlerRegistry $registry,
		Config $config,
		private readonly ?RateLimiter $rate_limiter = null,
		private readonly ?Scheduler $scheduler = null,
		private readonly ?WebhookNotifier $webhook_notifier = null,
		private readonly ?BatchManager $batch_manager = null,
		private readonly ?ChunkStore $chunk_store = null,
		private readonly ?WorkflowEventLog $event_log = null,
		private readonly ?StateMachine $state_machines = null,
		private readonly ?ResourceManager $resource_manager = null,
	) {}

	/**
	 * Parse a queue name parameter into an ordered list of queue names.
	 *
	 * Accepts a single queue name, a comma-separated string (e.g. "critical,default,low"),
	 * or an array of queue names. Returns an array in priority order.
	 *
	 * @param string|array<int, string> $queue_name Queue name(s).
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
	 * @param int|null $lease_seconds Ownership duration, or the configured default.
	 * @param int|null $action_seconds Action budget, or the configured default.
	 * @return Job|null The claimed job, or null if no job is available.
	 */
	private function claim_from_queues( array $queues, bool $log_paused = false, ?int $lease_seconds = null, ?int $action_seconds = null ): ?Job {
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
			$job = $this->queue->claim( $q, $lease_seconds ?? Config::stale_timeout(), $action_seconds );

			if ( null !== $job ) {
				return $job;
			}

			$this->debug_log( 'No job claimed, queue is empty.', $q );
		}

		return null;
	}

	/**
	 * Decide whether one claimed job should proceed to execution.
	 *
	 * Denied jobs are returned to pending so another worker mode or queue can
	 * pick them up later.
	 *
	 * @param Job  $job                 Claimed job.
	 * @param bool $respect_time_budget Whether to enforce a once-run time budget.
	 * @param int  $run_started_at      Worker-loop start time from hrtime(true).
	 * @return bool
	 */
	private function admit_claimed_job( Job $job, bool $respect_time_budget, int $run_started_at ): bool {
		if ( null === $this->resource_manager ) {
			return true;
		}

		$elapsed_ms = (int) ( ( hrtime( true ) - $run_started_at ) / 1_000_000 );
		$decision   = $this->resource_manager->admit( $job, $respect_time_budget, $elapsed_ms );
		if ( $decision['allowed'] ) {
			return true;
		}

		$this->debug_log(
			sprintf(
				'Deferring job #%d (%s): %s',
				$job->id,
				$job->handler,
				$decision['reason'] ?? 'resource policy'
			),
			$job->queue
		);
		$this->queue->unclaim( $job->id, claim_token: $job->claim_token );
		return false;
	}

	/**
	 * Execute at most one action without scheduling, recovery or retention work.
	 *
	 * Handlers must apply the remaining deadline to their own transports. This
	 * budget is cooperative; only an external watchdog can stop arbitrary I/O.
	 *
	 * @param string|array<int, string> $queue_name Queue names in priority order.
	 * @param int                       $action_seconds Cooperative action budget in seconds.
	 * @param int                       $lease_seconds Ownership lease in seconds, longer than the budget.
	 * @return Job|null The attempted job, or null when no job was admitted.
	 * @throws \InvalidArgumentException If the lease does not cover the action.
	 */
	public function tick( string|array $queue_name = 'default', int $action_seconds = 20, int $lease_seconds = 30 ): ?Job {
		if ( $action_seconds < 1 || $lease_seconds <= $action_seconds ) {
			throw new \InvalidArgumentException( 'The lease must be longer than the positive action budget.' );
		}
		Schema::assert_compatible( $this->conn );
		$started = hrtime( true );
		$job = $this->claim_from_queues( $this->parse_queue_names( $queue_name ), lease_seconds: $lease_seconds, action_seconds: $action_seconds );
		if ( null === $job ) {
			return null;
		}
		if ( null !== $this->rate_limiter ) {
			$this->register_job_rate_limit( $job );
			if ( $this->rate_limiter->is_limited( $job->handler ) ) {
				$this->queue->unclaim( $job->id, claim_token: $job->claim_token );
				return null;
			}
		}
		if ( ! $this->admit_claimed_job( $job, true, $started ) ) {
			return null;
		}
		$this->process_job( $job, $started + $action_seconds * 1_000_000_000, $lease_seconds );
		return $job;
	}

	/**
	 * Run the worker loop.
	 *
	 * Supports processing multiple queues in priority order. When a comma-separated
	 * string or array is provided, the worker tries to claim from each queue in order,
	 * processing the first available job.
	 *
	 * @param string|array<int, string> $queue_name Queue name(s) to process. Supports comma-separated
	 *                                              strings (e.g. "critical,default,low") or arrays.
	 * @param bool                      $once       If true, process one batch and exit.
	 * @param bool                      $run_maintenance Run upkeep in this process; pool children delegate to the parent.
	 */
	public function run( string|array $queue_name = 'default', bool $once = false, bool $run_maintenance = true ): void {
		$queues               = $this->parse_queue_names( $queue_name );
		$jobs_processed       = 0;
		$run_started_at       = hrtime( true );

		$previous_handlers = array();
		$previous_async    = null;
		if ( function_exists( 'pcntl_signal' ) && function_exists( 'pcntl_async_signals' ) ) {
			$previous_async = pcntl_async_signals( true );
			foreach ( array( SIGTERM, SIGINT ) as $signal ) {
				$previous_handlers[ $signal ] = pcntl_signal_get_handler( $signal );
				pcntl_signal(
					$signal,
					function (): void {
						$this->stop();
					}
				);
			}
		}

		try {
			if ( $run_maintenance ) {
				$this->maintenance( true );
			}
			while ( ! $this->should_stop ) {
				if ( $run_maintenance ) {
					$this->maintenance();
				}
				$job = $this->claim_from_queues( $queues, log_paused: true );

				if ( null === $job ) {
					if ( $once ) {
						break;
					}
					sleep( Config::worker_sleep() );

					continue;
				}

				if ( null !== $this->rate_limiter ) {
					$this->register_job_rate_limit( $job );

					$this->debug_log( "Checking rate limit for handler: {$job->handler}", $job->queue );

					if ( $this->rate_limiter->is_limited( $job->handler ) ) {
						$this->debug_log( "Handler {$job->handler} is rate-limited, unclaiming job #{$job->id}.", $job->queue );
						$this->queue->unclaim( $job->id, claim_token: $job->claim_token );
						if ( $once ) {
							break;
						}
						sleep( Config::worker_sleep() );
						continue;
					}
				}

				if ( ! $this->admit_claimed_job( $job, $once, $run_started_at ) ) {
					if ( $once ) {
						break;
					}
					sleep( Config::worker_sleep() );
					continue;
				}

				$this->process_job( $job );
				++$jobs_processed;

				if ( $once ) {
					break;
				}

				$memory_mb = memory_get_usage( true ) / 1024 / 1024;
				if ( $memory_mb >= Config::worker_max_memory() ) {
					break;
				}
				if ( $jobs_processed >= Config::worker_max_jobs() ) {
					break;
				}
			}
		} finally {
			foreach ( $previous_handlers as $signal => $handler ) {
				pcntl_signal( $signal, $handler );
			}
			if ( null !== $previous_async ) {
				pcntl_async_signals( $previous_async );
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
	 * @param string|array<int, string> $queue_name Queue name(s) to flush. Supports comma-separated
	 *                                              strings (e.g. "critical,default,low") or arrays.
	 * @param int|null                  $time_budget_seconds Stop claiming jobs when this budget is spent; the active job finishes.
	 * @return int Total jobs processed.
	 */
	public function flush( string|array $queue_name = 'default', ?int $time_budget_seconds = null ): int {
		$queues         = $this->parse_queue_names( $queue_name );
		$count          = 0;
		$last_skipped   = null;
		$skip_streak    = 0;
		$run_started_at = hrtime( true );

		$this->maintenance( true );
		while ( true ) {
			if ( null !== $time_budget_seconds && ( hrtime( true ) - $run_started_at ) / 1_000_000_000 >= $time_budget_seconds ) {
				break;
			}
			$this->maintenance();
			$job = $this->claim_from_queues( $queues );
			if ( null === $job ) {
				break;
			}

			if ( null !== $this->rate_limiter ) {
				$this->register_job_rate_limit( $job );

				if ( $this->rate_limiter->is_limited( $job->handler ) ) {
					$this->queue->unclaim( $job->id, claim_token: $job->claim_token );

					// Flush mode should not spin forever on a single rate-limited head job.
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

			if ( ! $this->admit_claimed_job( $job, false, $run_started_at ) ) {
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

			$last_skipped = null;
			$skip_streak  = 0;

			$this->process_job( $job );
			++$count;
		}
		return $count;
	}

	/**
	 * Run scheduled jobs, recovery, deadlines and configured retention cleanup.
	 *
	 * @param bool $force Run even when the last pass was less than a minute ago.
	 */
	public function maintenance( bool $force = false ): void {
		if ( ! $force && time() - $this->last_maintenance_at < 60 ) {
			return;
		}
		for ( $cursor = 0; $cursor < 5; ++$cursor ) {
			$this->maintenance_batch( cursor: $cursor );
		}
		$this->last_maintenance_at = time();
	}

	/**
	 * Run one bounded maintenance phase and return the next phase cursor.
	 *
	 * Cursor phases are recovery, schedules, deadlines, completed jobs and logs.
	 * Persist the returned cursor between short HTTP wakeups. Each phase removes
	 * or advances eligible rows, so repeated cycles drain a backlog without OFFSET.
	 *
	 * @param int $limit Maximum records considered in this phase (1 to 1000).
	 * @param int $cursor Phase cursor (0 to 4).
	 * @return array{cursor: int, processed: int} Next cursor and affected row count.
	 * @throws \InvalidArgumentException If the cursor or limit is out of range.
	 */
	public function maintenance_batch( int $limit = 100, int $cursor = 0 ): array {
		if ( $limit < 1 || $limit > 1000 || $cursor < 0 || $cursor > 4 ) {
			throw new \InvalidArgumentException( 'Maintenance requires a limit of 1 to 1000 and cursor of 0 to 4.' );
		}
		Schema::assert_compatible( $this->conn );
		$count = match ( $cursor ) {
			0 => $this->recover_stale( $limit ),
			1 => $this->scheduler?->tick( $limit ) ?? 0,
			2 => $this->workflow->check_deadlines( $limit ),
			3 => Config::retention_days() > 0 ? $this->queue->purge_completed( Config::retention_days(), $limit ) : 0,
			4 => Config::log_retention_days() > 0 ? $this->logger->purge( Config::log_retention_days(), $limit ) : 0,
		};
		return array(
			'cursor' => ( $cursor + 1 ) % 5,
			'processed' => $count,
		);
	}

	/**
	 * Execute a single job.
	 *
	 * @param Job      $job The claimed job.
	 * @param int|null $deadline_ns Optional monotonic action deadline.
	 * @param int      $lease_seconds Heartbeat lease duration.
	 * @throws \LogicException If execution starts inside an application transaction.
	 * @throws \RuntimeException If an internal workflow placeholder is malformed.
	 * @throws TerminalFailureException If a workflow wait definition is invalid.
	 * @throws \Throwable When the database transport is discarded and reconciliation is required.
	 */
	public function process_job( Job $job, ?int $deadline_ns = null, int $lease_seconds = 600 ): void {
		$this->batch_callbacks = array();
		if ( $this->conn->pdo()->inTransaction() ) {
			throw new \LogicException( 'Worker execution must start outside an application transaction.' );
		}
		try {
			$this->queue->assert_claim( $job->id, $job->claim_token ?? '' );
		} catch ( LostClaimException $e ) {
			return;
		}
		$start_time       = hrtime( true );
		$timeout_seconds  = Config::max_execution_time();
		$max_attempts     = $job->max_attempts;
		$backoff_strategy = null;
		$custom_backoff   = null;
		$pcntl_available  = function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' );
		$job_instance     = null;
		$runtime_options  = StepDispatchOptions::runtime_from_payload( $job->payload );
		$workflow_trace   = $job->is_workflow_step() ? $this->workflow_step_trace_info( $job ) : null;

		if ( $this->registry->is_job_class( $job->handler ) ) {
			$job_props = $this->read_job_properties( $job->handler );

			if ( null !== $job_props['timeout'] ) {
				$timeout_seconds = $job_props['timeout'];
			}
			if ( null !== $job_props['tries'] ) {
				$max_attempts = $job_props['tries'];
			}
			if ( ! empty( $job_props['backoff'] ) ) {
				$custom_backoff = $job_props['backoff'];
			}
		} else {
			$handler_settings = $this->resolve_handler_retry_settings( $job->handler );

			if ( null !== $handler_settings['max_attempts'] ) {
				$max_attempts = $handler_settings['max_attempts'];
			}

			if ( is_array( $handler_settings['backoff'] ) ) {
				$custom_backoff = $handler_settings['backoff'];
			} elseif ( is_string( $handler_settings['backoff'] ) ) {
				$backoff_strategy = BackoffStrategy::tryFrom( $handler_settings['backoff'] );
			}
		}

		if ( isset( $runtime_options['timeout_seconds'] ) && is_int( $runtime_options['timeout_seconds'] ) && $runtime_options['timeout_seconds'] > 0 ) {
			$timeout_seconds = $runtime_options['timeout_seconds'];
		}

		if ( isset( $runtime_options['max_attempts'] ) && is_int( $runtime_options['max_attempts'] ) && $runtime_options['max_attempts'] > 0 ) {
			$max_attempts = $runtime_options['max_attempts'];
		}

		if ( isset( $runtime_options['backoff'] ) && is_array( $runtime_options['backoff'] ) ) {
			$custom_backoff   = $runtime_options['backoff'];
			$backoff_strategy = null;
		} elseif ( isset( $runtime_options['backoff'] ) && is_string( $runtime_options['backoff'] ) ) {
			$backoff_strategy = BackoffStrategy::tryFrom( $runtime_options['backoff'] );
			$custom_backoff   = null;
		}

		try {
			$this->queue->with_claim(
				$job->id,
				$job->claim_token ?? '',
				function () use ( $job, $workflow_trace ): void {
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

					if ( null !== $this->event_log && $job->is_workflow_step() ) {
						$this->event_log->record_step_started(
							workflow_id: $job->workflow_id,
							step_index: $job->step_index,
							handler: $job->handler,
							step_name: $workflow_trace['step_name'] ?? null,
							step_type: $workflow_trace['step_type'] ?? null,
							job_id: $job->id,
							attempt: $job->attempts,
							queue: $job->queue,
							input: $workflow_trace['input'] ?? null,
							state_before: $workflow_trace['state_before'] ?? null,
							context: $workflow_trace['context'] ?? null,
						);
					}
				}
			);
		} catch ( LostClaimException $e ) {
			return;
		}

		if ( null !== $deadline_ns ) {
			$timeout_seconds = min( $timeout_seconds, max( 1, (int) ceil( ( $deadline_ns - hrtime( true ) ) / 1_000_000_000 ) ) );
		}
		$alarm = $pcntl_available ? new AlarmGuard( $timeout_seconds ) : null;

		Heartbeat::init( $job->id, $this->conn, $job->claim_token, $lease_seconds );
		ExecutionContext::enter( $job->id, $job->workflow_id, $job->step_index, $job->payload, job: $job );
		ExecutionContext::set_deadline( $deadline_ns ?? $start_time + $timeout_seconds * 1_000_000_000 );
		if ( null !== $this->batch_manager && null !== $job->batch_id ) {
			ExecutionContext::on_completion(
				function ( Connection $conn, Job $completed ): void {
					$this->batch_callbacks = $this->batch_manager->stage_batch_completion( $completed );
				}
			);
		}
		if ( null !== $workflow_trace ) {
			ExecutionContext::set_trace_input( $workflow_trace['input'] ?? array() );
			if ( ! empty( $workflow_trace['context'] ) && is_array( $workflow_trace['context'] ) ) {
				ExecutionContext::add_trace_context( $workflow_trace['context'] );
			}
		}

		try {
			ExecutionContext::assert_active();
			if ( $this->is_state_machine_action_job( $job ) ) {
				$meta = $this->state_machine_action_meta( $job );

				if ( null === $meta || null === $this->state_machines ) {
					throw new \RuntimeException( 'State machine action payload is missing required metadata.' );
				}

				$this->state_machines->handle_action_job(
					machine_id: $meta['machine_id'],
					state_name: $meta['state_name'],
					event_name: $meta['event_name'],
					event_payload: $meta['event_payload'],
					runtime: array(
						'job_id'  => $job->id,
						'queue'   => $job->queue,
						'attempt' => $job->attempts,
					),
					job: $job,
				);

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

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
			} elseif ( $job->is_workflow_step() && '__queuety_run_workflow' === $job->handler ) {
				$state = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();

				$this->workflow->handle_run_workflow_step(
					workflow_id: $job->workflow_id,
					job_id: $job->id,
					step_index: $job->step_index,
					workflow_state: $state,
					job: $job,
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
			} elseif ( $job->is_workflow_step() && '__queuety_for_each' === $job->handler ) {
				$state = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();

				$placeholder_completed = $this->workflow->handle_for_each_step(
					workflow_id: $job->workflow_id,
					job_id: $job->id,
					step_index: $job->step_index,
					workflow_state: $state,
					job: $job,
				);

				if ( $placeholder_completed ) {
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
				}
			} elseif ( $job->is_workflow_step() && '__queuety_repeat' === $job->handler ) {
				$wf_state         = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
				$steps_raw        = $wf_state['_steps'] ?? array();
				$steps            = is_array( $steps_raw ) ? $steps_raw : array();
				$step_def_in      = $steps[ $job->step_index ] ?? null;
				$step_def_typed   = self::normalize_step_def( $step_def_in );
				$step_def         = is_array( $step_def_typed ) ? $step_def_typed : null;

				if ( null === $step_def || 'repeat' !== ( $step_def['type'] ?? '' ) ) {
					throw new \RuntimeException( "Workflow repeat step {$job->step_index} is missing or invalid." );
				}

				$step_output = $this->workflow->handle_repeat_step(
					workflow_id: $job->workflow_id,
					step_def: $step_def,
					step_index: $job->step_index,
					workflow_state: $wf_state,
				);

				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->assert_handler_transaction_closed();
				$this->workflow->advance_step(
					workflow_id: $job->workflow_id,
					completed_job_id: $job->id,
					step_output: $step_output,
					duration_ms: $duration_ms,
					job: $job,
				);
			} elseif ( $job->is_workflow_step() && '__queuety_delay' === $job->handler ) {
				$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

				$this->assert_handler_transaction_closed();
				$this->workflow->advance_step(
					workflow_id: $job->workflow_id,
					completed_job_id: $job->id,
					step_output: array(),
					duration_ms: $duration_ms,
					job: $job,
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
			} elseif ( $job->is_workflow_step() && '__queuety_wait_for_signal' === $job->handler ) {
				$wf_state       = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
				$steps_raw      = $wf_state['_steps'] ?? array();
				$steps          = is_array( $steps_raw ) ? $steps_raw : array();
				$step_def_in    = $steps[ $job->step_index ] ?? null;
				$step_def_typed = self::normalize_step_def( $step_def_in );
				$step_def       = is_array( $step_def_typed ) ? $step_def_typed : null;

				if ( null !== $step_def && 'wait_for_signal' === ( $step_def['type'] ?? '' ) ) {
					$this->workflow->handle_signal_step(
						workflow_id: $job->workflow_id,
						step_def: $step_def,
						step_index: $job->step_index,
						job: $job,
					);
				} else {
					throw new TerminalFailureException( 'The signal wait step definition is missing or invalid.' );
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
			} elseif ( $job->is_workflow_step() && '__queuety_wait_for_workflows' === $job->handler ) {
				$wf_state       = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
				$steps_raw      = $wf_state['_steps'] ?? array();
				$steps          = is_array( $steps_raw ) ? $steps_raw : array();
				$step_def_in    = $steps[ $job->step_index ] ?? null;
				$step_def_typed = self::normalize_step_def( $step_def_in );
				$step_def       = is_array( $step_def_typed ) ? $step_def_typed : null;

				if ( null !== $step_def && 'wait_for_workflows' === ( $step_def['type'] ?? '' ) ) {
					$this->workflow->handle_wait_for_workflows_step(
						workflow_id: $job->workflow_id,
						step_def: $step_def,
						step_index: $job->step_index,
						workflow_state: $wf_state,
						job: $job,
					);
				} else {
					throw new TerminalFailureException( 'The workflow wait step definition is missing or invalid.' );
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
			} elseif ( $job->is_workflow_step() && '__queuety_start_workflows' === $job->handler ) {
				$wf_state    = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
				$step_output = $this->workflow->handle_start_workflows_step(
					workflow_id: $job->workflow_id,
					step_index: $job->step_index,
					workflow_state: $wf_state,
					job: $job,
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
			} elseif ( $this->registry->is_job_class( $job->handler ) ) {
				$this->debug_log( "Deserializing job class: {$job->handler}", $job->queue );
				$job_instance = JobSerializer::deserialize( $job->handler, $job->payload );

				$middleware = array();
				if ( method_exists( $job_instance, 'middleware' ) ) {
					$declared = $job_instance->middleware();
					if ( is_array( $declared ) ) {
						foreach ( $declared as $entry ) {
							if ( $entry instanceof \Queuety\Contracts\Middleware ) {
								$middleware[] = $entry;
							}
						}
					}
				}

				$logger_ref  = $this->logger;
				$webhook_ref = $this;
				$job_record  = $job;
				$start_ref   = $start_time;
				$core        = function ( \Queuety\Contracts\Job $job_obj ) use ( $logger_ref, $job_record, $start_ref ): void {
					$job_obj->handle();

					$duration_ms = (int) ( ( hrtime( true ) - $start_ref ) / 1_000_000 );

					$this->complete_job( $job_record );

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

				$this->fire_batch_callbacks();
				$this->notify_webhook(
					'job.completed',
					array(
						'job_id'      => $job->id,
						'handler'     => $job->handler,
						'queue'       => $job->queue,
						'duration_ms' => $duration_ms,
					)
				);

			} else {

				$this->debug_log( "Resolving handler: {$job->handler}", $job->queue );
				$handler = str_starts_with( $job->handler, '__queuety_cron_' )
					? new CronEventHandler()
					: $this->registry->resolve( $job->handler );

				if ( null !== $this->rate_limiter && $handler instanceof Handler ) {
					$config = $handler->config();
					if (
						isset( $config['rate_limit'] )
						&& is_array( $config['rate_limit'] )
						&& ! $this->rate_limiter->is_registered( $job->handler )
					) {
						$rl_max    = $config['rate_limit'][0] ?? 0;
						$rl_window = $config['rate_limit'][1] ?? 0;
						$this->rate_limiter->register(
							$job->handler,
							is_scalar( $rl_max ) ? (int) $rl_max : 0,
							is_scalar( $rl_window ) ? (int) $rl_window : 0,
						);
					}
				}

				if ( $job->is_workflow_step() && $handler instanceof StreamingStep ) {
					$this->process_streaming_step( $job, $handler, $start_time );
				} elseif ( $job->is_workflow_step() && $handler instanceof ForEachHandler && $this->is_for_each_workflow_job( $job ) ) {
					$state           = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
					$branch_meta_raw = $job->payload['__for_each'] ?? array();
					$branch_meta     = is_array( $branch_meta_raw ) ? $branch_meta_raw : array();
					$item_index_raw  = $branch_meta['item_index'] ?? 0;
					$output          = $handler->handle_item(
						$state,
						$branch_meta['item'] ?? null,
						is_scalar( $item_index_raw ) ? (int) $item_index_raw : 0,
					);

					$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

					$this->assert_handler_transaction_closed();
					$this->workflow->advance_step(
						workflow_id: $job->workflow_id,
						completed_job_id: $job->id,
						step_output: $output,
						duration_ms: $duration_ms,
						job: $job,
					);
				} elseif ( $job->is_workflow_step() && $handler instanceof Step ) {
					$state  = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
					$output = $handler->handle( $state );

					$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

					$this->assert_handler_transaction_closed();
					$this->workflow->advance_step(
						workflow_id: $job->workflow_id,
						completed_job_id: $job->id,
						step_output: $output,
						duration_ms: $duration_ms,
						job: $job,
					);
				} else {
					if ( ! $handler instanceof Handler ) {
						throw new \RuntimeException( "Handler {$job->handler} is not invocable as a non-workflow handler." );
					}

					$handler->handle( $this->public_payload( $job->payload ) );

					$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

					$this->complete_job( $job );

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

					$this->fire_batch_callbacks();
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
			}

			$this->fire_batch_callbacks();
			if ( null !== $this->rate_limiter ) {
				$this->rate_limiter->record( $job->handler );
			}
		} catch ( LostClaimException $e ) {
			// A replacement owner or expired deadline alone decides the next action.
			return;
		} catch ( \Throwable $e ) {
			$this->batch_callbacks = array();
			if ( $this->conn->is_discarded() ) {
				throw $e;
			}
			if ( $this->conn->pdo()->inTransaction() ) {
				$this->conn->pdo()->rollBack();
			}
			try {
				$this->queue->assert_claim( $job->id, $job->claim_token ?? '' );
				if ( $e instanceof WaitException || ( $e instanceof RetryAtException && $job->attempts < $max_attempts ) ) {
					$this->queue->reschedule( $job->id, $e->available_at, ! $e instanceof WaitException, $job->claim_token );
					return;
				}
				$terminal = $e instanceof TerminalFailureException || $e instanceof WorkflowConstraintViolationException || $job->attempts >= $max_attempts;
				if ( $terminal ) {
					$this->fail_terminal( $job, $e->getMessage() );
				} else {
					if ( is_array( $custom_backoff ) ) {
						$index = max( 0, min( $job->attempts - 1, count( $custom_backoff ) - 1 ) );
						$picked = $custom_backoff[ $index ] ?? 0;
						$custom_backoff = is_scalar( $picked ) ? (int) $picked : 0;
					}
					$backoff = is_int( $custom_backoff ) ? $custom_backoff : Queue::calculate_backoff( $job->attempts, $backoff_strategy ?? Config::retry_backoff() );
					$this->queue->retry( $job->id, $backoff, claim_token: $job->claim_token );
				}
			} catch ( LostClaimException $lost ) {
				return;
			}
			$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );
			if ( null !== $this->event_log && $job->is_workflow_step() ) {
				$trace          = ExecutionContext::consume_trace();
				$trace_input    = $trace['input'] ?? null;
				$fallback_input = $workflow_trace['input'] ?? null;
				$raw_input      = is_array( $trace_input ) ? $trace_input : ( is_array( $fallback_input ) ? $fallback_input : null );
				$failed_input   = null === $raw_input ? null : self::to_string_keyed_array( $raw_input );
				$trace_context  = $trace['context'] ?? null;
				$this->event_log->record_step_failed(
					workflow_id: $job->workflow_id,
					step_index: $job->step_index,
					handler: $job->handler,
					error: array(
						'message' => $e->getMessage(),
						'class'   => get_class( $e ),
						'trace'   => $e->getTraceAsString(),
					),
					duration_ms: $duration_ms,
					step_name: $workflow_trace['step_name'] ?? null,
					step_type: $workflow_trace['step_type'] ?? null,
					job_id: $job->id,
					attempt: $job->attempts,
					queue: $job->queue,
					input: $failed_input,
					state_before: $workflow_trace['state_before'] ?? null,
					state_after: $workflow_trace['state_after'] ?? null,
					context: is_array( $trace_context ) ? $trace_context : ( $workflow_trace['context'] ?? null ),
				);
			}

			$this->logger->log(
				$terminal ? LogEvent::Buried : LogEvent::Failed,
				array(
					'job_id' => $job->id,
					'workflow_id' => $job->workflow_id,
					'step_index' => $job->step_index,
					'handler' => $job->handler,
					'queue' => $job->queue,
					'attempt' => $job->attempts,
					'duration_ms' => $duration_ms,
					'error_message' => $e->getMessage(),
					'error_class' => get_class( $e ),
					'error_trace' => $e->getTraceAsString(),
				)
			);

			$this->fire_batch_callbacks();
			if ( $terminal ) {
				ExecutionContext::without_claim(
					function () use ( $job, $job_instance, $e ): void {
						$this->call_failed_hook( $job, $job_instance, $e );
						$this->call_chain_catch( $job, $e );
					}
				);
			} else {
				$this->logger->log(
					LogEvent::Retried,
					array(
						'job_id' => $job->id,
						'workflow_id' => $job->workflow_id,
						'handler' => $job->handler,
						'queue' => $job->queue,
						'attempt' => $job->attempts,
					)
				);
			}
			$this->notify_webhook(
				$terminal ? 'job.buried' : 'job.failed',
				array(
					'job_id' => $job->id,
					'handler' => $job->handler,
					'queue' => $job->queue,
					'error_message' => $e->getMessage(),
				)
			);
		} finally {
			try {
				if ( ! $this->conn->is_discarded() && $this->conn->pdo()->inTransaction() ) {
					$this->conn->pdo()->rollBack();
				}
			} finally {
				ExecutionContext::clear();
				Heartbeat::clear();
				$this->batch_callbacks = array();
				$alarm?->close();
			}
		}
	}

	/**
	 * Refuse a handler-owned transaction that would delay or swallow completion.
	 *
	 * @throws TerminalFailureException When a handler leaves its transaction open.
	 */
	private function assert_handler_transaction_closed(): void {
		if ( $this->conn->pdo()->inTransaction() ) {
			$this->conn->pdo()->rollBack();
			throw new TerminalFailureException( 'A handler left a database transaction open. Use on_completion() for atomic checkpoints.' );
		}
	}

	/**
	 * Commit ordinary job completion and application checkpoints together.
	 *
	 * @param Job $job Current claim.
	 * @throws \Throwable If ownership or a checkpoint write fails.
	 */
	private function complete_job( Job $job ): void {
		$this->assert_handler_transaction_closed();
		$pdo = $this->conn->pdo();
		$pdo->beginTransaction();
		try {
			$this->queue->assert_claim( $job->id, $job->claim_token ?? '', true );
			ExecutionContext::flush_completion( $this->conn, $job );
			$this->queue->complete( $job->id, claim_token: $job->claim_token );
			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Atomically stop the job and its owning workflow or state machine.
	 *
	 * @param Job    $job Current claim, or the expired claim observed by recovery.
	 * @param string $message Durable failure reason.
	 * @param bool   $recover Whether to compare and recover an expired claim.
	 * @return bool Whether the observed job was transitioned.
	 * @throws \Throwable If the terminal transition cannot commit.
	 */
	private function fail_terminal( Job $job, string $message, bool $recover = false ): bool {
		if ( $job->is_workflow_step() ) {
			if ( $this->is_for_each_workflow_job( $job ) ) {
				$failed = $this->workflow->handle_for_each_terminal_failure( $job->workflow_id, $job->id, $message, job: $job, recover: $recover );
			} else {
				$this->workflow->fail( $job->workflow_id, $job->id, $message, job: $job, recover: $recover );
				$failed = true;
			}
			if ( $failed ) {
				$this->notify_webhook(
					'workflow.failed',
					array(
						'workflow_id' => $job->workflow_id,
						'job_id' => $job->id,
						'handler' => $job->handler,
						'error_message' => $message,
					)
				);
			}
			return true;
		}
		if ( $this->is_state_machine_action_job( $job ) && null !== $this->state_machines ) {
			$meta = $this->state_machine_action_meta( $job );
			if ( null !== $meta ) {
				$this->state_machines->fail_action(
					$meta['machine_id'],
					$meta['state_name'],
					$message,
					array(
						'job_id' => $job->id,
						'queue' => $job->queue,
						'attempt' => $job->attempts,
						'action_failed_recorded' => ! $recover,
					),
					job: $job,
					recover: $recover
				);
				return true;
			}
		}
		$pdo = $this->conn->pdo();
		$pdo->beginTransaction();
		try {
			if ( $recover ) {
				if ( ! $this->queue->recover( $job, bury: true, error_message: $message, timeout_seconds: Config::stale_timeout() ) ) {
					$pdo->rollBack();
					return false;
				}
			} else {
				$this->queue->assert_claim( $job->id, $job->claim_token ?? '', true );
			}
			$callbacks = $this->batch_manager?->stage_failure( $job ) ?? array();
			if ( ! $recover ) {
				$this->queue->bury( $job->id, $message, claim_token: $job->claim_token );
			}
			$pdo->commit();
			$this->batch_callbacks = $callbacks;
			return true;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Find and conditionally recover one bounded batch of stale jobs.
	 *
	 * @param int $limit Maximum expired claims considered.
	 * @return int Number of stale jobs recovered.
	 */
	public function recover_stale( int $limit = 100 ): int {
		$stale_jobs = $this->queue->find_stale( Config::stale_timeout(), $limit );
		$count      = 0;

		foreach ( $stale_jobs as $job ) {
			$effective_max    = $job->max_attempts;
			$backoff_strategy = Config::retry_backoff();
			$handler_backoff  = null;
			$runtime_options  = StepDispatchOptions::runtime_from_payload( $job->payload );

			if ( $this->registry->is_job_class( $job->handler ) ) {
				$job_props = $this->read_job_properties( $job->handler );
				if ( null !== $job_props['tries'] ) {
					$effective_max = $job_props['tries'];
				}
				if ( ! empty( $job_props['backoff'] ) ) {
					$handler_backoff = $job_props['backoff'];
				}
			} else {
				$handler_settings = $this->resolve_handler_retry_settings( $job->handler );
				if ( null !== $handler_settings['max_attempts'] ) {
					$effective_max = $handler_settings['max_attempts'];
				}
				if ( is_array( $handler_settings['backoff'] ) ) {
					$handler_backoff = $handler_settings['backoff'];
				} elseif ( is_string( $handler_settings['backoff'] ) ) {
					$backoff_strategy = BackoffStrategy::tryFrom( $handler_settings['backoff'] ) ?? $backoff_strategy;
				}
			}

			if ( isset( $runtime_options['max_attempts'] ) && is_int( $runtime_options['max_attempts'] ) && $runtime_options['max_attempts'] > 0 ) {
				$effective_max = $runtime_options['max_attempts'];
			}

			if ( isset( $runtime_options['backoff'] ) && is_array( $runtime_options['backoff'] ) ) {
				$handler_backoff = $runtime_options['backoff'];
			} elseif ( isset( $runtime_options['backoff'] ) && is_string( $runtime_options['backoff'] ) ) {
				$backoff_strategy = BackoffStrategy::tryFrom( $runtime_options['backoff'] ) ?? $backoff_strategy;
				$handler_backoff  = null;
			}

			try {
				if ( $job->attempts >= $effective_max ) {
					$recovered = $this->fail_terminal( $job, 'Stale: worker died without completing.', recover: true );
				} else {
					if ( is_array( $handler_backoff ) ) {
						$index = max( 0, min( $job->attempts - 1, count( $handler_backoff ) - 1 ) );
						$picked = $handler_backoff[ $index ] ?? 0;
						$backoff = is_scalar( $picked ) ? (int) $picked : 0;
					} else {
						$backoff = Queue::calculate_backoff( $job->attempts, $backoff_strategy );
					}
					$recovered = $this->queue->recover( $job, delay_sec: $backoff, timeout_seconds: Config::stale_timeout() );
				}
			} catch ( LostClaimException $e ) {
				continue;
			}
			if ( ! $recovered ) {
				continue;
			}
			$this->fire_batch_callbacks();
			$this->logger->log(
				$job->attempts >= $effective_max ? LogEvent::Buried : LogEvent::Retried,
				array(
					'job_id' => $job->id,
					'workflow_id' => $job->workflow_id,
					'handler' => $job->handler,
					'queue' => $job->queue,
					'attempt' => $job->attempts,
				)
			);

			++$count;
		}

		return $count;
	}

	/**
	 * Read runtime properties from a Contracts\Job class.
	 *
	 * @param string $handler_class Fully qualified class name.
	 * @return array{
	 *     tries: int|null,
	 *     timeout: int|null,
	 *     max_exceptions: int|null,
	 *     backoff: array<int|string, mixed>|null,
	 *     concurrency_group: string|null,
	 *     concurrency_limit: int|null,
	 *     cost_units: int|null
	 * }
	 */
	private function read_job_properties( string $handler_class ): array {
		return JobMetadata::from_class( $handler_class );
	}

	/**
	 * Call the failed() hook on a job instance if it defines one.
	 *
	 * @param Job              $job          The job record.
	 * @param JobContract|null $job_instance The deserialized job instance, if available.
	 * @param \Throwable       $exception    The exception that caused the failure.
	 */
	private function call_failed_hook( Job $job, ?JobContract $job_instance, \Throwable $exception ): void {
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

		if ( ! $job->is_workflow_step() ) {
			throw new \RuntimeException( 'Streaming steps must run inside a workflow.' );
		}

		$state = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();

		$existing_chunks = array_values( $this->chunk_store->resume_chunks( $job ) );
		$chunk_index     = count( $existing_chunks );

		$generator = $handler->stream( $state, $existing_chunks );

		foreach ( $generator as $chunk ) {
			$content = is_scalar( $chunk ) ? (string) $chunk : '';

			$this->assert_handler_transaction_closed();
			$this->chunk_store->append_chunk(
				job_id: $job->id,
				chunk_index: $chunk_index,
				content: $content,
				workflow_id: $job->workflow_id,
				step_index: $job->step_index,
				claim_token: $job->claim_token,
			);

			++$chunk_index;

			Heartbeat::beat( array( 'streaming_chunks' => $chunk_index ) );
		}

		$all_chunks = array_values( $this->chunk_store->get_chunks( $job->id, claim_token: $job->claim_token ) );
		foreach ( $all_chunks as $index => $chunk ) {
			ExecutionContext::add_trace_chunk(
				array(
					'job_id'      => $job->id,
					'workflow_id' => $job->workflow_id,
					'step_index'  => $job->step_index,
					'chunk_index' => $index,
					'claim_token' => $job->claim_token,
					'content'     => $chunk,
				)
			);
		}

		$output = $handler->on_complete( $all_chunks, $state );

		$duration_ms = (int) ( ( hrtime( true ) - $start_time ) / 1_000_000 );

		$this->assert_handler_transaction_closed();
		$this->workflow->advance_step(
			workflow_id: $job->workflow_id,
			completed_job_id: $job->id,
			step_output: $output,
			duration_ms: $duration_ms,
			job: $job,
		);
		$this->chunk_store->clear_chunks( $job->id, claim_token: $job->claim_token );
	}

	/** Run best-effort external batch callbacks after their counters commit. */
	private function fire_batch_callbacks(): void {
		$callbacks = $this->batch_callbacks;
		$this->batch_callbacks = array();
		if ( null !== $this->batch_manager && ! empty( $callbacks ) ) {
			$this->batch_manager->fire_deferred( $callbacks );
		}
	}

	/**
	 * Register rate limit for a job from serialized runtime metadata or handler config.
	 *
	 * @param Job $job Job record.
	 */
	private function register_job_rate_limit( Job $job ): void {
		if ( null === $this->rate_limiter ) {
			return;
		}

		if ( $this->rate_limiter->is_registered( $job->handler ) ) {
			return;
		}

		$runtime = StepDispatchOptions::runtime_from_payload( $job->payload );
		if ( isset( $runtime['rate_limit'] ) && is_array( $runtime['rate_limit'] ) && count( $runtime['rate_limit'] ) >= 2 ) {
			$rl_max    = $runtime['rate_limit'][0] ?? 0;
			$rl_window = $runtime['rate_limit'][1] ?? 0;
			$this->rate_limiter->register(
				$job->handler,
				is_scalar( $rl_max ) ? (int) $rl_max : 0,
				is_scalar( $rl_window ) ? (int) $rl_window : 0,
			);
			return;
		}

		$this->register_handler_rate_limit( $job->handler );
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

		if ( $this->rate_limiter->is_registered( $handler ) ) {
			return;
		}

		if ( ! $this->registry->has( $handler ) ) {
			return;
		}

		$class = $this->registry->class_name( $handler );
		if ( null === $class ) {
			return;
		}

		$metadata = HandlerMetadata::from_class( $class );
		if ( isset( $metadata['rate_limit'] ) ) {
			$this->rate_limiter->register(
				$handler,
				$metadata['rate_limit'][0],
				$metadata['rate_limit'][1],
			);
		}
	}

	/**
	 * Build trace metadata for a workflow step job.
	 *
	 * @param Job $job Workflow job.
	 * @return array{
	 *     step_name: string|null,
	 *     step_type: string|null,
	 *     input: array<string, mixed>,
	 *     state_before: array<string, mixed>,
	 *     state_after: array<string, mixed>,
	 *     context: array<string, mixed>
	 * }
	 */
	private function workflow_step_trace_info( Job $job ): array {
		$empty = array(
			'step_name'    => null,
			'step_type'    => null,
			'input'        => array(),
			'state_before' => array(),
			'state_after'  => array(),
			'context'      => array(),
		);

		if ( ! $job->is_workflow_step() ) {
			return $empty;
		}
		$state        = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
		$public_state = $this->public_workflow_state( $state );
		$steps_raw    = $state['_steps'] ?? array();
		$steps        = is_array( $steps_raw ) ? $steps_raw : array();
		$step_index   = $job->step_index ?? 0;
		$step_def_raw = $steps[ $step_index ] ?? null;
		$step_def     = self::normalize_step_def( $step_def_raw );
		$step_name    = $this->step_name_from_definition( $step_def, $step_index );
		$step_type    = $this->step_type_from_definition( $step_def );
		$context      = array();
		$input        = $public_state;

		if ( isset( $job->payload['__for_each'] ) && is_array( $job->payload['__for_each'] ) ) {
			$branch_meta     = $job->payload['__for_each'];
			$item_index_raw  = $branch_meta['item_index'] ?? 0;
			$item_index      = is_scalar( $item_index_raw ) ? (int) $item_index_raw : 0;
			$item            = $branch_meta['item'] ?? null;
			$input           = array(
				'state'      => $public_state,
				'item'       => $item,
				'item_index' => $item_index,
			);
			$context = array(
				'item'       => $item,
				'item_index' => $item_index,
			);
		} elseif ( is_array( $step_def ) && isset( $step_def['type'] ) && 'single' !== $step_def['type'] ) {
			$input = array(
				'state'      => $public_state,
				'definition' => $step_def,
			);
		}

		return array(
			'step_name'    => $step_name,
			'step_type'    => $step_type,
			'input'        => $input,
			'state_before' => $public_state,
			'state_after'  => $public_state,
			'context'      => $context,
		);
	}

	/**
	 * Strip internal keys from workflow state for trace exposure.
	 *
	 * @param array<string, mixed> $state Workflow state.
	 * @return array<string, mixed>
	 */
	private function public_workflow_state( array $state ): array {
		return array_filter(
			$state,
			fn( string $key ) => ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Normalize a raw step definition to a typed shape.
	 *
	 * @param mixed $step_def_raw Raw step definition from workflow state.
	 * @return array<string, mixed>|string|null
	 */
	private static function normalize_step_def( mixed $step_def_raw ): array|string|null {
		if ( is_string( $step_def_raw ) ) {
			return $step_def_raw;
		}
		if ( ! is_array( $step_def_raw ) ) {
			return null;
		}
		$normalized = array();
		foreach ( $step_def_raw as $k => $v ) {
			$normalized[ (string) $k ] = $v;
		}
		return $normalized;
	}

	/**
	 * Coerce an arbitrary array into one keyed by strings only.
	 *
	 * @param array<mixed, mixed> $value Raw array.
	 * @return array<string, mixed>
	 */
	private static function to_string_keyed_array( array $value ): array {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ (string) $k ] = $v;
		}

		return $out;
	}

	/**
	 * Resolve a stable step name from a definition.
	 *
	 * @param array<string, mixed>|string|null $step_def   Step definition.
	 * @param int                              $step_index Step index.
	 * @return string
	 */
	private function step_name_from_definition( array|string|null $step_def, int $step_index ): string {
		if ( is_array( $step_def ) && isset( $step_def['name'] ) && is_string( $step_def['name'] ) && '' !== $step_def['name'] ) {
			return $step_def['name'];
		}

		return (string) $step_index;
	}

	/**
	 * Resolve a step type from a definition.
	 *
	 * @param array<string, mixed>|string|null $step_def Step definition.
	 * @return string
	 */
	private function step_type_from_definition( array|string|null $step_def ): string {
		if ( is_array( $step_def ) && isset( $step_def['type'] ) && is_string( $step_def['type'] ) ) {
			return $step_def['type'];
		}

		return 'single';
	}

	/**
	 * Strip internal metadata keys before invoking a classic handler.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>
	 */
	private function public_payload( array $payload ): array {
		unset( $payload['__chain_catch'] );

		return StepDispatchOptions::public_payload( $payload );
	}

	/**
	 * Resolve retry defaults from classic handler or step metadata.
	 *
	 * @param string $handler Handler alias or class.
	 * @return array{max_attempts: int|null, backoff: string|array<int|string, mixed>|null}
	 */
	private function resolve_handler_retry_settings( string $handler ): array {
		$class = $this->registry->class_name( $handler ) ?? ( class_exists( $handler ) ? $handler : null );

		if ( null === $class ) {
			return array(
				'max_attempts' => null,
				'backoff'      => null,
			);
		}

		$metadata = HandlerMetadata::from_class( $class );

		return array(
			'max_attempts' => $metadata['max_attempts'],
			'backoff'      => $metadata['backoff'],
		);
	}

	/**
	 * Whether the job belongs to a for-each workflow step.
	 *
	 * @param Job $job Workflow job.
	 * @return bool
	 */
	private function is_for_each_workflow_job( Job $job ): bool {
		if ( ! $job->is_workflow_step() ) {
			return false;
		}

		$state     = $this->workflow->get_state_uncached( $job->workflow_id ) ?? array();
		$steps_raw = $state['_steps'] ?? array();
		$steps     = is_array( $steps_raw ) ? $steps_raw : array();
		$step      = $steps[ $job->step_index ] ?? null;

		return is_array( $step ) && 'for_each' === ( $step['type'] ?? '' );
	}

	/**
	 * Whether the job is an internal state machine action job.
	 *
	 * @param Job $job Job record.
	 * @return bool
	 */
	private function is_state_machine_action_job( Job $job ): bool {
		return '__queuety_state_machine_action' === $job->handler;
	}

	/**
	 * Extract state machine metadata from an internal action job payload.
	 *
	 * @param Job $job Job record.
	 * @return array{machine_id: int, state_name: string, event_name: string|null, event_payload: array<string, mixed>}|null
	 */
	private function state_machine_action_meta( Job $job ): ?array {
		if ( ! $this->is_state_machine_action_job( $job ) ) {
			return null;
		}

		$machine_id = $job->payload['machine_id'] ?? null;
		$state_name = $job->payload['state_name'] ?? null;
		if ( ! is_numeric( $machine_id ) || ! is_string( $state_name ) || '' === trim( $state_name ) ) {
			return null;
		}

		$event_name = $job->payload['event_name'] ?? null;
		if ( ! is_string( $event_name ) || '' === trim( $event_name ) ) {
			$event_name = null;
		}

		$event_payload_raw = $job->payload['event_payload'] ?? array();
		$event_payload     = is_array( $event_payload_raw ) ? self::to_string_keyed_array( $event_payload_raw ) : array();

		return array(
			'machine_id'    => (int) $machine_id,
			'state_name'    => trim( $state_name ),
			'event_name'    => $event_name,
			'event_payload' => $event_payload,
		);
	}

	/**
	 * Run the chain catch handler after a chain job is permanently buried.
	 *
	 * @param Job        $job       Failed job.
	 * @param \Throwable $exception Failure cause.
	 */
	private function call_chain_catch( Job $job, \Throwable $exception ): void {
		$handler_class = $job->payload['__chain_catch'] ?? null;
		if ( ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
			return;
		}

		try {
			$handler = new $handler_class();

			if ( ! method_exists( $handler, 'handle' ) ) {
				return;
			}

			$method = new \ReflectionMethod( $handler, 'handle' );
			$arity  = $method->getNumberOfParameters();

			if ( 0 === $arity ) {
				$handler->handle();
			} elseif ( 1 === $arity ) {
				$handler->handle( $job );
			} else {
				$handler->handle( $job, $exception );
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Chain catch handlers are best-effort only.
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
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  Payload data.
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
