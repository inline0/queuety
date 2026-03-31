<?php
/**
 * Argument adapters that keep CLI semantics and direct PHP execution aligned.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\ExpressionType;

/**
 * Turns parsed CLI arguments into stable execution plans for the public API.
 */
class CliCommandAdapters {

	/**
	 * Resolve worker execution.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function worker_run( array $args, array $assoc_args ): array {
		$queue   = self::optional_assoc( $assoc_args, 'queue' ) ?? 'default';
		$once    = self::flag( $assoc_args, 'once' );
		$workers = self::int_assoc( $assoc_args, 'workers', 0 );

		if ( $workers > 1 ) {
			return self::php_plan(
				Queuety::class . '::run_worker_pool',
				array( $workers, $queue )
			);
		}

		return self::php_plan(
			Queuety::class . '::run_worker',
			array( $queue, $once )
		);
	}

	/**
	 * Resolve queue flush.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function queue_flush( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::flush_queue',
			array( self::optional_assoc( $assoc_args, 'queue' ) ?? 'default' )
		);
	}

	/**
	 * Resolve job dispatch.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the handler argument is missing.
	 */
	public static function job_dispatch( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::dispatch_job',
			array(
				self::required_positional( $args, 0, 'handler' ),
				self::json_array_or_empty( $assoc_args, 'payload' ),
				self::optional_assoc( $assoc_args, 'queue' ) ?? 'default',
				self::int_assoc( $assoc_args, 'priority', 0 ),
				self::int_assoc( $assoc_args, 'delay', 0 ),
			)
		);
	}

	/**
	 * Resolve queue status.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function queue_status( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::stats',
			array( self::optional_assoc( $assoc_args, 'queue' ) )
		);
	}

	/**
	 * Resolve job listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function job_list( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::list_jobs',
			array(
				self::optional_assoc( $assoc_args, 'queue' ),
				self::optional_assoc( $assoc_args, 'status' ),
				50,
			)
		);
	}

	/**
	 * Resolve job retry.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the job ID is missing.
	 */
	public static function job_retry( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::retry',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve buried job retry.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function job_retry_buried( array $args, array $assoc_args ): array {
		return self::php_plan( Queuety::class . '::retry_buried', array() );
	}

	/**
	 * Resolve manual job burial.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the job ID is missing.
	 */
	public static function job_bury( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::bury_job',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				'Manually buried via CLI.',
			)
		);
	}

	/**
	 * Resolve job deletion.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the job ID is missing.
	 */
	public static function job_delete( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::delete_job',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve stale job recovery.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function job_recover( array $args, array $assoc_args ): array {
		return self::php_plan( Queuety::class . '::recover_stale_jobs', array() );
	}

	/**
	 * Resolve completed job purging.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function job_purge( array $args, array $assoc_args ): array {
		$older_than = array_key_exists( 'older-than', $assoc_args )
			? (int) $assoc_args['older-than']
			: null;

		return self::php_plan(
			Queuety::class . '::purge',
			array( $older_than )
		);
	}

	/**
	 * Resolve queue pause.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the queue name is missing.
	 */
	public static function queue_pause( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::pause',
			array( self::required_positional( $args, 0, 'queue' ) )
		);
	}

	/**
	 * Resolve queue resume.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the queue name is missing.
	 */
	public static function queue_resume( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::resume',
			array( self::required_positional( $args, 0, 'queue' ) )
		);
	}

	/**
	 * Resolve job inspection.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the job ID is missing.
	 */
	public static function job_inspect( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::inspect_job',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve handler metrics.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function metrics_handlers( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::handler_metrics',
			array( self::int_assoc( $assoc_args, 'minutes', 60 ) )
		);
	}

	/**
	 * Resolve handler discovery.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the directory or namespace is missing.
	 */
	public static function handler_discover( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::discover_handlers_cli',
			array(
				self::required_positional( $args, 0, 'directory' ),
				self::required_positional( $args, 1, 'namespace' ),
				self::flag( $assoc_args, 'register' ),
			)
		);
	}

	/**
	 * Resolve workflow status.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_status( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::workflow_status',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow retry.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_retry( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::retry_workflow',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow approval.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing or the payload is not a JSON array/object.
	 */
	public static function workflow_approve( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::approve_workflow',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::json_array_required( $assoc_args, 'data' ),
				self::optional_assoc( $assoc_args, 'signal' ) ?? 'approval',
			)
		);
	}

	/**
	 * Resolve workflow rejection.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing or the payload is not a JSON array/object.
	 */
	public static function workflow_reject( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::reject_workflow',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::json_array_required( $assoc_args, 'data' ),
				self::optional_assoc( $assoc_args, 'signal' ) ?? 'rejected',
			)
		);
	}

	/**
	 * Resolve workflow input submission.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing or the payload is not a JSON array/object.
	 */
	public static function workflow_input( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::submit_workflow_input',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::json_array_required( $assoc_args, 'data' ),
				self::optional_assoc( $assoc_args, 'signal' ) ?? 'input',
			)
		);
	}

	/**
	 * Resolve workflow artifact listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_artifacts( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::workflow_artifacts',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::flag( $assoc_args, 'with-content' ),
			)
		);
	}

	/**
	 * Resolve one workflow artifact read.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID or artifact key is missing.
	 */
	public static function workflow_artifact( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::workflow_artifact',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::required_positional( $args, 1, 'key' ),
			)
		);
	}

	/**
	 * Resolve workflow pause.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_pause( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::pause_workflow',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow cancellation.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_cancel( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::cancel_workflow',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow resume.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_resume( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::resume_workflow',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function workflow_list( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::list_workflows',
			array(
				self::optional_assoc( $assoc_args, 'status' ),
				self::int_assoc( $assoc_args, 'limit', 50 ),
			)
		);
	}

	/**
	 * Resolve workflow timeline reads.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_timeline( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::workflow_timeline',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				self::int_assoc( $assoc_args, 'limit', 100 ),
				self::int_assoc( $assoc_args, 'offset', 0 ),
			)
		);
	}

	/**
	 * Resolve workflow rewind.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID or step index is missing.
	 */
	public static function workflow_rewind( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::rewind_workflow',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				(int) self::required_positional( $args, 1, 'step' ),
			)
		);
	}

	/**
	 * Resolve workflow fork.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_fork( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::fork_workflow',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve workflow export.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID is missing.
	 */
	public static function workflow_export( array $args, array $assoc_args ): array {
		$workflow_id = (int) self::required_positional( $args, 0, 'id' );

		if ( array_key_exists( 'output', $assoc_args ) && null !== $assoc_args['output'] ) {
			return self::php_plan(
				Queuety::class . '::export_workflow_to_file',
				array( $workflow_id, (string) $assoc_args['output'] )
			);
		}

		return self::php_plan(
			Queuety::class . '::export_workflow',
			array( $workflow_id )
		);
	}

	/**
	 * Resolve workflow replay from file.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the file path is missing.
	 */
	public static function workflow_replay( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::replay_workflow_file',
			array( self::required_positional( $args, 0, 'file' ) )
		);
	}

	/**
	 * Resolve workflow state snapshot reads.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the workflow ID or step index is missing.
	 */
	public static function workflow_state_at( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::workflow_state_at',
			array(
				(int) self::required_positional( $args, 0, 'id' ),
				(int) self::required_positional( $args, 1, 'step' ),
			)
		);
	}

	/**
	 * Resolve log queries.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function log_query( array $args, array $assoc_args ): array {
		$filters = array(
			'job_id'      => array_key_exists( 'job', $assoc_args ) ? (int) $assoc_args['job'] : null,
			'workflow_id' => array_key_exists( 'workflow', $assoc_args ) ? (int) $assoc_args['workflow'] : null,
			'handler'     => self::optional_assoc( $assoc_args, 'handler' ),
			'event'       => self::optional_assoc( $assoc_args, 'event' ),
			'since'       => self::optional_assoc( $assoc_args, 'since' ),
			'limit'       => self::int_assoc( $assoc_args, 'limit', 50 ),
		);

		if (
			null === $filters['job_id']
			&& null === $filters['workflow_id']
			&& null === $filters['handler']
			&& null === $filters['event']
			&& null === $filters['since']
		) {
			$filters['since'] = '-24 hours';
		}

		return self::php_plan(
			Queuety::class . '::query_logs',
			array( $filters )
		);
	}

	/**
	 * Resolve log purge.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the required age flag is missing.
	 */
	public static function log_purge( array $args, array $assoc_args ): array {
		if ( ! array_key_exists( 'older-than', $assoc_args ) ) {
			throw new \InvalidArgumentException( 'Missing required associative argument: older-than' );
		}

		return self::php_plan(
			Queuety::class . '::purge_logs',
			array( (int) $assoc_args['older-than'] )
		);
	}

	/**
	 * Resolve schedule listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function schedule_list( array $args, array $assoc_args ): array {
		return self::php_plan( Queuety::class . '::list_schedules', array() );
	}

	/**
	 * Resolve schedule creation.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the handler is missing or neither expression flag is present.
	 */
	public static function schedule_add( array $args, array $assoc_args ): array {
		$expression = self::optional_assoc( $assoc_args, 'every' );
		$type       = ExpressionType::Interval->value;

		if ( null === $expression ) {
			$expression = self::optional_assoc( $assoc_args, 'cron' );
			$type       = ExpressionType::Cron->value;
		}

		if ( null === $expression || '' === $expression ) {
			throw new \InvalidArgumentException( 'You must specify either --every or --cron.' );
		}

		return self::php_plan(
			Queuety::class . '::add_schedule',
			array(
				self::required_positional( $args, 0, 'handler' ),
				self::json_array_or_empty( $assoc_args, 'payload' ),
				self::optional_assoc( $assoc_args, 'queue' ) ?? 'default',
				$expression,
				$type,
			)
		);
	}

	/**
	 * Resolve schedule removal.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the handler is missing.
	 */
	public static function schedule_remove( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::remove_schedule',
			array( self::required_positional( $args, 0, 'handler' ) )
		);
	}

	/**
	 * Resolve scheduler tick execution.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function schedule_run( array $args, array $assoc_args ): array {
		return self::php_plan( Queuety::class . '::run_scheduler', array() );
	}

	/**
	 * Resolve webhook registration.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the event or URL is missing.
	 */
	public static function webhook_add( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::register_webhook',
			array(
				self::required_positional( $args, 0, 'event' ),
				self::required_positional( $args, 1, 'url' ),
			)
		);
	}

	/**
	 * Resolve webhook listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function webhook_list( array $args, array $assoc_args ): array {
		return self::php_plan( Queuety::class . '::list_webhooks', array() );
	}

	/**
	 * Resolve webhook removal.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the webhook ID is missing.
	 */
	public static function webhook_remove( array $args, array $assoc_args ): array {
		return self::php_plan(
			Queuety::class . '::remove_webhook',
			array( (int) self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Create a PHP execution plan.
	 *
	 * @param string               $callable Public callable string.
	 * @param array<int, mixed>    $arguments Normalized callable arguments.
	 * @param array<string, mixed> $extra Optional metadata.
	 * @return array<string, mixed>
	 */
	private static function php_plan( string $callable, array $arguments, array $extra = array() ): array {
		return array_merge(
			array(
				'transport'            => 'php',
				'callable'             => $callable,
				'arguments'            => $arguments,
				'needs_confirmation'   => false,
				'confirmation_message' => null,
			),
			$extra
		);
	}

	/**
	 * Read one required positional argument.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param int                $index Argument index.
	 * @param string             $name Human-readable argument name.
	 * @return string
	 *
	 * @throws \InvalidArgumentException If the requested argument is missing or blank.
	 */
	private static function required_positional( array $args, int $index, string $name ): string {
		if ( ! array_key_exists( $index, $args ) || '' === trim( (string) $args[ $index ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Missing required positional argument: %s', $name ) );
		}

		return (string) $args[ $index ];
	}

	/**
	 * Read one optional associative argument as a string.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @return string|null
	 */
	private static function optional_assoc( array $assoc_args, string $name ): ?string {
		if ( ! array_key_exists( $name, $assoc_args ) || null === $assoc_args[ $name ] ) {
			return null;
		}

		return (string) $assoc_args[ $name ];
	}

	/**
	 * Normalize one integer-style associative argument.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @param int                  $default Default value.
	 * @return int
	 */
	private static function int_assoc( array $assoc_args, string $name, int $default ): int {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return $default;
		}

		return (int) $assoc_args[ $name ];
	}

	/**
	 * Normalize boolean flags without depending on WP-CLI internals.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Flag name.
	 * @return bool
	 */
	private static function flag( array $assoc_args, string $name ): bool {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return false;
		}

		$value = $assoc_args[ $name ];
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return true;
		}

		return ! in_array( strtolower( (string) $value ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Decode an optional JSON option into an array, falling back to an empty array.
	 *
	 * This mirrors permissive CLI behavior for payload flags that currently treat
	 * invalid or non-object JSON as an empty payload.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @return array
	 */
	private static function json_array_or_empty( array $assoc_args, string $name ): array {
		$value = self::optional_assoc( $assoc_args, $name );
		if ( null === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Decode a JSON option into an array payload.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @return array
	 *
	 * @throws \InvalidArgumentException If the value is not a JSON array/object.
	 */
	private static function json_array_required( array $assoc_args, string $name ): array {
		$value = self::optional_assoc( $assoc_args, $name );
		if ( null === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			throw new \InvalidArgumentException( sprintf( '--%s must be a JSON object.', $name ) );
		}

		return $decoded;
	}
}
