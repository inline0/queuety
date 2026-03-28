<?php
/**
 * WorkflowLoader unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use Queuety\Tests\QueuetyTestCase;
use Queuety\WorkflowLoader;

class WorkflowLoaderTest extends QueuetyTestCase {

	public function test_load_throws_for_nonexistent_directory(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not exist' );
		WorkflowLoader::load( $this->tmp_dir . '/nonexistent' );
	}

	public function test_load_returns_zero_for_empty_directory(): void {
		$dir = $this->tmp_dir . '/empty_workflows';
		mkdir( $dir );

		$count = WorkflowLoader::load( $dir );
		$this->assertSame( 0, $count );
	}

	public function test_load_skips_non_php_files(): void {
		$dir = $this->tmp_dir . '/mixed_files';
		mkdir( $dir );
		file_put_contents( $dir . '/readme.txt', 'not a workflow' );
		file_put_contents( $dir . '/data.json', '{}' );

		$count = WorkflowLoader::load( $dir );
		$this->assertSame( 0, $count );
	}

	public function test_load_skips_files_not_returning_builder(): void {
		$dir = $this->tmp_dir . '/bad_workflows';
		mkdir( $dir );
		file_put_contents( $dir . '/not-a-workflow.php', '<?php return "hello";' );
		file_put_contents( $dir . '/returns-null.php', '<?php return null;' );

		$count = WorkflowLoader::load( $dir );
		$this->assertSame( 0, $count );
	}

	public function test_load_file_returns_null_for_unreadable(): void {
		$result = WorkflowLoader::load_file( $this->tmp_dir . '/nonexistent.php' );
		$this->assertNull( $result );
	}
}
