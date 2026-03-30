<?php

namespace Queuety\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for Queuety tests.
 */
class QueuetyTestCase extends TestCase {

	/**
	 * Per-test temporary directory.
	 *
	 * @var string
	 */
	protected string $tmp_dir;

	protected function setUp(): void {
		parent::setUp();
		global $_queuety_test_actions;
		$_queuety_test_actions = array();
		$this->tmp_dir = QUEUETY_TEST_TMPDIR . '/' . uniqid( 'test-', true );
		mkdir( $this->tmp_dir, 0755, true );
	}

	protected function tearDown(): void {
		global $_queuety_test_actions;

		if ( is_dir( $this->tmp_dir ) ) {
			$it    = new \RecursiveDirectoryIterator( $this->tmp_dir, \RecursiveDirectoryIterator::SKIP_DOTS );
			$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getRealPath() );
				} else {
					unlink( $file->getRealPath() );
				}
			}
			rmdir( $this->tmp_dir );
		}
		$_queuety_test_actions = array();
		parent::tearDown();
	}
}
