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
	 * Sum active processing cost units inside one concurrency group.
	 *
	 * @param string   $group          Group name.
	 * @param int|null $exclude_job_id Optional job ID to exclude.
	 * @return int
	 */
	public function active_group_cost_units( string $group, ?int $exclude_job_id = null ): int {
		$table  = $this->conn->table( Config::table_jobs() );
		$params = array(
			'group'  => $group,
			'status' => JobStatus::Processing->value,
		);

		$sql = "SELECT COALESCE(SUM(cost_units), 0) FROM {$table}
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
	 * Sum active processing cost units inside one queue.
	 *
	 * @param string   $queue          Queue name.
	 * @param int|null $exclude_job_id Optional job ID to exclude.
	 * @return int
	 */
	public function active_queue_cost_units( string $queue, ?int $exclude_job_id = null ): int {
		$table  = $this->conn->table( Config::table_jobs() );
		$params = array(
			'queue'  => $queue,
			'status' => JobStatus::Processing->value,
		);

		$sql = "SELECT COALESCE(SUM(cost_units), 0) FROM {$table}
			WHERE queue = :queue
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
	 * Read one best-effort memory snapshot from the current container or host.
	 *
	 * @return array{
	 *     source: string,
	 *     limit_kb: int|null,
	 *     used_kb: int|null,
	 *     available_kb: int|null
	 * }|null
	 */
	public function system_memory_snapshot(): ?array {
		$snapshot = $this->read_cgroup_v2_memory_snapshot();
		if ( null !== $snapshot ) {
			return $snapshot;
		}

		$snapshot = $this->read_cgroup_v1_memory_snapshot();
		if ( null !== $snapshot ) {
			return $snapshot;
		}

		return $this->read_proc_meminfo_snapshot();
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
	 *     active_queue_cost_units: int|null,
	 *     queue_cost_budget: int|null,
	 *     active_group_cost_units: int|null,
	 *     group_cost_budget: int|null,
	 *     profile: array{sample_count: int, avg_duration_ms: int|null, max_memory_peak_kb: int|null}|null,
	 *     system_memory: array{
	 *         source: string,
	 *         limit_kb: int|null,
	 *         used_kb: int|null,
	 *         available_kb: int|null
	 *     }|null
	 * }
	 */
	public function admit( Job $job, bool $respect_time_budget = false, int $elapsed_ms = 0 ): array {
		$decision = array(
			'allowed'                 => true,
			'reason'                  => null,
			'retry_after'             => Config::worker_sleep(),
			'active_group_count'      => null,
			'active_queue_cost_units' => null,
			'queue_cost_budget'       => null,
			'active_group_cost_units' => null,
			'group_cost_budget'       => null,
			'profile'                 => null,
			'system_memory'           => null,
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

		$queue_budget = Config::resource_queue_cost_budgets()[ $job->queue ] ?? null;
		if ( null !== $queue_budget && $job->cost_units > 0 ) {
			$active_queue_cost_units             = $this->active_queue_cost_units( $job->queue, $job->id );
			$decision['active_queue_cost_units'] = $active_queue_cost_units;
			$decision['queue_cost_budget']       = $queue_budget;

			if ( $active_queue_cost_units + $job->cost_units > $queue_budget ) {
				$decision['allowed'] = false;
				$decision['reason']  = sprintf(
					"Queue '%s' cost budget is exhausted (%d/%d).",
					$job->queue,
					$active_queue_cost_units,
					$queue_budget
				);
				return $decision;
			}
		}

		if ( null !== $job->concurrency_group ) {
			$group_budget = Config::resource_group_cost_budgets()[ $job->concurrency_group ] ?? null;
			if ( null !== $group_budget && $job->cost_units > 0 ) {
				$active_group_cost_units             = $this->active_group_cost_units( $job->concurrency_group, $job->id );
				$decision['active_group_cost_units'] = $active_group_cost_units;
				$decision['group_cost_budget']       = $group_budget;

				if ( $active_group_cost_units + $job->cost_units > $group_budget ) {
					$decision['allowed'] = false;
					$decision['reason']  = sprintf(
						"Concurrency group '%s' cost budget is exhausted (%d/%d).",
						$job->concurrency_group,
						$active_group_cost_units,
						$group_budget
					);
					return $decision;
				}
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
		$current_kb    = $this->current_process_memory_kb();
		$headroom_kb   = Config::resource_memory_headroom_mb() * 1024;
		$observed_peak = (int) ( $profile['max_memory_peak_kb'] ?? 0 );

		if ( Config::resource_system_memory_awareness_enabled() ) {
			$decision['system_memory'] = $this->system_memory_snapshot();
		}

		if ( $observed_peak > 0 && null !== $decision['system_memory'] ) {
			$available_kb       = (int) ( $decision['system_memory']['available_kb'] ?? 0 );
			$system_headroom_kb = Config::resource_system_memory_headroom_mb() * 1024;

			if ( $available_kb > 0 && $observed_peak + $system_headroom_kb > $available_kb ) {
				$decision['allowed']     = false;
				$decision['reason']      = sprintf(
					'Projected memory use for %s exceeds the available system memory headroom.',
					$job->handler
				);
				$decision['retry_after'] = max( 1, Config::worker_sleep() );
				return $decision;
			}
		}

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

	/**
	 * Read the current process memory footprint in KB.
	 *
	 * @return int
	 */
	protected function current_process_memory_kb(): int {
		return (int) ( memory_get_usage( true ) / 1024 );
	}

	/**
	 * Read a cgroup v2 memory snapshot when available.
	 *
	 * @return array{source: string, limit_kb: int|null, used_kb: int|null, available_kb: int|null}|null
	 */
	private function read_cgroup_v2_memory_snapshot(): ?array {
		$limit   = $this->read_trimmed_file( '/sys/fs/cgroup/memory.max' );
		$current = $this->read_trimmed_file( '/sys/fs/cgroup/memory.current' );

		if ( null === $limit || null === $current || 'max' === $limit ) {
			return null;
		}

		$limit_bytes   = $this->normalize_memory_bytes( $limit );
		$current_bytes = $this->normalize_memory_bytes( $current );
		if ( null === $limit_bytes || null === $current_bytes ) {
			return null;
		}

		return $this->memory_snapshot_from_bytes( 'cgroup-v2', $limit_bytes, $current_bytes );
	}

	/**
	 * Read a cgroup v1 memory snapshot when available.
	 *
	 * @return array{source: string, limit_kb: int|null, used_kb: int|null, available_kb: int|null}|null
	 */
	private function read_cgroup_v1_memory_snapshot(): ?array {
		$limit   = $this->read_trimmed_file( '/sys/fs/cgroup/memory/memory.limit_in_bytes' );
		$current = $this->read_trimmed_file( '/sys/fs/cgroup/memory/memory.usage_in_bytes' );

		if ( null === $limit || null === $current ) {
			return null;
		}

		$limit_bytes   = $this->normalize_memory_bytes( $limit );
		$current_bytes = $this->normalize_memory_bytes( $current );
		if ( null === $limit_bytes || null === $current_bytes ) {
			return null;
		}

		if ( $limit_bytes > ( 1 << 60 ) ) {
			return null;
		}

		return $this->memory_snapshot_from_bytes( 'cgroup-v1', $limit_bytes, $current_bytes );
	}

	/**
	 * Read one `/proc/meminfo` memory snapshot when available.
	 *
	 * @return array{source: string, limit_kb: int|null, used_kb: int|null, available_kb: int|null}|null
	 */
	private function read_proc_meminfo_snapshot(): ?array {
		$contents = $this->read_trimmed_file( '/proc/meminfo' );
		if ( null === $contents ) {
			return null;
		}

		$info = array();
		foreach ( preg_split( '/\r?\n/', $contents ) ?: array() as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_]+):\s+([0-9]+)\s+kB$/', trim( $line ), $matches ) ) {
				continue;
			}

			$info[ $matches[1] ] = (int) $matches[2];
		}

		$total_kb     = $info['MemTotal'] ?? null;
		$available_kb = $info['MemAvailable'] ?? ( $info['MemFree'] ?? null );
		if ( null === $total_kb || null === $available_kb ) {
			return null;
		}

		return array(
			'source'       => 'proc-meminfo',
			'limit_kb'     => $total_kb,
			'used_kb'      => max( 0, $total_kb - $available_kb ),
			'available_kb' => $available_kb,
		);
	}

	/**
	 * Turn one byte-based memory pair into the public snapshot shape.
	 *
	 * @param string $source        Snapshot source.
	 * @param int    $limit_bytes   Memory limit in bytes.
	 * @param int    $current_bytes Current memory use in bytes.
	 * @return array{source: string, limit_kb: int|null, used_kb: int|null, available_kb: int|null}
	 */
	private function memory_snapshot_from_bytes( string $source, int $limit_bytes, int $current_bytes ): array {
		$limit_kb     = (int) floor( $limit_bytes / 1024 );
		$used_kb      = (int) floor( $current_bytes / 1024 );
		$available_kb = max( 0, $limit_kb - $used_kb );

		return array(
			'source'       => $source,
			'limit_kb'     => $limit_kb,
			'used_kb'      => $used_kb,
			'available_kb' => $available_kb,
		);
	}

	/**
	 * Read one small file and return the trimmed contents.
	 *
	 * @param string $path Filesystem path.
	 * @return string|null
	 */
	private function read_trimmed_file( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local cgroup or proc files, never remote URLs.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}

		return trim( $contents );
	}

	/**
	 * Normalize one memory byte string to an integer.
	 *
	 * @param string $value Raw byte string.
	 * @return int|null
	 */
	private function normalize_memory_bytes( string $value ): ?int {
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
