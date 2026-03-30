<?php
/**
 * Serializable catalog for translating WP-CLI commands into PHP operations.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\CLI\LogCommand;
use Queuety\CLI\QueuetyCommand;
use Queuety\CLI\ScheduleCommand;
use Queuety\CLI\WebhookCommand;
use Queuety\CLI\WorkflowCommand;

/**
 * Exposes the CLI surface as stable metadata for agent and harness integrations.
 */
class CliCommandMap {

	/**
	 * Return the full command catalog.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array_values( self::definition_index() );
	}

	/**
	 * Resolve one command definition by path.
	 *
	 * @param string|array<int, string> $path Command path with or without "wp" and the root command.
	 * @return array<string, mixed>|null
	 */
	public static function definition( $path ): ?array {
		$key = self::path_key( self::normalize_path( $path ) );

		return self::definition_index()[ $key ] ?? null;
	}

	/**
	 * Convert one parsed CLI invocation into an execution plan.
	 *
	 * @param string|array<int, string> $path Command path with or without "wp" and the root command.
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, mixed>      $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the CLI path is not part of the published command surface.
	 * @throws \RuntimeException If the command adapter does not return a valid execution plan.
	 */
	public static function resolve( $path, array $args = array(), array $assoc_args = array() ): array {
		$definition = self::definition( $path );
		if ( ! $definition ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown Queuety CLI command path: %s', self::path_key( self::normalize_path( $path ) ) )
			);
		}

		$adapter = $definition['adapter'];
		$plan    = call_user_func( $adapter, $args, $assoc_args );
		if ( ! is_array( $plan ) ) {
			throw new \RuntimeException( sprintf( 'CLI adapter did not return a plan: %s', $adapter ) );
		}

		return array_merge(
			$definition,
			array(
				'transport'            => 'php',
				'arguments'            => array(),
				'needs_confirmation'   => false,
				'confirmation_message' => null,
			),
			$plan
		);
	}

	/**
	 * Build the indexed command catalog once per request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definition_index(): array {
		static $definitions = null;

		if ( null !== $definitions ) {
			return $definitions;
		}

		$definitions = array();

		foreach ( self::raw_definitions() as $definition ) {
			$definition['wp_cli_command']                             = 'wp ' . Queuety::cli_command() . ' ' . self::path_key( $definition['cli_path'] );
			$definitions[ self::path_key( $definition['cli_path'] ) ] = $definition;
		}

		return $definitions;
	}

	/**
	 * Define the supported command surface in one place so the harness can trust it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function raw_definitions(): array {
		return array(
			self::definition_item(
				'worker.run',
				array( 'work' ),
				QueuetyCommand::class,
				'work',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::run_worker',
					),
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::run_worker_pool',
					),
				),
				CliCommandAdapters::class . '::worker_run',
				'Run one worker or a forked worker pool.'
			),
			self::definition_item(
				'queue.flush',
				array( 'flush' ),
				QueuetyCommand::class,
				'flush',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::flush_queue',
					),
				),
				CliCommandAdapters::class . '::queue_flush',
				'Process all pending jobs and exit.'
			),
			self::definition_item(
				'job.dispatch',
				array( 'dispatch' ),
				QueuetyCommand::class,
				'dispatch',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::dispatch_job',
					),
				),
				CliCommandAdapters::class . '::job_dispatch',
				'Dispatch a job with queue, priority, and delay options.'
			),
			self::definition_item(
				'queue.status',
				array( 'status' ),
				QueuetyCommand::class,
				'status',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::stats',
					),
				),
				CliCommandAdapters::class . '::queue_status',
				'Read queue status counters.'
			),
			self::definition_item(
				'job.list',
				array( 'list' ),
				QueuetyCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::list_jobs',
					),
				),
				CliCommandAdapters::class . '::job_list',
				'List recent jobs with optional queue and status filters.'
			),
			self::definition_item(
				'job.retry',
				array( 'retry' ),
				QueuetyCommand::class,
				'retry',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::retry',
					),
				),
				CliCommandAdapters::class . '::job_retry',
				'Retry one job.'
			),
			self::definition_item(
				'job.retry-buried',
				array( 'retry-buried' ),
				QueuetyCommand::class,
				'retry_buried',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::retry_buried',
					),
				),
				CliCommandAdapters::class . '::job_retry_buried',
				'Retry all buried jobs.'
			),
			self::definition_item(
				'job.bury',
				array( 'bury' ),
				QueuetyCommand::class,
				'bury',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::bury_job',
					),
				),
				CliCommandAdapters::class . '::job_bury',
				'Manually bury a job.'
			),
			self::definition_item(
				'job.delete',
				array( 'delete' ),
				QueuetyCommand::class,
				'delete',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::delete_job',
					),
				),
				CliCommandAdapters::class . '::job_delete',
				'Delete a job row.'
			),
			self::definition_item(
				'job.recover',
				array( 'recover' ),
				QueuetyCommand::class,
				'recover',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::recover_stale_jobs',
					),
				),
				CliCommandAdapters::class . '::job_recover',
				'Recover stale processing jobs.'
			),
			self::definition_item(
				'job.purge',
				array( 'purge' ),
				QueuetyCommand::class,
				'purge',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::purge',
					),
				),
				CliCommandAdapters::class . '::job_purge',
				'Purge completed jobs.'
			),
			self::definition_item(
				'queue.pause',
				array( 'pause' ),
				QueuetyCommand::class,
				'pause',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::pause',
					),
				),
				CliCommandAdapters::class . '::queue_pause',
				'Pause one queue.'
			),
			self::definition_item(
				'queue.resume',
				array( 'resume' ),
				QueuetyCommand::class,
				'resume',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::resume',
					),
				),
				CliCommandAdapters::class . '::queue_resume',
				'Resume one queue.'
			),
			self::definition_item(
				'job.inspect',
				array( 'inspect' ),
				QueuetyCommand::class,
				'inspect',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::inspect_job',
					),
				),
				CliCommandAdapters::class . '::job_inspect',
				'Inspect one job and its log history.'
			),
			self::definition_item(
				'metrics.handlers',
				array( 'metrics' ),
				QueuetyCommand::class,
				'metrics',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::handler_metrics',
					),
				),
				CliCommandAdapters::class . '::metrics_handlers',
				'Read per-handler metrics.'
			),
			self::definition_item(
				'handler.discover',
				array( 'discover' ),
				QueuetyCommand::class,
				'discover',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::discover_handlers_cli',
					),
				),
				CliCommandAdapters::class . '::handler_discover',
				'Discover handlers and optionally register them.'
			),
			self::definition_item(
				'workflow.status',
				array( 'workflow', 'status' ),
				WorkflowCommand::class,
				'status',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::workflow_status',
					),
				),
				CliCommandAdapters::class . '::workflow_status',
				'Read workflow status.'
			),
			self::definition_item(
				'workflow.retry',
				array( 'workflow', 'retry' ),
				WorkflowCommand::class,
				'retry',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::retry_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_retry',
				'Retry a failed workflow.'
			),
			self::definition_item(
				'workflow.approve',
				array( 'workflow', 'approve' ),
				WorkflowCommand::class,
				'approve',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::approve_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_approve',
				'Send approval data to a workflow.'
			),
			self::definition_item(
				'workflow.reject',
				array( 'workflow', 'reject' ),
				WorkflowCommand::class,
				'reject',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::reject_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_reject',
				'Send rejection data to a workflow.'
			),
			self::definition_item(
				'workflow.input',
				array( 'workflow', 'input' ),
				WorkflowCommand::class,
				'input',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::submit_workflow_input',
					),
				),
				CliCommandAdapters::class . '::workflow_input',
				'Send structured input to a workflow.'
			),
			self::definition_item(
				'workflow.artifacts',
				array( 'workflow', 'artifacts' ),
				WorkflowCommand::class,
				'artifacts',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::workflow_artifacts',
					),
				),
				CliCommandAdapters::class . '::workflow_artifacts',
				'List workflow artifacts.'
			),
			self::definition_item(
				'workflow.artifact',
				array( 'workflow', 'artifact' ),
				WorkflowCommand::class,
				'artifact',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::workflow_artifact',
					),
				),
				CliCommandAdapters::class . '::workflow_artifact',
				'Read one workflow artifact.'
			),
			self::definition_item(
				'workflow.pause',
				array( 'workflow', 'pause' ),
				WorkflowCommand::class,
				'pause',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::pause_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_pause',
				'Pause a workflow.'
			),
			self::definition_item(
				'workflow.cancel',
				array( 'workflow', 'cancel' ),
				WorkflowCommand::class,
				'cancel',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::cancel_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_cancel',
				'Cancel a workflow.'
			),
			self::definition_item(
				'workflow.resume',
				array( 'workflow', 'resume' ),
				WorkflowCommand::class,
				'resume',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::resume_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_resume',
				'Resume a paused workflow.'
			),
			self::definition_item(
				'workflow.list',
				array( 'workflow', 'list' ),
				WorkflowCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::list_workflows',
					),
				),
				CliCommandAdapters::class . '::workflow_list',
				'List workflows.'
			),
			self::definition_item(
				'workflow.timeline',
				array( 'workflow', 'timeline' ),
				WorkflowCommand::class,
				'timeline',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::workflow_timeline',
					),
				),
				CliCommandAdapters::class . '::workflow_timeline',
				'Read the workflow event timeline.'
			),
			self::definition_item(
				'workflow.rewind',
				array( 'workflow', 'rewind' ),
				WorkflowCommand::class,
				'rewind',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::rewind_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_rewind',
				'Rewind a workflow to an earlier step.'
			),
			self::definition_item(
				'workflow.fork',
				array( 'workflow', 'fork' ),
				WorkflowCommand::class,
				'fork',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::fork_workflow',
					),
				),
				CliCommandAdapters::class . '::workflow_fork',
				'Fork a workflow into an independent copy.'
			),
			self::definition_item(
				'workflow.export',
				array( 'workflow', 'export' ),
				WorkflowCommand::class,
				'export',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::export_workflow',
					),
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::export_workflow_to_file',
					),
				),
				CliCommandAdapters::class . '::workflow_export',
				'Export a workflow to JSON or write it to a file.'
			),
			self::definition_item(
				'workflow.replay',
				array( 'workflow', 'replay' ),
				WorkflowCommand::class,
				'replay',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::replay_workflow_file',
					),
				),
				CliCommandAdapters::class . '::workflow_replay',
				'Replay a workflow export from disk.'
			),
			self::definition_item(
				'workflow.state-at',
				array( 'workflow', 'state-at' ),
				WorkflowCommand::class,
				'state_at',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::workflow_state_at',
					),
				),
				CliCommandAdapters::class . '::workflow_state_at',
				'Read a workflow state snapshot.'
			),
			self::definition_item(
				'log.query',
				array( 'log' ),
				LogCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::query_logs',
					),
				),
				CliCommandAdapters::class . '::log_query',
				'Query log entries.'
			),
			self::definition_item(
				'log.purge',
				array( 'log', 'purge' ),
				LogCommand::class,
				'purge',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::purge_logs',
					),
				),
				CliCommandAdapters::class . '::log_purge',
				'Purge old log entries.'
			),
			self::definition_item(
				'schedule.list',
				array( 'schedule', 'list' ),
				ScheduleCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::list_schedules',
					),
				),
				CliCommandAdapters::class . '::schedule_list',
				'List recurring schedules.'
			),
			self::definition_item(
				'schedule.add',
				array( 'schedule', 'add' ),
				ScheduleCommand::class,
				'add',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::add_schedule',
					),
				),
				CliCommandAdapters::class . '::schedule_add',
				'Create a recurring schedule.'
			),
			self::definition_item(
				'schedule.remove',
				array( 'schedule', 'remove' ),
				ScheduleCommand::class,
				'remove',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::remove_schedule',
					),
				),
				CliCommandAdapters::class . '::schedule_remove',
				'Remove a recurring schedule.'
			),
			self::definition_item(
				'schedule.run',
				array( 'schedule', 'run' ),
				ScheduleCommand::class,
				'run',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::run_scheduler',
					),
				),
				CliCommandAdapters::class . '::schedule_run',
				'Run one scheduler tick.'
			),
			self::definition_item(
				'webhook.add',
				array( 'webhook', 'add' ),
				WebhookCommand::class,
				'add',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::register_webhook',
					),
				),
				CliCommandAdapters::class . '::webhook_add',
				'Register a webhook.'
			),
			self::definition_item(
				'webhook.list',
				array( 'webhook', 'list' ),
				WebhookCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::list_webhooks',
					),
				),
				CliCommandAdapters::class . '::webhook_list',
				'List webhooks.'
			),
			self::definition_item(
				'webhook.remove',
				array( 'webhook', 'remove' ),
				WebhookCommand::class,
				'remove',
				array(
					array(
						'transport' => 'php',
						'callable'  => Queuety::class . '::remove_webhook',
					),
				),
				CliCommandAdapters::class . '::webhook_remove',
				'Remove a webhook.'
			),
		);
	}

	/**
	 * Build one definition record.
	 *
	 * @param string                            $operation Stable operation identifier.
	 * @param array<int, string>                $cli_path Relative command path below the root command.
	 * @param string                            $handler_class WP-CLI command class.
	 * @param string                            $handler_method Method or __invoke target.
	 * @param array<int, array<string, string>> $targets Potential execution targets.
	 * @param string                            $adapter Adapter callable.
	 * @param string                            $summary Short intent summary.
	 * @return array<string, mixed>
	 */
	private static function definition_item( string $operation, array $cli_path, string $handler_class, string $handler_method, array $targets, string $adapter, string $summary ): array {
		return array(
			'operation' => $operation,
			'cli_path'  => $cli_path,
			'handler'   => array(
				'class'  => $handler_class,
				'method' => $handler_method,
			),
			'targets'   => $targets,
			'adapter'   => $adapter,
			'summary'   => $summary,
		);
	}

	/**
	 * Normalize a command path so callers can pass raw shell segments or short paths.
	 *
	 * @param string|array<int, string> $path Raw command path.
	 * @return array<int, string>
	 */
	private static function normalize_path( $path ): array {
		if ( is_string( $path ) ) {
			$path = preg_split( '/\s+/', trim( $path ) );
			if ( false === $path ) {
				$path = array();
			}
		}

		$path = array_values(
			array_filter(
				array_map(
					static function ( string $segment ): string {
						return trim( $segment );
					},
					$path
				),
				static function ( string $segment ): bool {
					return '' !== $segment;
				}
			)
		);

		if ( isset( $path[0] ) && 'wp' === $path[0] ) {
			array_shift( $path );
		}

		if ( isset( $path[0] ) && Queuety::cli_command() === $path[0] ) {
			array_shift( $path );
		}

		return $path;
	}

	/**
	 * Collapse a path into the lookup key used throughout the catalog.
	 *
	 * @param array<int, string> $path Command path.
	 * @return string
	 */
	private static function path_key( array $path ): string {
		return implode( ' ', $path );
	}
}
