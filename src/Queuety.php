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
	 * Workflow template registry instance.
	 *
	 * @var WorkflowRegistry|null
	 */
	private static ?WorkflowRegistry $workflow_registry = null;

	/**
	 * Metrics instance.
	 *
	 * @var Metrics|null
	 */
	private static ?Metrics $metrics = null;

	/**
	 * Webhook notifier instance.
	 *
	 * @var WebhookNotifier|null
	 */
	private static ?WebhookNotifier $webhook_notifier = null;

	/**
	 * Initialize Queuety with a database connection.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function init( Connection $conn ): void {
		self::$conn              = $conn;
		self::$queue             = new Queue( $conn );
		self::$logger            = new Logger( $conn );
		self::$workflow          = new Workflow( $conn, self::$queue, self::$logger );
		self::$registry          = new HandlerRegistry();
		self::$rate_limiter      = new RateLimiter( $conn );
		self::$scheduler         = new Scheduler( $conn, self::$queue );
		self::$workflow_registry = new WorkflowRegistry();
		self::$metrics           = new Metrics( $conn );
		self::$webhook_notifier  = new WebhookNotifier( $conn );
		self::$worker            = new Worker(
			$conn,
			self::$queue,
			self::$logger,
			self::$workflow,
			self::$registry,
			new Config(),
			self::$rate_limiter,
			self::$scheduler,
			self::$webhook_notifier,
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
	 * Dispatch multiple jobs in a single multi-row INSERT.
	 *
	 * Each item in $jobs is an associative array with keys:
	 * handler, payload, queue, priority, delay, max_attempts.
	 *
	 * @param array $jobs Array of job definitions.
	 * @return int[] Array of new job IDs.
	 */
	public static function batch( array $jobs ): array {
		self::ensure_initialized();
		return self::$queue->batch( $jobs );
	}

	/**
	 * Pause a queue so workers skip it.
	 *
	 * @param string $queue Queue name.
	 */
	public static function pause( string $queue ): void {
		self::ensure_initialized();
		self::$queue->pause_queue( $queue );
	}

	/**
	 * Resume a paused queue.
	 *
	 * @param string $queue Queue name.
	 */
	public static function resume( string $queue ): void {
		self::ensure_initialized();
		self::$queue->resume_queue( $queue );
	}

	/**
	 * Check if a queue is paused.
	 *
	 * @param string $queue Queue name.
	 * @return bool
	 */
	public static function is_paused( string $queue ): bool {
		self::ensure_initialized();
		return self::$queue->is_queue_paused( $queue );
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
	 * Get the internal Connection instance.
	 *
	 * @return Connection
	 */
	public static function connection(): Connection {
		self::ensure_initialized();
		return self::$conn;
	}

	/**
	 * Define a named workflow template. Returns a builder whose steps will be
	 * registered as a template when build_and_register() is called.
	 *
	 * @param string $name Template name.
	 * @return WorkflowBuilder Builder for defining the template steps.
	 */
	public static function define_workflow( string $name ): WorkflowBuilder {
		self::ensure_initialized();
		return new WorkflowBuilder( $name, self::$conn, self::$queue, self::$logger );
	}

	/**
	 * Register a workflow template from a builder.
	 *
	 * @param WorkflowBuilder $builder The builder with defined steps.
	 */
	public static function register_workflow_template( WorkflowBuilder $builder ): void {
		self::ensure_initialized();

		$template = new WorkflowTemplate(
			name: $builder->get_name(),
			steps: $builder->build_steps(),
			queue: $builder->get_queue(),
			priority: $builder->get_priority(),
			max_attempts: $builder->get_max_attempts(),
		);

		self::$workflow_registry->register( $builder->get_name(), $template );
	}

	/**
	 * Dispatch a registered workflow template by name.
	 *
	 * @param string $name    Template name.
	 * @param array  $payload Initial payload/state.
	 * @return int The workflow ID.
	 * @throws \RuntimeException If the template is not registered.
	 */
	public static function run_workflow( string $name, array $payload = array() ): int {
		self::ensure_initialized();

		$template = self::$workflow_registry->get( $name );
		if ( null === $template ) {
			throw new \RuntimeException( "Workflow template '{$name}' is not registered." );
		}

		return $template->dispatch( $payload );
	}

	/**
	 * Get the workflow template registry.
	 *
	 * @return WorkflowRegistry
	 */
	public static function workflow_templates(): WorkflowRegistry {
		self::ensure_initialized();
		return self::$workflow_registry;
	}

	/**
	 * Get the Metrics instance.
	 *
	 * @return Metrics
	 */
	public static function metrics(): Metrics {
		self::ensure_initialized();
		return self::$metrics;
	}

	/**
	 * Get the WebhookNotifier instance.
	 *
	 * @return WebhookNotifier
	 */
	public static function webhook_notifier(): WebhookNotifier {
		self::ensure_initialized();
		return self::$webhook_notifier;
	}

	/**
	 * Auto-discover and register handler classes from a directory.
	 *
	 * @param string $directory Absolute path to the directory to scan.
	 * @param string $namespace PSR-4 namespace prefix for the directory.
	 * @return int Number of handlers registered.
	 * @throws \RuntimeException If the directory does not exist.
	 */
	public static function discover_handlers( string $directory, string $namespace ): int {
		self::ensure_initialized();
		$discovery = new HandlerDiscovery();
		return $discovery->register_all( $directory, $namespace, self::$registry );
	}

	/**
	 * Reset the singleton state (for testing).
	 */
	public static function reset(): void {
		self::$conn              = null;
		self::$queue             = null;
		self::$logger            = null;
		self::$workflow          = null;
		self::$worker            = null;
		self::$registry          = null;
		self::$rate_limiter      = null;
		self::$scheduler         = null;
		self::$workflow_registry = null;
		self::$metrics           = null;
		self::$webhook_notifier  = null;
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
