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
	 * @var array<int, array{started_at: int, restarts: int}>
	 */
	private array $children = array();

	/**
	 * Whether the pool is shutting down.
	 *
	 * @var bool
	 */
	private bool $shutting_down = false;

	/**
	 * Maximum restarts per child within 60 seconds before giving up.
	 *
	 * @var int
	 */
	private const MAX_RESTARTS_PER_MINUTE = 10;

	/**
	 * Constructor.
	 *
	 * @param int    $worker_count Number of worker processes to fork.
	 * @param string $host         Database host.
	 * @param string $dbname       Database name.
	 * @param string $user         Database user.
	 * @param string $password     Database password.
	 * @param string $prefix       Table prefix.
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
	) {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			throw new \RuntimeException( 'pcntl extension is required for --workers=N.' );
		}
		if ( $this->worker_count < 1 || $this->worker_count > 32 ) {
			throw new \RuntimeException( 'Worker count must be between 1 and 32.' );
		}
	}

	/**
	 * Start the worker pool.
	 *
	 * Forks N children and monitors them until SIGTERM/SIGINT.
	 *
	 * @param string $queue Queue name for all workers.
	 */
	public function run( string $queue = 'default' ): void {
		$this->install_parent_signals();

		for ( $i = 0; $i < $this->worker_count; $i++ ) {
			$this->fork_child( $queue );
		}

		$child_count = count( $this->children );
		while ( ! $this->shutting_down || $child_count > 0 ) {
			$child_count = count( $this->children );
			pcntl_signal_dispatch();

			while ( ( $pid = pcntl_waitpid( -1, $status, WNOHANG ) ) > 0 ) {
				$this->handle_child_exit( $pid, $status, $queue );
			}

			// The scheduler must stay single-writer even when job execution is forked.
			static $last_tick = 0;
			if ( ! $this->shutting_down && time() - $last_tick >= 60 ) {
				$this->run_scheduler_tick();
				$last_tick = time();
			}

			usleep( 250_000 );
		}
	}

	/**
	 * Fork a single child worker process.
	 *
	 * @param string $queue Queue name.
	 * @throws \RuntimeException If fork fails.
	 */
	private function fork_child( string $queue ): void {
		$pid = pcntl_fork();

		if ( -1 === $pid ) {
			throw new \RuntimeException( 'Failed to fork worker process.' );
		}

		if ( 0 === $pid ) {
			$this->run_child_worker( $queue );
			exit( 0 );
		}

		$this->children[ $pid ] = array(
			'started_at' => time(),
			'restarts'   => 0,
		);
	}

	/**
	 * Run in the child process. Creates a fresh DB connection and worker.
	 *
	 * @param string $queue Queue name.
	 */
	private function run_child_worker( string $queue ): void {
		// Forked PDO handles are unsafe to reuse across processes.
		$conn              = new Connection( $this->host, $this->dbname, $this->user, $this->password, $this->prefix );
		$cache             = null;

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
		$workflow          = new Workflow( $conn, $queue_op, $logger, $cache, $event_log );
		try {
			$registry = Queuety::registry();
		} catch ( \RuntimeException ) {
			$registry = new HandlerRegistry();
		}

		$rate_limiter      = new RateLimiter( $conn, $cache );
		$scheduler         = new Scheduler( $conn, $queue_op );
		$webhook_notifier  = new WebhookNotifier( $conn );
		$batch_manager     = new BatchManager( $conn );
		$chunk_store       = new ChunkStore( $conn );
		$worker            = new Worker(
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
			'started_at' => 0,
			'restarts'   => 0,
		);
		unset( $this->children[ $pid ] );

		if ( $this->shutting_down ) {
			return;
		}

		$exit_code = pcntl_wifexited( $status ) ? pcntl_wexitstatus( $status ) : -1;

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
		$new_pid = pcntl_fork();

		if ( -1 === $new_pid ) {
			return;
		}

		if ( 0 === $new_pid ) {
			$this->run_child_worker( $queue );
			exit( 0 );
		}

		$this->children[ $new_pid ] = array(
			'started_at' => time(),
			'restarts'   => $uptime < 60 ? $restarts : 0,
		);
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
}
