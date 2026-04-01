<?php
/**
 * Resource-aware admission and concurrency policies.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Cache;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;

/**
 * Resolves handler resource profiles and enforces coarse admission policies.
 */
class ResourceManager {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn  Database connection.
	 * @param Cache|null $cache Optional cache backend for short-lived handler profiles.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly ?Cache $cache = null,
	) {}

	/**
	 * Count active processing jobs in one concurrency group.
	 *
	 * @param string   $group          Group name.
	 * @param int|null $exclude_job_id Optional job ID to exclude from the count.
	 * @return int
	 */
	public function active_group_count( string $group, ?int $exclude_job_id = null ): int {
		$table  = $this->conn->table( Config::table_jobs() );
		$params = array(
			'group'  => $group,
			'status' => JobStatus::Processing->value,
		);

		$sql = "SELECT COUNT(*) FROM {$table}
			WHERE concurrency_group = :group
				AND status = :status";

		if ( null !== $exclude_job_id ) {
			$sql                     .= ' AND id != :exclude_job_id';
			$params['exclude_job_id'] = $exclude_job_id;
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Load a short-lived observed profile for one handler.
	 *
	 * @param string $handler Handler alias or class.
	 * @return array{
	 *     sample_count: int,
	 *     avg_duration_ms: int|null,
	 *     max_memory_peak_kb: int|null
	 * }
	 */
	public function handler_profile( string $handler ): array {
		$cache_key = "queuety:resource_profile:{$handler}";
		if ( null !== $this->cache ) {
			$cached = $this->cache->get( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$table = $this->conn->table( Config::table_logs() );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( Config::resource_profile_window_minutes() * 60 ) );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT
				COUNT(*) AS sample_count,
				AVG(duration_ms) AS avg_duration_ms,
				MAX(memory_peak_kb) AS max_memory_peak_kb
			FROM {$table}
			WHERE handler = :handler
				AND event = :event
				AND created_at >= :since"
		);
		$stmt->execute(
			array(
				'handler' => $handler,
				'event'   => LogEvent::Completed->value,
				'since'   => $since,
			)
		);

		$row     = $stmt->fetch() ?: array();
		$profile = array(
			'sample_count'       => (int) ( $row['sample_count'] ?? 0 ),
			'avg_duration_ms'    => isset( $row['avg_duration_ms'] ) ? (int) round( (float) $row['avg_duration_ms'] ) : null,
			'max_memory_peak_kb' => isset( $row['max_memory_peak_kb'] ) ? (int) $row['max_memory_peak_kb'] : null,
		);

		if ( null !== $this->cache ) {
			$this->cache->set( $cache_key, $profile, Config::resource_profile_ttl_seconds() );
		}

		return $profile;
	}

	/**
	 * Decide whether one claimed job should be admitted to execution.
	 *
	 * @param Job  $job                 Claimed job.
	 * @param bool $respect_time_budget Whether to apply a request-style time budget.
	 * @param int  $elapsed_ms          Elapsed loop time in milliseconds.
	 * @return array{
	 *     allowed: bool,
	 *     reason: string|null,
	 *     retry_after: int,
	 *     active_group_count: int|null,
	 *     profile: array{sample_count: int, avg_duration_ms: int|null, max_memory_peak_kb: int|null}|null
	 * }
	 */
	public function admit( Job $job, bool $respect_time_budget = false, int $elapsed_ms = 0 ): array {
		$decision = array(
			'allowed'            => true,
			'reason'             => null,
			'retry_after'        => Config::worker_sleep(),
			'active_group_count' => null,
			'profile'            => null,
		);

		if ( null !== $job->concurrency_group && null !== $job->concurrency_limit ) {
			$active_count                   = $this->active_group_count( $job->concurrency_group, $job->id );
			$decision['active_group_count'] = $active_count;
			if ( $active_count >= $job->concurrency_limit ) {
				$decision['allowed'] = false;
				$decision['reason']  = sprintf(
					"Concurrency group '%s' is saturated (%d/%d).",
					$job->concurrency_group,
					$active_count,
					$job->concurrency_limit
				);
				return $decision;
			}
		}

		if ( ! Config::resource_admission_enabled() ) {
			return $decision;
		}

		$profile             = $this->handler_profile( $job->handler );
		$decision['profile'] = $profile;

		if ( $profile['sample_count'] < 1 ) {
			return $decision;
		}

		$max_memory_kb = Config::worker_max_memory() * 1024;
		$current_kb    = (int) ( memory_get_usage( true ) / 1024 );
		$headroom_kb   = Config::resource_memory_headroom_mb() * 1024;
		$observed_peak = (int) ( $profile['max_memory_peak_kb'] ?? 0 );

		if ( $observed_peak > 0 && $current_kb + $observed_peak + $headroom_kb > $max_memory_kb ) {
			$decision['allowed']     = false;
			$decision['reason']      = sprintf(
				'Projected memory use for %s exceeds the worker memory headroom.',
				$job->handler
			);
			$decision['retry_after'] = max( 1, Config::worker_sleep() );
			return $decision;
		}

		if ( $respect_time_budget ) {
			$projected_duration_ms = (int) ( $profile['avg_duration_ms'] ?? 0 );
			$max_window_ms         = Config::max_execution_time() * 1000;
			$headroom_ms           = Config::resource_time_headroom_ms();

			if ( $projected_duration_ms > 0 && $elapsed_ms + $projected_duration_ms + $headroom_ms > $max_window_ms ) {
				$decision['allowed']     = false;
				$decision['reason']      = sprintf(
					'Projected duration for %s exceeds the once-run time budget.',
					$job->handler
				);
				$decision['retry_after'] = max( 1, Config::worker_sleep() );
			}
		}

		return $decision;
	}
}
