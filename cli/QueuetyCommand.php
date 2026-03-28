<?php
/**
 * WP-CLI job queue commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Enums\JobStatus;
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
	 * : Queue to process. Default: 'default'.
	 *
	 * [--once]
	 * : Process one batch and exit.
	 *
	 * [--workers=<n>]
	 * : Fork N worker processes (requires pcntl extension).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function work( $args, $assoc_args ) {
		$queue   = $assoc_args['queue'] ?? 'default';
		$once    = isset( $assoc_args['once'] );
		$workers = isset( $assoc_args['workers'] ) ? (int) $assoc_args['workers'] : 0;

		if ( $workers > 1 ) {
			if ( ! function_exists( 'pcntl_fork' ) ) {
				\WP_CLI::error( 'The pcntl extension is required for --workers=N.' );
				return;
			}

			\WP_CLI::log( "Starting {$workers} workers on queue: {$queue}" );

			$pool = new \Queuety\WorkerPool(
				$workers,
				DB_HOST,
				DB_NAME,
				DB_USER,
				DB_PASSWORD,
				$GLOBALS['wpdb']->prefix,
			);
			$pool->run( $queue );

			\WP_CLI::success( 'Worker pool stopped.' );
			return;
		}

		\WP_CLI::log( "Starting worker on queue: {$queue}" . ( $once ? ' (once)' : '' ) );

		Queuety::worker()->run( $queue, $once );

		\WP_CLI::success( 'Worker stopped.' );
	}

	/**
	 * Process all pending jobs and exit.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Queue to flush. Default: 'default'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function flush( $args, $assoc_args ) {
		$queue = $assoc_args['queue'] ?? 'default';
		$count = Queuety::worker()->flush( $queue );

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

		$pending = Queuety::dispatch( $handler, $payload )
			->on_queue( $queue )
			->delay( $delay );

		$job_id = $pending->id();
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
		$table  = Queuety::queue();

		// Build a simple query using the Queue's connection.
		$conn   = $this->get_connection();
		$tbl    = $conn->table( \Queuety\Config::table_jobs() );
		$sql    = "SELECT id, queue, handler, status, attempts, priority, created_at FROM {$tbl} WHERE 1=1";
		$params = array();

		if ( isset( $assoc_args['queue'] ) ) {
			$sql            .= ' AND queue = :queue';
			$params['queue'] = $assoc_args['queue'];
		}
		if ( isset( $assoc_args['status'] ) ) {
			$sql             .= ' AND status = :status';
			$params['status'] = $assoc_args['status'];
		}

		$sql .= ' ORDER BY id DESC LIMIT 50';
		$stmt = $conn->pdo()->prepare( $sql );
		$stmt->execute( $params );
		$rows = $stmt->fetchAll();

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
		Queuety::queue()->bury( (int) $args[0], 'Manually buried via CLI.' );
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
		$conn  = $this->get_connection();
		$table = $conn->table( \Queuety\Config::table_jobs() );
		$stmt  = $conn->pdo()->prepare( "DELETE FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => (int) $args[0] ) );

		\WP_CLI::success( "Job #{$args[0]} deleted." );
	}

	/**
	 * Recover stale jobs.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function recover( $args, $assoc_args ) {
		$count = Queuety::worker()->recover_stale();
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
	 * Get the database connection via reflection.
	 *
	 * @return \Queuety\Connection
	 */
	private function get_connection(): \Queuety\Connection {
		// Access the connection through the Queue's internal state.
		// The facade exposes queue() which requires the connection.
		$reflection = new \ReflectionProperty( \Queuety\Queue::class, 'conn' );
		return $reflection->getValue( Queuety::queue() );
	}
}
