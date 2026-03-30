<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class ActionWorkflowBridgeTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow_mgr = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry     = new HandlerRegistry();
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			$this->registry,
			new Config(),
		);

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	private function registered_action_callback( string $hook ): callable {
		global $_queuety_test_actions;

		foreach ( $_queuety_test_actions ?? array() as $action ) {
			if ( $hook === ( $action['hook'] ?? null ) ) {
				return $action['callback'];
			}
		}

		throw new \RuntimeException( "Action {$hook} was not registered." );
	}

	private function workflow_row( int $workflow_id ): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( "Workflow {$workflow_id} was not found." );
		}

		return $row;
	}

	public function test_on_action_dispatches_registered_template_with_runtime_metadata(): void {
		$builder = Queuety::define_workflow( 'content_review' )
			->version( 'content-review.v2' )
			->max_transitions( 5 )
			->prune_state_after( 1 )
			->must_complete_within( minutes: 5 )
			->then( DataFetchStep::class )
			->then( AccumulatingStep::class );

		Queuety::register_workflow_template( $builder );

		Queuety::on_action(
			'save_post',
			'content_review',
			map: static fn( int $post_id, object $post, bool $update ): array => array(
				'user_id'   => $post_id,
				'post_type' => $post->post_type,
				'update'    => $update,
			),
			when: static fn( int $post_id, object $post ): bool => 'post' === $post->post_type,
			idempotency_key: static fn( int $post_id ): string => "save_post:{$post_id}",
		);

		$callback = $this->registered_action_callback( 'save_post' );
		$callback( 42, (object) array( 'post_type' => 'post' ), true );
		$callback( 42, (object) array( 'post_type' => 'post' ), true );

		$workflows = Queuety::list_workflows();
		$this->assertCount( 1, $workflows );

		$workflow_id = $workflows[0]->workflow_id;
		$this->assertSame( 'content-review.v2', $workflows[0]->definition_version );
		$this->assertSame( 'save_post:42', $workflows[0]->idempotency_key );
		$this->assertSame( 5, $workflows[0]->budget['max_transitions'] );

		$row   = $this->workflow_row( $workflow_id );
		$state = json_decode( $row['state'], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 1, $state['_prune_state_after'] );
		$this->assertNotNull( $row['deadline_at'] );

		$this->worker->flush();

		$status = $this->workflow_mgr->status( $workflow_id );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 1, $status->state['counter'] );
		$this->assertSame( 'post', $status->state['post_type'] );
		$this->assertTrue( $status->state['update'] );
	}

	public function test_on_action_skips_dispatch_when_guard_returns_false(): void {
		Queuety::register_workflow_template(
			Queuety::define_workflow( 'guarded_review' )
				->then( DataFetchStep::class )
		);

		Queuety::on_action(
			'save_post',
			'guarded_review',
			map: static fn( int $post_id, object $post ): array => array(
				'user_id'   => $post_id,
				'post_type' => $post->post_type,
			),
			when: static fn( int $post_id, object $post ): bool => 'post' === $post->post_type,
		);

		$callback = $this->registered_action_callback( 'save_post' );
		$callback( 42, (object) array( 'post_type' => 'page' ), true );

		$this->assertCount( 0, Queuety::list_workflows() );
	}

	public function test_on_action_dispatches_inline_builder_with_runtime_idempotency(): void {
		$builder = Queuety::workflow( 'inline_comment_review' )
			->then( AccumulatingStep::class );

		Queuety::on_action(
			'comment_post',
			$builder,
			map: static fn( int $comment_id ): array => array(
				'comment_id' => $comment_id,
				'counter'    => 41,
			),
			idempotency_key: static fn( int $comment_id ): string => "comment:{$comment_id}",
		);

		$callback = $this->registered_action_callback( 'comment_post' );
		$callback( 9 );
		$callback( 9 );

		$workflows = Queuety::list_workflows();
		$this->assertCount( 1, $workflows );
		$this->assertSame( 'comment:9', $workflows[0]->idempotency_key );

		$this->worker->flush();

		$status = $this->workflow_mgr->status( $workflows[0]->workflow_id );
		$this->assertSame( 42, $status->state['counter'] );
		$this->assertSame( 9, $status->state['comment_id'] );
	}
}
