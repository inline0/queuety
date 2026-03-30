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
	 * Decode a JSON assoc arg into an array payload.
	 *
	 * @param array  $assoc_args Associative command arguments.
	 * @param string $key        Argument key.
	 * @return array
	 */
	private function decode_json_assoc_arg( array $assoc_args, string $key ): array {
		$value = $assoc_args[ $key ] ?? null;
		if ( null === $value ) {
			return array();
		}

		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( "--{$key} must be a JSON object." );
		}

		return $decoded;
	}

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

		if ( null !== $state->current_step_name ) {
			\WP_CLI::log( "StepName: {$state->current_step_name}" );
		}

		if ( null !== $state->definition_version ) {
			\WP_CLI::log( "Version:  {$state->definition_version}" );
		}

		if ( null !== $state->definition_hash ) {
			\WP_CLI::log( "Hash:     {$state->definition_hash}" );
		}

		if ( null !== $state->idempotency_key ) {
			\WP_CLI::log( "Key:      {$state->idempotency_key}" );
		}

		if ( null !== $state->wait_type && ! empty( $state->waiting_for ) ) {
			\WP_CLI::log( 'Waiting:  ' . $state->wait_type . ' => ' . implode( ', ', array_map( 'strval', $state->waiting_for ) ) );
		}

		if ( null !== $state->wait_mode ) {
			\WP_CLI::log( "WaitMode: {$state->wait_mode}" );
		}

		if ( null !== $state->wait_details ) {
			\WP_CLI::log( 'WaitInfo: ' . json_encode( $state->wait_details, JSON_UNESCAPED_SLASHES ) );
		}

		if ( null !== $state->budget ) {
			\WP_CLI::log( 'Budget:   ' . json_encode( $state->budget, JSON_UNESCAPED_SLASHES ) );
		}

		if ( null !== $state->artifact_count ) {
			\WP_CLI::log( "Artifacts: {$state->artifact_count}" );
		}

		if ( ! empty( $state->artifact_keys ) ) {
			\WP_CLI::log( 'ArtifactKeys: ' . implode( ', ', $state->artifact_keys ) );
		}

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
	 * Send an approval signal to a workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * ## OPTIONS
	 *
	 * [--data=<json>]
	 * : Optional JSON object payload.
	 *
	 * [--signal=<name>]
	 * : Override the approval signal name. Default: approval
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function approve( $args, $assoc_args ) {
		$data   = $this->decode_json_assoc_arg( $assoc_args, 'data' );
		$signal = $assoc_args['signal'] ?? 'approval';

		Queuety::approve_workflow( (int) $args[0], $data, (string) $signal );
		\WP_CLI::success( "Approval sent to workflow #{$args[0]}." );
	}

	/**
	 * Send a rejection signal to a workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * ## OPTIONS
	 *
	 * [--data=<json>]
	 * : Optional JSON object payload.
	 *
	 * [--signal=<name>]
	 * : Override the rejection signal name. Default: rejected
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reject( $args, $assoc_args ) {
		$data   = $this->decode_json_assoc_arg( $assoc_args, 'data' );
		$signal = $assoc_args['signal'] ?? 'rejected';

		Queuety::reject_workflow( (int) $args[0], $data, (string) $signal );
		\WP_CLI::success( "Rejection sent to workflow #{$args[0]}." );
	}

	/**
	 * Send structured input to a workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * ## OPTIONS
	 *
	 * [--data=<json>]
	 * : JSON object payload. Default: {}
	 *
	 * [--signal=<name>]
	 * : Override the input signal name. Default: input
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function input( $args, $assoc_args ) {
		$data   = $this->decode_json_assoc_arg( $assoc_args, 'data' );
		$signal = $assoc_args['signal'] ?? 'input';

		Queuety::submit_workflow_input( (int) $args[0], $data, (string) $signal );
		\WP_CLI::success( "Input sent to workflow #{$args[0]}." );
	}

	/**
	 * List stored artifacts for a workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: 'table'.
	 *
	 * [--with-content]
	 * : Include artifact content in the output.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function artifacts( $args, $assoc_args ) {
		$workflow_id      = (int) $args[0];
		$format           = $assoc_args['format'] ?? 'table';
		$include_content  = isset( $assoc_args['with-content'] );
		$artifacts        = Queuety::workflow_artifacts( $workflow_id, $include_content );

		if ( 'json' === $format ) {
			\WP_CLI::log( json_encode( $artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$items = array_map(
			static fn( array $artifact ): array => array(
				'Key'       => $artifact['key'],
				'Kind'      => $artifact['kind'],
				'Step'      => $artifact['step_index'] ?? '-',
				'UpdatedAt' => $artifact['updated_at'],
				'Content'   => $include_content ? json_encode( $artifact['content'], JSON_UNESCAPED_SLASHES ) : '-',
			),
			$artifacts
		);

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			$include_content
				? array( 'Key', 'Kind', 'Step', 'UpdatedAt', 'Content' )
				: array( 'Key', 'Kind', 'Step', 'UpdatedAt' )
		);
	}

	/**
	 * Show one stored artifact for a workflow.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * <key>
	 * : Artifact key.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function artifact( $args, $assoc_args ) {
		$artifact = Queuety::workflow_artifact( (int) $args[0], (string) $args[1] );

		if ( null === $artifact ) {
			\WP_CLI::error( "Artifact '{$args[1]}' not found for workflow #{$args[0]}." );
			return;
		}

		\WP_CLI::log( json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
				'ID'       => $wf->workflow_id,
				'Name'     => $wf->name,
				'Version'  => $wf->definition_version ?? '-',
				'Hash'     => null !== $wf->definition_hash ? substr( $wf->definition_hash, 0, 12 ) : '-',
				'Status'   => $wf->status->value,
				'Step'     => "{$wf->current_step}/{$wf->total_steps}",
				'StepName' => $wf->current_step_name ?? '-',
				'WaitMode' => $wf->wait_mode ?? '-',
				'Waiting'  => null !== $wf->wait_type && ! empty( $wf->waiting_for )
					? $wf->wait_type . ':' . implode( ',', array_map( 'strval', $wf->waiting_for ) )
					: '-',
			),
			$workflows
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'Name', 'Version', 'Hash', 'Status', 'Step', 'StepName', 'WaitMode', 'Waiting' ) );
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
	 * Rewind a workflow to a previous step and re-run from there.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * <step>
	 * : Step index to rewind to (0-based).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rewind( $args, $assoc_args ) {
		try {
			Queuety::rewind_workflow( (int) $args[0], (int) $args[1] );
			\WP_CLI::success( "Workflow #{$args[0]} rewound to step {$args[1]}." );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Fork a running workflow into an independent copy.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function fork( $args, $assoc_args ) {
		try {
			$forked_id = Queuety::fork_workflow( (int) $args[0] );
			\WP_CLI::success( "Workflow #{$args[0]} forked. New workflow ID: {$forked_id}." );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Export a workflow's full execution history to JSON.
	 *
	 * <id>
	 * : Workflow ID.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<file>]
	 * : File path to write the JSON to. Defaults to stdout.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function export( $args, $assoc_args ) {
		try {
			$data = Queuety::export_workflow( (int) $args[0] );
			$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

			if ( isset( $assoc_args['output'] ) ) {
				file_put_contents( $assoc_args['output'], $json );
				\WP_CLI::success( "Workflow #{$args[0]} exported to {$assoc_args['output']}." );
			} else {
				\WP_CLI::log( $json );
			}
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Replay an exported workflow from a JSON file.
	 *
	 * <file>
	 * : Path to the exported JSON file.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function replay( $args, $assoc_args ) {
		$file = $args[0];

		if ( ! file_exists( $file ) ) {
			\WP_CLI::error( "File not found: {$file}" );
			return;
		}

		try {
			$json   = file_get_contents( $file );
			$data   = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			$new_id = Queuety::replay_workflow( $data );
			\WP_CLI::success( "Workflow replayed. New workflow ID: {$new_id}." );
		} catch ( \JsonException $e ) {
			\WP_CLI::error( "Invalid JSON: {$e->getMessage()}" );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
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
