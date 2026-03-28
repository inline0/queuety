<?php
/**
 * WP-CLI workflow management commands.
 *
 * @package Queuety
 */

namespace Queuety\CLI;

use Queuety\Queuety;

/**
 * Queuety workflow management commands.
 */
class WorkflowCommand extends \WP_CLI_Command {

	/**
	 * Show workflow status and progress.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$state = Queuety::workflow_status( (int) $args[0] );

		if ( null === $state ) {
			\WP_CLI::error( "Workflow #{$args[0]} not found." );
			return;
		}

		\WP_CLI::log( "Workflow: {$state->name} (#{$state->workflow_id})" );
		\WP_CLI::log( "Status:   {$state->status->value}" );
		\WP_CLI::log( "Step:     {$state->current_step}/{$state->total_steps}" );

		if ( ! empty( $state->state ) ) {
			\WP_CLI::log( 'State:' );
			\WP_CLI::log( json_encode( $state->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Retry a failed workflow from its failed step.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry( $args, $assoc_args ) {
		Queuety::retry_workflow( (int) $args[0] );
		\WP_CLI::success( "Workflow #{$args[0]} scheduled for retry." );
	}

	/**
	 * Pause a running workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pause( $args, $assoc_args ) {
		Queuety::pause_workflow( (int) $args[0] );
		\WP_CLI::success( "Workflow #{$args[0]} paused." );
	}

	/**
	 * Cancel a workflow and run cleanup handlers.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cancel( $args, $assoc_args ) {
		try {
			Queuety::cancel_workflow( (int) $args[0] );
			\WP_CLI::success( "Workflow #{$args[0]} cancelled." );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Resume a paused workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function resume( $args, $assoc_args ) {
		Queuety::resume_workflow( (int) $args[0] );
		\WP_CLI::success( "Workflow #{$args[0]} resumed." );
	}

	/**
	 * List workflows.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (running, completed, failed, paused).
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
		$format        = $assoc_args['format'] ?? 'table';
		$status_filter = null;

		if ( isset( $assoc_args['status'] ) ) {
			$status_filter = \Queuety\Enums\WorkflowStatus::tryFrom( $assoc_args['status'] );
		}

		$workflows = Queuety::workflow_manager()->list( $status_filter );

		$items = array_map(
			fn( $wf ) => array(
				'ID'     => $wf->workflow_id,
				'Name'   => $wf->name,
				'Status' => $wf->status->value,
				'Step'   => "{$wf->current_step}/{$wf->total_steps}",
			),
			$workflows
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'Name', 'Status', 'Step' ) );
	}

	/**
	 * Show the event timeline for a workflow.
	 *
	 * Displays all step events (started, completed, failed) with timestamps,
	 * handlers, and durations.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: 'table'.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function timeline( $args, $assoc_args ) {
		$workflow_id = (int) $args[0];
		$format      = $assoc_args['format'] ?? 'table';
		$events      = Queuety::workflow_timeline( $workflow_id );

		if ( empty( $events ) ) {
			\WP_CLI::log( "No events found for workflow #{$workflow_id}." );
			return;
		}

		$items = array_map(
			fn( array $event ) => array(
				'ID'          => $event['id'],
				'Step'        => $event['step_index'],
				'Event'       => $event['event'],
				'Handler'     => $event['handler'],
				'Duration_ms' => $event['duration_ms'] ?? '-',
				'Error'       => $event['error_message'] ?? '-',
				'Created_at'  => $event['created_at'],
			),
			$events
		);

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'ID', 'Step', 'Event', 'Handler', 'Duration_ms', 'Error', 'Created_at' )
		);
	}

	/**
	 * Show the state snapshot at a specific workflow step.
	 *
	 * Displays the full workflow state as it was after the given step completed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * <step>
	 * : Step index (0-based).
	 *
	 * @subcommand state-at
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function state_at( $args, $assoc_args ) {
		$workflow_id = (int) $args[0];
		$step_index  = (int) $args[1];
		$snapshot    = Queuety::workflow_state_at( $workflow_id, $step_index );

		if ( null === $snapshot ) {
			\WP_CLI::error( "No state snapshot found for workflow #{$workflow_id} at step {$step_index}." );
			return;
		}

		\WP_CLI::log( "State at step {$step_index} for workflow #{$workflow_id}:" );
		\WP_CLI::log( json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
