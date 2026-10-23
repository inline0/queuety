<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\DatabaseFailureGuard;
use Queuety\Exceptions\CommitOutcomeUnknownException;

class DatabaseFailureGuardTest extends TestCase {

	public function test_unknown_commit_discards_once_and_preserves_original_error(): void {
		$discarded = 0;
		$guard = new DatabaseFailureGuard( static function () use ( &$discarded ): void { ++$discarded; } );
		$error = new \PDOException( 'Acknowledgement lost', 2013 );
		try {
			$guard->handle( $error, true );
			$this->fail( 'Unknown transaction outcome must throw.' );
		} catch ( CommitOutcomeUnknownException $unknown ) {
			$this->assertSame( $error, $unknown->getPrevious() );
		}
		$this->assertSame( 1, $discarded );
		$this->assertTrue( $guard->failed() );
		$this->expectException( \RuntimeException::class );
		$guard->check();
	}

	public function test_regular_lock_timeout_can_be_rolled_back_on_existing_connection(): void {
		$guard = new DatabaseFailureGuard();
		$error = new \PDOException( 'Lock wait timeout', 1205 );
		try {
			$guard->handle( $error );
		} catch ( \PDOException $caught ) {
			$this->assertSame( $error, $caught );
		}
		$this->assertFalse( $guard->failed() );
		$guard->check();
	}
}
