<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Queuety;

class ActionWorkflowBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Queuety::reset();
		global $_queuety_test_actions;
		$_queuety_test_actions = array();
	}

	protected function tearDown(): void {
		global $_queuety_test_actions;
		$_queuety_test_actions = array();
		Queuety::reset();
		parent::tearDown();
	}

	private function registered_actions(): array {
		global $_queuety_test_actions;
		return $_queuety_test_actions ?? array();
	}

	public function test_on_action_registers_wordpress_callback_with_inferred_accepted_args(): void {
		Queuety::on_action(
			'save_post',
			'content_review',
			map: static fn( int $post_id, object $post, bool $update ): array => array(
				'post_id'   => $post_id,
				'post_type' => $post->post_type,
				'update'    => $update,
			),
			idempotency_key: static fn( int $post_id ): string => "save_post:{$post_id}",
		);

		$actions = $this->registered_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'save_post', $actions[0]['hook'] );
		$this->assertSame( 10, $actions[0]['priority'] );
		$this->assertSame( 3, $actions[0]['accepted_args'] );
		$this->assertIsCallable( $actions[0]['callback'] );
	}

	public function test_on_action_allows_explicit_accepted_args_override(): void {
		Queuety::on_action(
			'comment_post',
			'comment_review',
			map: static fn( int $comment_id, bool $approved ): array => array(
				'comment_id' => $comment_id,
				'approved'   => $approved,
			),
			accepted_args: 1,
		);

		$actions = $this->registered_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 1, $actions[0]['accepted_args'] );
	}

	public function test_on_action_rejects_empty_hook_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Action hook name cannot be empty.' );

		Queuety::on_action( '   ', 'content_review' );
	}
}
