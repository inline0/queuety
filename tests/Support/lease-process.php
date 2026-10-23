<?php

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Queuety\Connection;
use Queuety\ExecutionContext;
use Queuety\Heartbeat;
use Queuety\Queue;

$config = json_decode( fgets( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$conn   = new Connection( ...$config );
$queue  = new Queue( $conn );
$job    = null;

while ( false !== ( $line = fgets( STDIN ) ) ) {
	$request = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
	if ( 'quit' === $request['operation'] ) {
		break;
	}
	if ( ! empty( $request['acknowledge'] ) ) {
		echo "{\"ready\":true}\n";
		flush();
	}
	try {
		$value = null;
		switch ( $request['operation'] ) {
			case 'claim':
				$job = $queue->claim( $request['queue'] ?? 'default', $request['lease_seconds'] ?? 60, 60 );
				if ( null !== $job ) {
					Heartbeat::init( $job->id, $conn, $job->claim_token, 60 );
					ExecutionContext::enter( $job->id, job: $job );
					$value = array( 'id' => $job->id, 'token' => $job->claim_token, 'revision' => $job->revision, 'attempts' => $job->attempts );
				}
				break;
			case 'observe':
				$job = $queue->find( $request['id'] );
				$value = $job?->id;
				break;
			case 'dispatch':
				$value = $queue->dispatch( 'handler', $request['payload'] ?? array(), unique: $request['unique'] ?? false, dispatch_key: $request['key'] ?? null );
				break;
			case 'heartbeat':
				Heartbeat::beat( array( 'owner' => getmypid() ) );
				break;
			case 'complete':
				$queue->complete( $job->id, $job->claim_token );
				break;
			case 'fail':
				$queue->fail( $job->id, 'stale failure', $job->claim_token );
				break;
			case 'bury':
				$queue->bury( $job->id, 'stale burial', $job->claim_token );
				break;
			case 'retry':
				$queue->retry( $job->id, 0, $job->claim_token );
				break;
			case 'unclaim':
				$queue->unclaim( $job->id, $job->claim_token );
				break;
			case 'release':
				$queue->release( $job->id, $job->claim_token );
				break;
			case 'reschedule':
				$queue->reschedule( $job->id, new DateTimeImmutable( '+1 minute' ), false, $job->claim_token );
				break;
			case 'recover':
				$value = $queue->recover( $job );
				break;
			default:
				throw new RuntimeException( 'Unknown subprocess operation.' );
		}
		echo json_encode( array( 'value' => $value, 'pid' => getmypid(), 'wordpress' => function_exists( 'add_action' ) ), JSON_THROW_ON_ERROR ) . "\n";
	} catch ( Throwable $error ) {
		echo json_encode( array( 'error' => $error::class, 'message' => $error->getMessage(), 'pid' => getmypid() ), JSON_THROW_ON_ERROR ) . "\n";
	}
	flush();
}
