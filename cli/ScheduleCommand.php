<?php
/**
 * WP-CLI schedule management commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Queuety;

/**
 * Queuety recurring schedule commands.
 */
class ScheduleCommand extends \WP_CLI_Command {

	/**
	 * List all schedules.
	 *
	 * ## OPTIONS
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
		$format    = $assoc_args['format'] ?? 'table';
		$schedules = Queuety::list_schedules();

		$items = array_map(
			fn( $s ) => array(
				'ID'         => $s->id,
				'Handler'    => $s->handler,
				'Expression' => $s->expression,
				'Type'       => $s->expression_type->value,
				'Queue'      => $s->queue,
				'Enabled'    => $s->enabled ? 'yes' : 'no',
				'Last Run'   => $s->last_run ? $s->last_run->format( 'Y-m-d H:i:s' ) : '—',
				'Next Run'   => $s->next_run->format( 'Y-m-d H:i:s' ),
			),
			$schedules
		);

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'ID', 'Handler', 'Expression', 'Type', 'Queue', 'Enabled', 'Last Run', 'Next Run' )
		);
	}

	/**
	 * Add a recurring schedule.
	 *
	 * ## OPTIONS
	 *
	 * <handler>
	 * : Handler name or class.
	 *
	 * [--every=<interval>]
	 * : Interval expression (e.g. '1 hour', '30 minutes').
	 *
	 * [--cron=<expression>]
	 * : Cron expression (e.g. '0 3 * * *').
	 *
	 * [--payload=<json>]
	 * : JSON payload. Default: '{}'.
	 *
	 * [--queue=<queue>]
	 * : Queue name. Default: 'default'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function add( $args, $assoc_args ) {
		$handler = $args[0];
		$payload = json_decode( $assoc_args['payload'] ?? '{}', true ) ?: array();
		$queue   = $assoc_args['queue'] ?? 'default';

		if ( isset( $assoc_args['every'] ) ) {
			$expression = $assoc_args['every'];
			$type       = 'interval';
		} elseif ( isset( $assoc_args['cron'] ) ) {
			$expression = $assoc_args['cron'];
			$type       = 'cron';
		} else {
			\WP_CLI::error( 'You must specify either --every or --cron.' );
			return;
		}

		$id = Queuety::add_schedule( $handler, $payload, $queue, $expression, $type );
		\WP_CLI::success( "Schedule #{$id} added for handler '{$handler}'." );
	}

	/**
	 * Remove a schedule by handler name.
	 *
	 * <handler>
	 * : Handler name to remove.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( $args, $assoc_args ) {
		$handler = $args[0];
		$removed = Queuety::remove_schedule( $handler );

		if ( $removed ) {
			\WP_CLI::success( "Schedule for handler '{$handler}' removed." );
		} else {
			\WP_CLI::warning( "No schedule found for handler '{$handler}'." );
		}
	}

	/**
	 * Manually trigger a scheduler tick.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ) {
		$count = Queuety::run_scheduler();
		\WP_CLI::success( "Scheduler tick complete. Enqueued {$count} jobs." );
	}
}
