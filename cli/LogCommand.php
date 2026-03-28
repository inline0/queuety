<?php
/**
 * WP-CLI log query commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Enums\LogEvent;
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
		$logger = Queuety::logger();
		$rows   = array();

		if ( isset( $assoc_args['job'] ) ) {
			$rows = $logger->for_job( (int) $assoc_args['job'] );
		} elseif ( isset( $assoc_args['workflow'] ) ) {
			$rows = $logger->for_workflow( (int) $assoc_args['workflow'] );
		} elseif ( isset( $assoc_args['handler'] ) ) {
			$limit = (int) ( $assoc_args['limit'] ?? 50 );
			$rows  = $logger->for_handler( $assoc_args['handler'], $limit );
		} elseif ( isset( $assoc_args['event'] ) ) {
			$event = LogEvent::tryFrom( $assoc_args['event'] );
			$limit = (int) ( $assoc_args['limit'] ?? 50 );
			if ( $event ) {
				$rows = $logger->for_event( $event, $limit );
			}
		} elseif ( isset( $assoc_args['since'] ) ) {
			$since = new \DateTimeImmutable( $assoc_args['since'] );
			$limit = (int) ( $assoc_args['limit'] ?? 50 );
			$rows  = $logger->since( $since, $limit );
		} else {
			$limit = (int) ( $assoc_args['limit'] ?? 50 );
			$rows  = $logger->since( new \DateTimeImmutable( '-24 hours' ), $limit );
		}

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
		$count = Queuety::logger()->purge( $days );

		\WP_CLI::success( "Purged {$count} log entries older than {$days} days." );
	}
}
