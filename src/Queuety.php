<?php
/**
 * Public API facade for the Queuety plugin.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\ExpressionType;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;

/**
 * Public API facade. All static methods delegate to internal singleton instances.
 *
 * @example
 * // Simple job
 * Queuety::dispatch('send_email', ['to' => 'user@example.com']);
 *
 * // Workflow
 * Queuety::workflow('generate_report')
 *     ->then(FetchDataHandler::class)
 *     ->then(CallLLMHandler::class)
 *     ->dispatch(['user_id' => 42]);
 */
class Queuety {

	/**
	 * Database connection instance.
	 *
	 * @var Connection|null
	 */
	private static ?Connection $conn = null;

	/**
	 * Queue operations instance.
	 *
	 * @var Queue|null
	 */
	private static ?Queue $queue = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger|null
	 */
	private static ?Logger $logger = null;

	/**
	 * Workflow manager instance.
	 *
	 * @var Workflow|null
	 */
	private static ?Workflow $workflow = null;

	/**
	 * Worker instance.
	 *
	 * @var Worker|null
	 */
	private static ?Worker $worker = null;

	/**
	 * Handler registry instance.
	 *
	 * @var HandlerRegistry|null
	 */
	private static ?HandlerRegistry $registry = null;

	/**
	 * Rate limiter instance.
	 *
	 * @var RateLimiter|null
	 */
	private static ?RateLimiter $rate_limiter = null;

	/**
	 * Scheduler instance.
	 *
	 * @var Scheduler|null
	 */
	private static ?Scheduler $scheduler = null;

	/**
	 * Initialize Queuety with a database connection.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function init( Connection $conn ): void {
		self::$conn         = $conn;
		self::$queue        = new Queue( $conn );
		self::$logger       = new Logger( $conn );
		self::$workflow     = new Workflow( $conn, self::$queue, self::$logger );
		self::$registry     = new HandlerRegistry();
		self::$rate_limiter = new RateLimiter( $conn );
		self::$scheduler    = new Scheduler( $conn, self::$queue );
		self::$worker       = new Worker(
			$conn,
			self::$queue,
			self::$logger,
			self::$workflow,
			self::$registry,
			new Config(),
			self::$rate_limiter,
			self::$scheduler,
		);
	}

	/**
	 * Dispatch a simple job.
	 *
	 * @param string $handler Handler name or class.
	 * @param array  $payload Job payload.
	 * @return PendingJob Fluent builder for additional options.
	 */
	public static function dispatch( string $handler, array $payload = array() ): PendingJob {
		self::ensure_initialized();
		return new PendingJob( $handler, $payload, self::$queue );
	}

	/**
	 * Start building a workflow.
	 *
	 * @param string $name Workflow name.
	 * @return WorkflowBuilder Fluent builder for defining steps.
	 */
	public static function workflow( string $name ): WorkflowBuilder {
		self::ensure_initialized();
		return new WorkflowBuilder( $name, self::$conn, self::$queue, self::$logger );
	}

	/**
	 * Register a handler class under a name.
	 *
	 * @param string $name  Handler name.
	 * @param string $class Fully qualified class name.
	 */
	public static function register( string $name, string $class ): void {
		self::ensure_initialized();
		self::$registry->register( $name, $class );
	}

	/**
	 * Get job counts grouped by status.
	 *
	 * @param string|null $queue Optional queue filter.
	 * @return array
	 */
	public static function stats( ?string $queue = null ): array {
		self::ensure_initialized();
		return self::$queue->stats( $queue );
	}

	/**
	 * Get all buried jobs.
	 *
	 * @param string|null $queue Optional queue filter.
	 * @return Job[]
	 */
	public static function buried( ?string $queue = null ): array {
		self::ensure_initialized();
		return self::$queue->buried( $queue );
	}

	/**
	 * Retry all buried jobs.
	 *
	 * @return int Number of jobs retried.
	 */
	public static function retry_buried(): int {
		self::ensure_initialized();
		$buried = self::$queue->buried();
		$count  = 0;
		foreach ( $buried as $job ) {
			self::$queue->retry( $job->id );
			++$count;
		}
		return $count;
	}

	/**
	 * Retry a specific job.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function retry( int $job_id ): void {
		self::ensure_initialized();
		self::$queue->retry( $job_id );
	}

	/**
	 * Purge completed jobs.
	 *
	 * @param int|null $older_than_days Days threshold (defaults to config).
	 * @return int Number of jobs purged.
	 */
	public static function purge( ?int $older_than_days = null ): int {
		self::ensure_initialized();
		$days = $older_than_days ?? Config::retention_days();
		return self::$queue->purge_completed( $days );
	}

	/**
	 * Get workflow status.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return WorkflowState|null
	 */
	public static function workflow_status( int $workflow_id ): ?WorkflowState {
		self::ensure_initialized();
		return self::$workflow->status( $workflow_id );
	}

	/**
	 * Retry a failed workflow from its failed step.
	 *
	 * @param int $workflow_id Workflow ID.
	 */
	public static function retry_workflow( int $workflow_id ): void {
		self::ensure_initialized();
		self::$workflow->retry( $workflow_id );
	}

	/**
	 * Pause a running workflow.
	 *
	 * @param int $workflow_id Workflow ID.
	 */
	public static function pause_workflow( int $workflow_id ): void {
		self::ensure_initialized();
		self::$workflow->pause( $workflow_id );
	}

	/**
	 * Resume a paused workflow.
	 *
	 * @param int $workflow_id Workflow ID.
	 */
	public static function resume_workflow( int $workflow_id ): void {
		self::ensure_initialized();
		self::$workflow->resume( $workflow_id );
	}

	/**
	 * Get the internal Queue instance.
	 *
	 * @return Queue
	 */
	public static function queue(): Queue {
		self::ensure_initialized();
		return self::$queue;
	}

	/**
	 * Get the internal Logger instance.
	 *
	 * @return Logger
	 */
	public static function logger(): Logger {
		self::ensure_initialized();
		return self::$logger;
	}

	/**
	 * Get the internal Worker instance.
	 *
	 * @return Worker
	 */
	public static function worker(): Worker {
		self::ensure_initialized();
		return self::$worker;
	}

	/**
	 * Get the internal Workflow instance.
	 *
	 * @return Workflow
	 */
	public static function workflow_manager(): Workflow {
		self::ensure_initialized();
		return self::$workflow;
	}

	/**
	 * Get the handler registry.
	 *
	 * @return HandlerRegistry
	 */
	public static function registry(): HandlerRegistry {
		self::ensure_initialized();
		return self::$registry;
	}

	/**
	 * Get the rate limiter instance.
	 *
	 * @return RateLimiter
	 */
	public static function rate_limiter(): RateLimiter {
		self::ensure_initialized();
		return self::$rate_limiter;
	}

	/**
	 * Create a new recurring schedule.
	 *
	 * @param string $handler Handler name or class.
	 * @param array  $payload Job payload.
	 * @return PendingSchedule Fluent builder for schedule options.
	 */
	public static function schedule( string $handler, array $payload = array() ): PendingSchedule {
		self::ensure_initialized();
		return new PendingSchedule( $handler, $payload, self::$scheduler );
	}

	/**
	 * Get the internal Scheduler instance.
	 *
	 * @return Scheduler
	 */
	public static function scheduler(): Scheduler {
		self::ensure_initialized();
		return self::$scheduler;
	}

	/**
	 * Reset the singleton state (for testing).
	 */
	public static function reset(): void {
		self::$conn         = null;
		self::$queue        = null;
		self::$logger       = null;
		self::$workflow     = null;
		self::$worker       = null;
		self::$registry     = null;
		self::$rate_limiter = null;
		self::$scheduler    = null;
	}

	/**
	 * Ensure the facade has been initialized.
	 *
	 * @throws \RuntimeException If init() has not been called.
	 */
	private static function ensure_initialized(): void {
		if ( null === self::$conn ) {
			throw new \RuntimeException( 'Queuety not initialized. Call Queuety::init() first.' );
		}
	}
}
