<?php
/**
 * WP-CLI webhook management commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Queuety;

/**
 * Queuety webhook management commands.
 */
class WebhookCommand extends \WP_CLI_Command {

	/**
	 * Register a webhook URL for an event.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event name (e.g. job.completed, job.failed, job.buried, workflow.failed).
	 *
	 * <url>
	 * : URL to POST notifications to.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function add( $args, $assoc_args ) {
		$event = $args[0];
		$url   = $args[1];

		$id = Queuety::register_webhook( $event, $url );
		\WP_CLI::success( "Webhook #{$id} registered for event '{$event}'." );
	}

	/**
	 * List all registered webhooks.
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
		$format   = $assoc_args['format'] ?? 'table';
		$webhooks = Queuety::list_webhooks();

		if ( empty( $webhooks ) ) {
			\WP_CLI::log( 'No webhooks registered.' );
			return;
		}

		$fields = array( 'id', 'event', 'url', 'created_at' );
		\WP_CLI\Utils\format_items( $format, $webhooks, $fields );
	}

	/**
	 * Remove a webhook by ID.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Webhook ID to remove.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( $args, $assoc_args ) {
		$id = (int) $args[0];
		Queuety::remove_webhook( $id );
		\WP_CLI::success( "Webhook #{$id} removed." );
	}
}
