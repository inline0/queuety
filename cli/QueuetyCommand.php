<?php
/**
 * WP-CLI job queue commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Queuety;

/**
 * Queuety job queue commands.
 */
class QueuetyCommand extends \WP_CLI_Command {

	/**
	 * Start a worker that processes jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Queue(s) to process, in priority order. Comma-separated values are
	 *   supported for multi-queue workers. The worker tries to claim from each
	 *   queue in the listed order; the first queue with an available job wins.
	 *   Default: 'default'.
	 *
	 * [--once]
	 * : Process one batch and exit.
	 *
	 * [--workers=<n>]
	 * : Fork N worker processes (requires pcntl extension).
	 *
	 * [--min-workers=<n>]
	 * : Minimum worker count for an adaptive pool. Requires --max-workers.
	 *
	 * [--max-workers=<n>]
	 * : Maximum worker count for an adaptive pool.
	 *
	 * ## EXAMPLES
	 *
	 *     # Process a single queue
	 *     wp queuety work --queue=default
	 *
	 *     # Process multiple queues in priority order
	 *     wp queuety work --queue=critical,default,low
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function work( $args, $assoc_args ) {
		$queue       = $assoc_args['queue'] ?? 'default';
		$once        = isset( $assoc_args['once'] );
		$workers     = isset( $assoc_args['workers'] ) ? (int) $assoc_args['workers'] : 0;
		$min_workers = isset( $assoc_args['min-workers'] ) ? (int) $assoc_args['min-workers'] : 1;
		$max_workers = isset( $assoc_args['max-workers'] ) ? (int) $assoc_args['max-workers'] : 0;

		if ( $workers > 1 && ( isset( $assoc_args['min-workers'] ) || isset( $assoc_args['max-workers'] ) ) ) {
			\WP_CLI::error( 'Use either --workers for a fixed pool or --min-workers/--max-workers for adaptive scaling.' );
		}

		if ( isset( $assoc_args['min-workers'] ) && ! isset( $assoc_args['max-workers'] ) ) {
			\WP_CLI::error( '--min-workers requires --max-workers.' );
		}

		if ( $max_workers > 0 ) {
			\WP_CLI::log( "Starting adaptive worker pool ({$min_workers}-{$max_workers}) on queue: {$queue}" );

			try {
				Queuety::run_auto_scaling_worker_pool( $min_workers, $max_workers, $queue );
				\WP_CLI::success( 'Adaptive worker pool stopped.' );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			return;
		}

		if ( $workers > 1 ) {
			\WP_CLI::log( "Starting {$workers} workers on queue: {$queue}" );

			try {
				Queuety::run_worker_pool( $workers, $queue );
				\WP_CLI::success( 'Worker pool stopped.' );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			return;
		}

		\WP_CLI::log( "Starting worker on queue: {$queue}" . ( $once ? ' (once)' : '' ) );

		Queuety::run_worker( $queue, $once );

		\WP_CLI::success( 'Worker stopped.' );
	}

	/**
	 * Process all pending jobs and exit.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Queue(s) to flush, in priority order. Comma-separated values are
	 *   supported. Default: 'default'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function flush( $args, $assoc_args ) {
		$queue = $assoc_args['queue'] ?? 'default';
		$count = Queuety::flush_queue( $queue );

		\WP_CLI::success( "Flushed {$count} jobs." );
	}

	/**
	 * Dispatch a job from the command line.
	 *
	 * ## OPTIONS
	 *
	 * <handler>
	 * : Handler name or class.
	 *
	 * [--payload=<json>]
	 * : JSON payload. Default: '{}'.
	 *
	 * [--queue=<queue>]
	 * : Queue name. Default: 'default'.
	 *
	 * [--priority=<priority>]
	 * : Priority (0-3). Default: 0.
	 *
	 * [--delay=<seconds>]
	 * : Delay in seconds. Default: 0.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function dispatch( $args, $assoc_args ) {
		$handler  = $args[0];
		$payload  = json_decode( $assoc_args['payload'] ?? '{}', true ) ?: array();
		$queue    = $assoc_args['queue'] ?? 'default';
		$priority = (int) ( $assoc_args['priority'] ?? 0 );
		$delay    = (int) ( $assoc_args['delay'] ?? 0 );
		$job_id   = Queuety::dispatch_job( $handler, $payload, $queue, $priority, $delay );
		\WP_CLI::success( "Dispatched job #{$job_id}" );
	}

	/**
	 * Show queue statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Filter by queue name.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$queue = $assoc_args['queue'] ?? null;
		$stats = Queuety::stats( $queue );

		$items = array(
			array(
				'Queue'      => $queue ?? 'all',
				'Pending'    => $stats['pending'],
				'Processing' => $stats['processing'],
				'Completed'  => $stats['completed'],
				'Failed'     => $stats['failed'],
				'Buried'     => $stats['buried'],
			),
		);

		\WP_CLI\Utils\format_items( 'table', $items, array( 'Queue', 'Pending', 'Processing', 'Completed', 'Failed', 'Buried' ) );
	}

	/**
	 * List jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Filter by queue.
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 *
	 * [--format=<format>]
	 * : Output format. Default: 'table'.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';
		$rows   = Queuety::list_jobs(
			$assoc_args['queue'] ?? null,
			$assoc_args['status'] ?? null,
			50
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'queue', 'handler', 'status', 'attempts', 'priority', 'created_at' ) );
	}

	/**
	 * Retry a specific job.
	 *
	 * <id>
	 * : Job ID to retry.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry( $args, $assoc_args ) {
		Queuety::retry( (int) $args[0] );
		\WP_CLI::success( "Job #{$args[0]} scheduled for retry." );
	}

	/**
	 * Retry all buried jobs.
	 *
	 * @subcommand retry-buried
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry_buried( $args, $assoc_args ) {
		$count = Queuety::retry_buried();
		\WP_CLI::success( "Retried {$count} buried jobs." );
	}

	/**
	 * Bury a job.
	 *
	 * <id>
	 * : Job ID to bury.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function bury( $args, $assoc_args ) {
		Queuety::bury_job( (int) $args[0], 'Manually buried via CLI.' );
		\WP_CLI::success( "Job #{$args[0]} buried." );
	}

	/**
	 * Delete a job.
	 *
	 * <id>
	 * : Job ID to delete.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		Queuety::delete_job( (int) $args[0] );
		\WP_CLI::success( "Job #{$args[0]} deleted." );
	}

	/**
	 * Recover stale jobs.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function recover( $args, $assoc_args ) {
		$count = Queuety::recover_stale_jobs();
		\WP_CLI::success( "Recovered {$count} stale jobs." );
	}

	/**
	 * Purge completed jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Delete jobs older than N days. Default: config value.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		$days  = isset( $assoc_args['older-than'] ) ? (int) $assoc_args['older-than'] : null;
		$count = Queuety::purge( $days );
		\WP_CLI::success( "Purged {$count} completed jobs." );
	}

	/**
	 * Pause a queue so workers skip it.
	 *
	 * ## OPTIONS
	 *
	 * <queue>
	 * : Queue name to pause.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pause( $args, $assoc_args ) {
		$queue = $args[0];
		Queuety::pause( $queue );
		\WP_CLI::success( "Queue '{$queue}' paused." );
	}

	/**
	 * Resume a paused queue.
	 *
	 * ## OPTIONS
	 *
	 * <queue>
	 * : Queue name to resume.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function resume( $args, $assoc_args ) {
		$queue = $args[0];
		Queuety::resume( $queue );
		\WP_CLI::success( "Queue '{$queue}' resumed." );
	}

	/**
	 * Show full details of a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID to inspect.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function inspect( $args, $assoc_args ) {
		$job_id = (int) $args[0];
		$data   = Queuety::inspect_job( $job_id );

		if ( null === $data ) {
			\WP_CLI::error( "Job #{$job_id} not found." );
			return;
		}

		$job = $data['job'];

		\WP_CLI::line( "Job #{$job['id']}" );
		\WP_CLI::line( "Handler:      {$job['handler']}" );
		\WP_CLI::line( "Queue:        {$job['queue']}" );
		\WP_CLI::line( "Status:       {$job['status']}" );
		\WP_CLI::line( "Priority:     {$job['priority']}" );
		\WP_CLI::line( "Attempts:     {$job['attempts']}/{$job['max_attempts']}" );
		\WP_CLI::line( "Created:      {$job['created_at']}" );
		\WP_CLI::line( "Available at: {$job['available_at']}" );

		if ( null !== $job['reserved_at'] ) {
			\WP_CLI::line( "Reserved at:  {$job['reserved_at']}" );
		}
		if ( null !== $job['completed_at'] ) {
			\WP_CLI::line( "Completed at: {$job['completed_at']}" );
		}
		if ( null !== $job['failed_at'] ) {
			\WP_CLI::line( "Failed at:    {$job['failed_at']}" );
		}
		if ( null !== $job['error_message'] ) {
			\WP_CLI::line( "Error:        {$job['error_message']}" );
		}
		if ( null !== $job['workflow_id'] ) {
			\WP_CLI::line( "Workflow ID:  {$job['workflow_id']}" );
			\WP_CLI::line( "Step index:   {$job['step_index']}" );
		}
		if ( null !== $job['depends_on'] ) {
			\WP_CLI::line( "Depends on:   {$job['depends_on']}" );
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Payload:' );
		\WP_CLI::line( json_encode( $job['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$logs = $data['logs'];
		if ( ! empty( $logs ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Log history:' );
			$fields = array( 'id', 'event', 'attempt', 'duration_ms', 'error_message', 'created_at' );
			\WP_CLI\Utils\format_items( 'table', $logs, $fields );
		}
	}

	/**
	 * Show metrics for all handlers.
	 *
	 * ## OPTIONS
	 *
	 * [--minutes=<minutes>]
	 * : Time window in minutes. Default: 60.
	 *
	 * [--format=<format>]
	 * : Output format. Default: 'table'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function metrics( $args, $assoc_args ) {
		$minutes = (int) ( $assoc_args['minutes'] ?? 60 );
		$format  = $assoc_args['format'] ?? 'table';
		$stats   = Queuety::handler_metrics( $minutes );

		if ( empty( $stats ) ) {
			\WP_CLI::log( "No metrics data found for the last {$minutes} minutes." );
			return;
		}

		$fields = array( 'handler', 'completed', 'failed', 'avg_ms', 'p95_ms', 'error_rate' );
		\WP_CLI\Utils\format_items( $format, $stats, $fields );
	}

	/**
	 * Discover handler classes in a directory.
	 *
	 * ## OPTIONS
	 *
	 * <directory>
	 * : Directory to scan for handler classes.
	 *
	 * <namespace>
	 * : PSR-4 namespace prefix for the directory.
	 *
	 * [--register]
	 * : Actually register discovered handlers.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function discover( $args, $assoc_args ) {
		$directory  = $args[0];
		$namespace  = $args[1];
		$register   = isset( $assoc_args['register'] );
		$result     = Queuety::discover_handlers_cli( $directory, $namespace, $register );
		$discovered = $result['discovered'];

		if ( empty( $discovered ) ) {
			\WP_CLI::log( 'No handlers found.' );
			return;
		}

		$items = array();
		foreach ( $discovered as $entry ) {
			$items[] = array(
				'class' => $entry['class'],
				'type'  => $entry['type'],
				'name'  => $entry['name'] ?? '(none)',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'class', 'type', 'name' ) );

		if ( $register ) {
			\WP_CLI::success( "Registered {$result['registered']} handlers." );
		} else {
			\WP_CLI::log( 'Use --register to register discovered handlers.' );
		}
	}
}
