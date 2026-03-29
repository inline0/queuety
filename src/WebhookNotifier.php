<?php
/**
 * Webhook notification system.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Fires HTTP webhooks on job/workflow events.
 */
class WebhookNotifier {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Register a webhook URL for an event.
	 *
	 * @param string $event Event name (e.g. 'job.completed', 'job.failed', 'job.buried').
	 * @param string $url   URL to POST to.
	 * @return int The new webhook ID.
	 */
	public function register( string $event, string $url ): int {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (event, url) VALUES (:event, :url)"
		);
		$stmt->execute(
			array(
				'event' => $event,
				'url'   => $url,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Remove a webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 */
	public function remove( int $id ): void {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $id ) );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @return array Array of webhook rows.
	 */
	public function list(): array {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->query(
			"SELECT * FROM {$table} ORDER BY id ASC"
		);
		return $stmt->fetchAll();
	}

	/**
	 * Send POST notifications to all webhooks registered for a given event.
	 *
	 * This is non-blocking (fire-and-forget). Failures are silently ignored.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Payload data to send as JSON.
	 */
	public function notify( string $event, array $data ): void {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT url FROM {$table} WHERE event = :event"
		);
		$stmt->execute( array( 'event' => $event ) );
		$urls = array_column( $stmt->fetchAll(), 'url' );

		if ( empty( $urls ) ) {
			return;
		}

		$json = json_encode(
			array(
				'event'     => $event,
				'data'      => $data,
				'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			JSON_THROW_ON_ERROR
		);

		foreach ( $urls as $url ) {
			$this->fire_and_forget( $url, $json );
		}
	}

	/**
	 * Send a non-blocking HTTP POST request.
	 *
	 * Uses a very short timeout so the worker is not blocked.
	 * Catches and ignores all failures.
	 *
	 * @param string $url  Target URL.
	 * @param string $json JSON body.
	 */
	private function fire_and_forget( string $url, string $json ): void {
		try {
			$context = stream_context_create(
				array(
					'http' => array(
						'method'  => 'POST',
						'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen( $json ) . "\r\n",
						'content' => $json,
						'timeout' => 1,
					),
				)
			);
			@file_get_contents( $url, false, $context );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}
}
