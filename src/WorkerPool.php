<?php
/**
 * Multi-process worker pool.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Cache\CacheFactory;

/**
 * Forks multiple worker processes and monitors them.
 *
 * Uses pcntl_fork() to spawn N child workers. The parent process
 * monitors children, restarts crashed ones, and handles graceful
 * shutdown on SIGTERM/SIGINT.
 */
class WorkerPool {

	/**
	 * Child PID to metadata mapping.
	 *
	 * @var array<int, array{started_at: int, restarts: int, intentional_stop: bool}>
	 */
	private array $children = array();

	/**
	 * Whether the pool is shutting down.
	 *
	 * @var bool
	 */
	private bool $shutting_down = false;

	/**
	 * Time of the last observed non-empty backlog.
	 *
	 * @var int|null
	 */
	private ?int $last_nonempty_backlog_at = null;

	/**
	 * Minimum worker count.
	 *
	 * @var int
	 */
	private int $min_worker_count;

	/**
	 * Maximum worker count.
	 *
	 * @var int
	 */
	private int $max_worker_count;

	/**
	 * Maximum restarts per child within 60 seconds before giving up.
	 *
	 * @var int
	 */
	private const MAX_RESTARTS_PER_MINUTE = 10;

	/**
	 * Constructor.
	 *
	 * @param int      $worker_count Minimum worker count, or the fixed count when adaptive scaling is disabled.
	 * @param string   $host         Database host.
	 * @param string   $dbname       Database name.
	 * @param string   $user         Database user.
	 * @param string   $password     Database password.
	 * @param string   $prefix       Table prefix.
	 * @param int|null $max_worker_count Optional adaptive upper bound.
	 *
	 * @throws \RuntimeException If pcntl is not available or worker_count is invalid.
	 */
	public function __construct(
		private readonly int $worker_count,
		private readonly string $host,
		private readonly string $dbname,
		private readonly string $user,
		private readonly string $password,
		private readonly string $prefix,
		?int $max_worker_count = null,
	) {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			throw new \RuntimeException( 'pcntl extension is required for --workers=N.' );
		}

		$this->min_worker_count = $this->worker_count;
		$this->max_worker_count = $max_worker_count ?? $this->worker_count;

		if ( $this->min_worker_count < 1 || $this->min_worker_count > 32 ) {
			throw new \RuntimeException( 'Worker count must be between 1 and 32.' );
		}

		if ( $this->max_worker_count < $this->min_worker_count || $this->max_worker_count > 32 ) {
			throw new \RuntimeException( 'Maximum worker count must be between the minimum and 32.' );
		}
	}

	/**
	 * Start the worker pool.
	 *
	 * Forks the minimum child set and monitors them until SIGTERM/SIGINT.
	 *
	 * @param string $queue Queue name for all workers.
	 */
	public function run( string $queue = 'default' ): void {
		$this->install_parent_signals();

		for ( $i = 0; $i < $this->min_worker_count; $i++ ) {
			$this->fork_child( $queue );
		}

		$last_scheduler_tick = 0;
		$last_scale_tick     = 0;
		$child_count         = count( $this->children );
		while ( ! $this->shutting_down || $child_count > 0 ) {
			$child_count = count( $this->children );
			pcntl_signal_dispatch();

			while ( true ) {
				$pid = pcntl_waitpid( -1, $status, WNOHANG );
				if ( $pid <= 0 ) {
					break;
				}

				$this->handle_child_exit( $pid, $status, $queue );
			}

			// The scheduler must stay single-writer even when job execution is forked.
			if ( ! $this->shutting_down && time() - $last_scheduler_tick >= 60 ) {
				$this->run_scheduler_tick();
				$last_scheduler_tick = time();
			}

			if ( ! $this->shutting_down && $this->is_adaptive() && time() - $last_scale_tick >= Config::worker_pool_scale_interval_seconds() ) {
				$this->reconcile_pool_size( $queue );
				$last_scale_tick = time();
			}

			usleep( 250_000 );
		}
	}

	/**
	 * Fork a single child worker process.
	 *
	 * @param string $queue    Queue name.
	 * @param int    $restarts Restart count carried into the child metadata.
	 * @throws \RuntimeException If fork fails.
	 */
	private function fork_child( string $queue, int $restarts = 0 ): void {
		$pid = pcntl_fork();

		if ( -1 === $pid ) {
			throw new \RuntimeException( 'Failed to fork worker process.' );
		}

		if ( 0 === $pid ) {
			$this->run_child_worker( $queue );
			exit( 0 );
		}

		$this->children[ $pid ] = array(
			'started_at'       => time(),
			'restarts'         => $restarts,
			'intentional_stop' => false,
		);
	}

	/**
	 * Run in the child process. Creates a fresh DB connection and worker.
	 *
	 * @param string $queue Queue name.
	 */
	private function run_child_worker( string $queue ): void {
		// Forked PDO handles are unsafe to reuse across processes.
		$conn  = new Connection( $this->host, $this->dbname, $this->user, $this->password, $this->prefix );
		$cache = null;

		if ( null === Queuety::queue_fake() ) {
			try {
				$cache = Queuety::cache();
			} catch ( \RuntimeException ) {
				$cache = CacheFactory::create();
			}
		}

		$queue_op          = new Queue( $conn, $cache );
		$logger            = new Logger( $conn );
		$event_log         = new WorkflowEventLog( $conn );
		$artifact_store    = new ArtifactStore( $conn );
		$machine_event_log = new StateMachineEventLog( $conn );
		$workflow          = new Workflow( $conn, $queue_op, $logger, $cache, $event_log, $artifact_store );
		$state_machines    = new StateMachine( $conn, $queue_op, $machine_event_log );
		try {
			$registry = Queuety::registry();
		} catch ( \RuntimeException ) {
			$registry = new HandlerRegistry();
		}

		$rate_limiter     = new RateLimiter( $conn, $cache );
		$resource_manager = new ResourceManager( $conn, $cache );
		$scheduler        = new Scheduler( $conn, $queue_op );
		$webhook_notifier = new WebhookNotifier( $conn );
		$batch_manager    = new BatchManager( $conn );
		$chunk_store      = new ChunkStore( $conn );
		$worker           = new Worker(
			$conn,
			$queue_op,
			$logger,
			$workflow,
			$registry,
			new Config(),
			$rate_limiter,
			$scheduler,
			$webhook_notifier,
			$batch_manager,
			$chunk_store,
			$event_log,
			$state_machines,
			$resource_manager,
		);

		pcntl_signal(
			SIGTERM,
			function () use ( $worker ) {
				$worker->stop();
			}
		);
		pcntl_signal(
			SIGINT,
			function () use ( $worker ) {
				$worker->stop();
			}
		);

		$worker->run( $queue );
	}

	/**
	 * Handle a child process exit.
	 *
	 * @param int    $pid    Exited child PID.
	 * @param int    $status Exit status.
	 * @param string $queue  Queue name for respawning.
	 */
	private function handle_child_exit( int $pid, int $status, string $queue ): void {
		$meta = $this->children[ $pid ] ?? array(
			'started_at'       => 0,
			'restarts'         => 0,
			'intentional_stop' => false,
		);
		unset( $this->children[ $pid ] );

		if ( $this->shutting_down || $meta['intentional_stop'] ) {
			return;
		}

		$exit_code = pcntl_wifexited( $status ) ? pcntl_wexitstatus( $status ) : -1;
		$target    = $this->desired_worker_count( $queue, count( $this->children ) );

		if ( count( $this->children ) >= $target ) {
			return;
		}

		if ( 0 === $exit_code ) {
			$this->fork_child( $queue );
			return;
		}

		$restarts = $meta['restarts'] + 1;
		$uptime   = time() - $meta['started_at'];

		if ( $uptime < 60 && $restarts >= self::MAX_RESTARTS_PER_MINUTE ) {
			return;
		}

		sleep( 1 );
		$this->fork_child( $queue, $uptime < 60 ? $restarts : 0 );
	}

	/**
	 * Install signal handlers for the parent process.
	 */
	private function install_parent_signals(): void {
		$handler = function () {
			$this->shutting_down = true;
			$this->signal_children( SIGTERM );
		};

		pcntl_signal( SIGTERM, $handler );
		pcntl_signal( SIGINT, $handler );
	}

	/**
	 * Send a signal to all child processes.
	 *
	 * @param int $signal Signal number.
	 */
	private function signal_children( int $signal ): void {
		foreach ( array_keys( $this->children ) as $pid ) {
			posix_kill( $pid, $signal );
		}
	}

	/**
	 * Run a scheduler tick in the parent process context.
	 */
	private function run_scheduler_tick(): void {
		try {
			$scheduler = Queuety::scheduler();
			$scheduler->tick();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Scheduler may not be initialized.
			unset( $e );
		}
	}

	/**
	 * Reconcile the current child count with the desired target.
	 *
	 * @param string $queue Queue name or ordered queue list.
	 */
	private function reconcile_pool_size( string $queue ): void {
		$current = count( $this->children );
		$target  = $this->desired_worker_count( $queue, $current );

		if ( $target > $current ) {
			for ( $i = $current; $i < $target; $i++ ) {
				$this->fork_child( $queue );
			}
			return;
		}

		if ( $target < $current ) {
			$excess = $current - $target;
			foreach ( array_keys( $this->children ) as $pid ) {
				if ( $excess <= 0 ) {
					break;
				}

				$this->children[ $pid ]['intentional_stop'] = true;
				posix_kill( $pid, SIGTERM );
				--$excess;
			}
		}
	}

	/**
	 * Decide how many worker processes should currently run.
	 *
	 * @param string $queue           Queue name or ordered queue list.
	 * @param int    $current_workers Current worker count.
	 * @return int
	 */
	private function desired_worker_count( string $queue, int $current_workers ): int {
		if ( ! $this->is_adaptive() ) {
			return $this->min_worker_count;
		}

		try {
			$conn             = new Connection( $this->host, $this->dbname, $this->user, $this->password, $this->prefix );
			$queue_op         = new Queue( $conn );
			$resource_manager = new ResourceManager( $conn );
			$queues           = $this->active_queue_names( $queue, $queue_op );
			$backlog          = empty( $queues ) ? 0 : $queue_op->available_pending_count( $queues );
			$can_scale_up     = $this->can_scale_up_for_capacity( $resource_manager );
			$can_scale_down   = $this->can_scale_down( $backlog );

			return WorkerPoolScalePolicy::target_worker_count(
				$backlog,
				$current_workers,
				$this->min_worker_count,
				$this->max_worker_count,
				$can_scale_up,
				$can_scale_down
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Keep the current pool size when sizing signals fail.
			unset( $e );
			return max( $current_workers, $this->min_worker_count );
		}
	}

	/**
	 * Determine whether the adaptive pool may scale up right now.
	 *
	 * @param ResourceManager $resource_manager Resource manager.
	 * @return bool
	 */
	private function can_scale_up_for_capacity( ResourceManager $resource_manager ): bool {
		if ( ! Config::resource_system_memory_awareness_enabled() ) {
			return true;
		}

		$snapshot = $resource_manager->system_memory_snapshot();
		if ( null === $snapshot ) {
			return true;
		}

		$available_kb = (int) ( $snapshot['available_kb'] ?? 0 );
		if ( $available_kb < 1 ) {
			return false;
		}

		$required_kb = ( Config::worker_max_memory() + Config::resource_system_memory_headroom_mb() ) * 1024;

		return $available_kb >= $required_kb;
	}

	/**
	 * Decide whether the pool may scale back down yet.
	 *
	 * @param int $backlog Claimable pending jobs.
	 * @return bool
	 */
	private function can_scale_down( int $backlog ): bool {
		if ( $backlog > 0 ) {
			$this->last_nonempty_backlog_at = time();
			return true;
		}

		if ( null === $this->last_nonempty_backlog_at ) {
			return true;
		}

		return time() - $this->last_nonempty_backlog_at >= Config::worker_pool_idle_grace_seconds();
	}

	/**
	 * Filter paused queues out of one ordered queue list.
	 *
	 * @param string $queue    Queue name or ordered queue list.
	 * @param Queue  $queue_op Queue operations.
	 * @return array<int, string>
	 */
	private function active_queue_names( string $queue, Queue $queue_op ): array {
		$queues = $this->parse_queue_names( $queue );

		return array_values(
			array_filter(
				$queues,
				static fn ( string $queue_name ): bool => '' !== $queue_name && ! $queue_op->is_queue_paused( $queue_name )
			)
		);
	}

	/**
	 * Parse one queue name parameter into an ordered list.
	 *
	 * @param string $queue Queue name or comma-separated list.
	 * @return array<int, string>
	 */
	private function parse_queue_names( string $queue ): array {
		if ( str_contains( $queue, ',' ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $queue ) ) ) );
		}

		return array( trim( $queue ) );
	}

	/**
	 * Whether this pool may change its size after boot.
	 *
	 * @return bool
	 */
	private function is_adaptive(): bool {
		return $this->max_worker_count > $this->min_worker_count;
	}
}
