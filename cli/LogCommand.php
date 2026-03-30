<?php
/**
 * WP-CLI log query commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Queuety;

/**
 * Queuety log query commands.
 */
class LogCommand extends \WP_CLI_Command {

	/**
	 * Query log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--job=<id>]
	 * : Filter by job ID.
	 *
	 * [--workflow=<id>]
	 * : Filter by workflow ID.
	 *
	 * [--handler=<name>]
	 * : Filter by handler name.
	 *
	 * [--event=<event>]
	 * : Filter by event type.
	 *
	 * [--since=<datetime>]
	 * : Show entries since this datetime.
	 *
	 * [--limit=<n>]
	 * : Max entries to show. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format. Default: 'table'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';
		$rows   = Queuety::query_logs(
			array(
				'job_id'      => isset( $assoc_args['job'] ) ? (int) $assoc_args['job'] : null,
				'workflow_id' => isset( $assoc_args['workflow'] ) ? (int) $assoc_args['workflow'] : null,
				'handler'     => $assoc_args['handler'] ?? null,
				'event'       => $assoc_args['event'] ?? null,
				'since'       => $assoc_args['since'] ?? null,
				'limit'       => (int) ( $assoc_args['limit'] ?? 50 ),
			)
		);

		$fields = array( 'id', 'event', 'handler', 'job_id', 'workflow_id', 'step_index', 'attempt', 'duration_ms', 'created_at' );

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Purge old log entries.
	 *
	 * ## OPTIONS
	 *
	 * --older-than=<days>
	 * : Delete entries older than N days.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! isset( $assoc_args['older-than'] ) ) {
			\WP_CLI::error( 'Required: --older-than=<days>' );
			return;
		}

		$days  = (int) $assoc_args['older-than'];
		$count = Queuety::purge_logs( $days );

		\WP_CLI::success( "Purged {$count} log entries older than {$days} days." );
	}
}
