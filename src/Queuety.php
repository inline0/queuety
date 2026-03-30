<?php
/**
 * Public API facade for the Queuety plugin.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Cache\CacheFactory;
use Queuety\Contracts\Cache;
use Queuety\Contracts\Job as JobContract;
use Queuety\Enums\ExpressionType;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\Testing\FakeBatchManager;
use Queuety\Testing\FakeQueue;
use Queuety\Testing\QueueFake;

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
	 * Batch manager instance.
	 *
	 * @var BatchManager|null
	 */
	private static ?BatchManager $batch_manager = null;

	/**
	 * Chunk store instance for streaming steps.
	 *
	 * @var ChunkStore|null
	 */
	private static ?ChunkStore $chunk_store = null;

	/**
	 * Workflow event log instance.
	 *
	 * @var WorkflowEventLog|null
	 */
	private static ?WorkflowEventLog $workflow_event_log = null;

	/**
	 * Workflow artifact storage instance.
	 *
	 * @var ArtifactStore|null
	 */
	private static ?ArtifactStore $artifact_store = null;

	/**
	 * Cache backend instance.
	 *
	 * @var Cache|null
	 */
	private static ?Cache $cache = null;

	/**
	 * Queue fake for testing.
	 *
	 * @var QueueFake|null
	 */
	private static ?QueueFake $queue_fake = null;

	/**
	 * Queue driver used while the facade is faked.
	 *
	 * @var FakeQueue|null
	 */
	private static ?FakeQueue $fake_queue = null;

	/**
	 * Batch manager used while the facade is faked.
	 *
	 * @var FakeBatchManager|null
	 */
	private static ?FakeBatchManager $fake_batch_manager = null;

	/**
	 * Initialize Queuety with a database connection.
	 *
	 * @param Connection $conn Database connection.
	 */
	public static function init( Connection $conn ): void {
		self::$conn = $conn;

		if ( null === self::$cache ) {
			self::$cache = CacheFactory::create();
		}

		self::$queue              = new Queue( $conn, self::$cache );
		self::$logger             = new Logger( $conn );
		self::$workflow_event_log = new WorkflowEventLog( $conn );
		self::$artifact_store     = new ArtifactStore( $conn );
		self::$workflow           = new Workflow( $conn, self::$queue, self::$logger, self::$cache, self::$workflow_event_log, self::$artifact_store );
		self::$registry           = new HandlerRegistry();
		self::$rate_limiter       = new RateLimiter( $conn, self::$cache );
		self::$scheduler          = new Scheduler( $conn, self::$queue );
		self::$workflow_registry  = new WorkflowRegistry();
		self::$metrics            = new Metrics( $conn );
		self::$webhook_notifier   = new WebhookNotifier( $conn );
		self::$batch_manager      = new BatchManager( $conn );
		self::$chunk_store        = new ChunkStore( $conn );
		self::$worker             = new Worker(
			$conn,
			self::$queue,
			self::$logger,
			self::$workflow,
			self::$registry,
			new Config(),
			self::$rate_limiter,
			self::$scheduler,
			self::$webhook_notifier,
			self::$batch_manager,
			self::$chunk_store,
			self::$workflow_event_log,
		);
	}

	/**
	 * Dispatch a simple job or a dispatchable Job instance.
	 *
	 * When $handler is a Contracts\Job instance, the serializer extracts the
	 * FQCN and public properties as the handler name and payload respectively.
	 * The original instance is stored on the PendingJob for middleware extraction.
	 *
	 * @param string|JobContract $handler Handler name, class, or Job instance.
	 * @param array              $payload Job payload (ignored when $handler is a Job instance).
	 * @return PendingJob Fluent builder for additional options.
	 */
	public static function dispatch( string|JobContract $handler, array $payload = array() ): PendingJob {
		if ( null !== self::$queue_fake ) {
			if ( $handler instanceof JobContract ) {
				$serialized = JobSerializer::serialize( $handler );
				$pending    = new PendingJob( $serialized['handler'], $serialized['payload'], self::fake_queue(), $handler );
			} else {
				$pending = new PendingJob( $handler, $payload, self::fake_queue() );
			}

			return self::apply_pending_defaults( $pending, $handler );
		}

		self::ensure_initialized();

		if ( $handler instanceof JobContract ) {
			$serialized = JobSerializer::serialize( $handler );
			$pending    = new PendingJob( $serialized['handler'], $serialized['payload'], self::$queue, $handler );
			return self::apply_pending_defaults( $pending, $handler );
		}

		return self::apply_pending_defaults( new PendingJob( $handler, $payload, self::$queue ), $handler );
	}

	/**
	 * Execute a job synchronously without dispatching to the queue.
	 *
	 * @param string|JobContract $handler Handler name/class or Job instance.
	 * @param array              $payload Job payload (ignored when $handler is a Job instance).
	 */
	public static function dispatch_sync( string|JobContract $handler, array $payload = array() ): void {
		if ( $handler instanceof JobContract ) {
			$handler->handle();
			return;
		}

		if ( class_exists( $handler ) ) {
			$reflection = new \ReflectionClass( $handler );
			if ( $reflection->implementsInterface( JobContract::class ) ) {
				$instance = JobSerializer::deserialize( $handler, $payload );
				$instance->handle();
				return;
			}
		}

		self::ensure_initialized();
		$resolved = self::$registry->resolve( $handler );
		if ( $resolved instanceof JobContract ) {
			$resolved->handle();
		} elseif ( $resolved instanceof Handler ) {
			$resolved->handle( $payload );
		}
	}

	/**
	 * Create a batch builder for dispatching a group of jobs with callbacks.
	 *
	 * @param array $jobs Array of Contracts\Job instances or handler+payload arrays.
	 * @return BatchBuilder Fluent builder for batch options.
	 */
	public static function create_batch( array $jobs ): BatchBuilder {
		if ( null !== self::$queue_fake ) {
			return new BatchBuilder( $jobs, self::fake_queue(), self::fake_batch_manager() );
		}

		self::ensure_initialized();
		return new BatchBuilder( $jobs, self::$queue, self::$batch_manager );
	}

	/**
	 * Find a batch by ID.
	 *
	 * @param int $id Batch ID.
	 * @return Batch|null
	 */
	public static function find_batch( int $id ): ?Batch {
		if ( null !== self::$queue_fake ) {
			return self::fake_batch_manager()->find( $id );
		}

		self::ensure_initialized();
		return self::$batch_manager->find( $id );
	}

	/**
	 * Create a chain builder for sequential job execution.
	 *
	 * @param array $jobs Array of Contracts\Job instances.
	 * @return ChainBuilder Fluent builder for chain options.
	 */
	public static function chain( array $jobs ): ChainBuilder {
		if ( null !== self::$queue_fake ) {
			return new ChainBuilder( $jobs, self::fake_queue() );
		}

		self::ensure_initialized();
		return new ChainBuilder( $jobs, self::$queue );
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
		if ( null !== self::$queue_fake ) {
			return self::fake_queue()->batch( $jobs );
		}

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
	 * Send a signal to a workflow.
	 *
	 * If the workflow is currently waiting for this signal, it resumes
	 * immediately. Otherwise, the signal is stored and will be picked up
	 * when the workflow reaches the corresponding wait_for_signal step.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param string $name        The signal name.
	 * @param array  $data        Optional payload data to merge into workflow state.
	 */
	public static function signal( int $workflow_id, string $name, array $data = array() ): void {
		self::ensure_initialized();
		self::$workflow->handle_signal( $workflow_id, $name, $data );
	}

	/**
	 * Send an approval signal to a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param array  $data        Optional approval payload.
	 * @param string $signal_name Approval signal name.
	 */
	public static function approve_workflow( int $workflow_id, array $data = array(), string $signal_name = 'approval' ): void {
		self::signal( $workflow_id, $signal_name, $data );
	}

	/**
	 * Send a rejection signal to a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param array  $data        Optional rejection payload.
	 * @param string $signal_name Rejection signal name.
	 */
	public static function reject_workflow( int $workflow_id, array $data = array(), string $signal_name = 'rejected' ): void {
		self::signal( $workflow_id, $signal_name, $data );
	}

	/**
	 * Send structured human input to a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param array  $data        Input payload.
	 * @param string $signal_name Input signal name.
	 */
	public static function submit_workflow_input( int $workflow_id, array $data = array(), string $signal_name = 'input' ): void {
		self::signal( $workflow_id, $signal_name, $data );
	}

	/**
	 * Store one artifact for a workflow.
	 *
	 * @param int      $workflow_id  Workflow ID.
	 * @param string   $artifact_key Artifact key.
	 * @param mixed    $content      Artifact content.
	 * @param string   $kind         Artifact kind.
	 * @param int|null $step_index   Related workflow step index, if any.
	 * @param array    $metadata     Optional metadata.
	 * @throws \InvalidArgumentException If the artifact key or workflow ID is invalid.
	 */
	public static function put_artifact(
		int $workflow_id,
		string $artifact_key,
		mixed $content,
		string $kind = 'json',
		?int $step_index = null,
		array $metadata = array(),
	): void {
		self::ensure_initialized();
		self::$artifact_store->put( $workflow_id, $artifact_key, $content, $kind, $step_index, $metadata );
	}

	/**
	 * Store one artifact for the currently executing workflow step.
	 *
	 * @param string $artifact_key Artifact key.
	 * @param mixed  $content      Artifact content.
	 * @param string $kind         Artifact kind.
	 * @param array  $metadata     Optional metadata.
	 * @throws \RuntimeException If no workflow step is currently executing.
	 */
	public static function put_current_artifact(
		string $artifact_key,
		mixed $content,
		string $kind = 'json',
		array $metadata = array(),
	): void {
		$workflow_id = ExecutionContext::workflow_id();
		if ( null === $workflow_id ) {
			throw new \RuntimeException( 'No workflow is currently executing.' );
		}

		self::put_artifact(
			$workflow_id,
			$artifact_key,
			$content,
			$kind,
			ExecutionContext::step_index(),
			$metadata,
		);
	}

	/**
	 * Get one stored artifact for a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param string $artifact_key Artifact key.
	 * @return array<string,mixed>|null
	 */
	public static function workflow_artifact( int $workflow_id, string $artifact_key ): ?array {
		self::ensure_initialized();
		return self::$artifact_store->get( $workflow_id, $artifact_key );
	}

	/**
	 * List stored artifacts for a workflow.
	 *
	 * @param int  $workflow_id     Workflow ID.
	 * @param bool $include_content Whether to include artifact content.
	 * @return array<int,array<string,mixed>>
	 */
	public static function workflow_artifacts( int $workflow_id, bool $include_content = false ): array {
		self::ensure_initialized();
		return self::$artifact_store->list( $workflow_id, $include_content );
	}

	/**
	 * Delete one stored artifact for a workflow.
	 *
	 * @param int    $workflow_id Workflow ID.
	 * @param string $artifact_key Artifact key.
	 */
	public static function delete_workflow_artifact( int $workflow_id, string $artifact_key ): void {
		self::ensure_initialized();
		self::$artifact_store->delete( $workflow_id, $artifact_key );
	}

	/**
	 * Get the currently executing workflow ID, if any.
	 *
	 * @return int|null
	 */
	public static function current_workflow_id(): ?int {
		return ExecutionContext::workflow_id();
	}

	/**
	 * Get the currently executing workflow step index, if any.
	 *
	 * @return int|null
	 */
	public static function current_step_index(): ?int {
		return ExecutionContext::step_index();
	}

	/**
	 * Cancel a workflow and run any cleanup handlers.
	 *
	 * @param int $workflow_id Workflow ID.
	 */
	public static function cancel_workflow( int $workflow_id ): void {
		self::ensure_initialized();
		self::$workflow->cancel( $workflow_id );
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
	 * Get the batch manager instance.
	 *
	 * @return BatchManager
	 */
	public static function batch_manager(): BatchManager {
		if ( null !== self::$queue_fake ) {
			return self::fake_batch_manager();
		}

		self::ensure_initialized();
		return self::$batch_manager;
	}

	/**
	 * Get the chunk store instance.
	 *
	 * @return ChunkStore
	 */
	public static function chunk_store(): ChunkStore {
		self::ensure_initialized();
		return self::$chunk_store;
	}

	/**
	 * Get the workflow artifact store instance.
	 *
	 * @return ArtifactStore
	 */
	public static function artifacts(): ArtifactStore {
		self::ensure_initialized();
		return self::$artifact_store;
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
	 * Get the cache backend instance.
	 *
	 * @return Cache
	 */
	public static function cache(): Cache {
		self::ensure_initialized();
		return self::$cache;
	}

	/**
	 * Override the cache backend.
	 *
	 * Call this before init() to use a custom cache implementation.
	 * If called after init(), the new cache will only take effect on the
	 * next init() call.
	 *
	 * @param Cache $cache Cache backend to use.
	 */
	public static function set_cache( Cache $cache ): void {
		self::$cache = $cache;
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
	 * Load and register workflow definitions from a directory.
	 *
	 * Each .php file in the directory should return a WorkflowBuilder.
	 * Classes defined in the files are auto-loaded when required.
	 *
	 * @param string $directory Absolute path to the workflows directory.
	 * @param bool   $recursive Whether to scan subdirectories.
	 * @return int Number of workflows registered.
	 * @throws \RuntimeException If the directory does not exist.
	 */
	public static function load_workflows( string $directory, bool $recursive = false ): int {
		self::ensure_initialized();
		return WorkflowLoader::load( $directory, $recursive );
	}

	/**
	 * Load and register a single workflow file.
	 *
	 * @param string $file_path Absolute path to the workflow PHP file.
	 * @return WorkflowTemplate|null The registered template, or null if invalid.
	 */
	public static function load_workflow_file( string $file_path ): ?WorkflowTemplate {
		self::ensure_initialized();
		return WorkflowLoader::load_file( $file_path );
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
	 * Get the workflow event log instance.
	 *
	 * @return WorkflowEventLog
	 */
	public static function workflow_events(): WorkflowEventLog {
		self::ensure_initialized();
		return self::$workflow_event_log;
	}

	/**
	 * Rewind a workflow to a previous step and re-run from there.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @param int $to_step     Step index to rewind to.
	 */
	public static function rewind_workflow( int $workflow_id, int $to_step ): void {
		self::ensure_initialized();
		self::$workflow->rewind( $workflow_id, $to_step );
	}

	/**
	 * Fork a running workflow into an independent copy.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return int The new (forked) workflow ID.
	 */
	public static function fork_workflow( int $workflow_id ): int {
		self::ensure_initialized();
		return self::$workflow->fork( $workflow_id );
	}

	/**
	 * Replace WordPress cron with Queuety's scheduler.
	 *
	 * Only works when WordPress is loaded (the plugin is active).
	 */
	public static function replace_wp_cron(): void {
		CronBridge::install();
	}

	/**
	 * Restore WordPress default cron behaviour.
	 */
	public static function restore_wp_cron(): void {
		CronBridge::uninstall();
	}

	/**
	 * Export a workflow's full execution history.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array JSON-serializable export data.
	 */
	public static function export_workflow( int $workflow_id ): array {
		self::ensure_initialized();
		return WorkflowExporter::export( $workflow_id, self::$conn );
	}

	/**
	 * Replay an exported workflow in the current environment.
	 *
	 * @param array $data Export data from export_workflow().
	 * @return int The new workflow ID.
	 */
	public static function replay_workflow( array $data ): int {
		self::ensure_initialized();
		return WorkflowReplayer::replay( $data, self::$conn );
	}

	/**
	 * Get the full timeline of events for a workflow.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array Array of event rows, ordered by id.
	 */
	public static function workflow_timeline( int $workflow_id ): array {
		self::ensure_initialized();
		return self::$workflow_event_log->get_timeline( $workflow_id );
	}

	/**
	 * Get the state snapshot at a specific workflow step.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @param int $step_index  Step index.
	 * @return array|null The state snapshot, or null if not found.
	 */
	public static function workflow_state_at( int $workflow_id, int $step_index ): ?array {
		self::ensure_initialized();
		return self::$workflow_event_log->get_state_at_step( $workflow_id, $step_index );
	}

	/**
	 * Replace the queue with a fake for testing.
	 *
	 * Returns a QueueFake instance that records all dispatched jobs
	 * and provides assertion methods.
	 *
	 * @return QueueFake The fake queue instance.
	 */
	public static function fake(): QueueFake {
		self::$queue_fake         = new QueueFake();
		self::$fake_queue         = new FakeQueue( self::$queue_fake );
		self::$fake_batch_manager = new FakeBatchManager( self::$queue_fake );
		return self::$queue_fake;
	}

	/**
	 * Get the active queue fake recorder, if any.
	 *
	 * @return QueueFake|null
	 */
	public static function queue_fake(): ?QueueFake {
		return self::$queue_fake;
	}

	/**
	 * Reset the singleton state (for testing).
	 */
	public static function reset(): void {
		self::$conn               = null;
		self::$queue              = null;
		self::$logger             = null;
		self::$workflow           = null;
		self::$worker             = null;
		self::$registry           = null;
		self::$rate_limiter       = null;
		self::$scheduler          = null;
		self::$workflow_registry  = null;
		self::$metrics            = null;
		self::$webhook_notifier   = null;
		self::$batch_manager      = null;
		self::$chunk_store        = null;
		self::$workflow_event_log = null;
		self::$artifact_store     = null;
		self::$cache              = null;
		self::$queue_fake         = null;
		self::$fake_queue         = null;
		self::$fake_batch_manager = null;
		ExecutionContext::clear();
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

	/**
	 * Get the fake queue driver.
	 *
	 * @return FakeQueue
	 */
	private static function fake_queue(): FakeQueue {
		if ( null === self::$fake_queue || null === self::$queue_fake ) {
			self::fake();
		}

		return self::$fake_queue;
	}

	/**
	 * Get the fake batch manager.
	 *
	 * @return FakeBatchManager
	 */
	private static function fake_batch_manager(): FakeBatchManager {
		if ( null === self::$fake_batch_manager || null === self::$queue_fake ) {
			self::fake();
		}

		return self::$fake_batch_manager;
	}

	/**
	 * Apply queue and retry defaults derived from handler metadata.
	 *
	 * @param PendingJob         $pending Pending job builder.
	 * @param string|JobContract $handler Handler alias, class, or job instance.
	 * @return PendingJob
	 */
	private static function apply_pending_defaults( PendingJob $pending, string|JobContract $handler ): PendingJob {
		$class = null;

		if ( $handler instanceof JobContract ) {
			$class = get_class( $handler );
		} elseif ( null !== self::$registry ) {
			$class = self::$registry->class_name( $handler );
		} elseif ( class_exists( $handler ) ) {
			$class = $handler;
		}

		if ( null === $class ) {
			return $pending;
		}

		$defaults = HandlerMetadata::from_class( $class );

		if ( null !== $defaults['queue'] ) {
			$pending->on_queue( $defaults['queue'] );
		}

		if ( null !== $defaults['max_attempts'] ) {
			$pending->max_attempts( $defaults['max_attempts'] );
		}

		return $pending;
	}
}
