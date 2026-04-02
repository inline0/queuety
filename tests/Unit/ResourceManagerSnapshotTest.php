<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\ResourceManager;

class ResourceManagerSnapshotTest extends TestCase {

	public function test_system_memory_snapshot_prefers_cgroup_v2(): void {
		$manager  = $this->manager_with_files(
			array(
				'/sys/fs/cgroup/memory.max'     => '1048576',
				'/sys/fs/cgroup/memory.current' => '262144',
			)
		);
		$snapshot = $manager->system_memory_snapshot();

		$this->assertSame( 'cgroup-v2', $snapshot['source'] );
		$this->assertSame( 1024, $snapshot['limit_kb'] );
		$this->assertSame( 256, $snapshot['used_kb'] );
		$this->assertSame( 768, $snapshot['available_kb'] );
	}

	public function test_system_memory_snapshot_falls_back_to_cgroup_v1(): void {
		$manager  = $this->manager_with_files(
			array(
				'/sys/fs/cgroup/memory.max'                     => 'max',
				'/sys/fs/cgroup/memory.current'                 => '262144',
				'/sys/fs/cgroup/memory/memory.limit_in_bytes'   => '2097152',
				'/sys/fs/cgroup/memory/memory.usage_in_bytes'   => '524288',
			)
		);
		$snapshot = $manager->system_memory_snapshot();

		$this->assertSame( 'cgroup-v1', $snapshot['source'] );
		$this->assertSame( 2048, $snapshot['limit_kb'] );
		$this->assertSame( 512, $snapshot['used_kb'] );
		$this->assertSame( 1536, $snapshot['available_kb'] );
	}

	public function test_system_memory_snapshot_falls_back_to_proc_meminfo(): void {
		$manager  = $this->manager_with_files(
			array(
				'/sys/fs/cgroup/memory/memory.limit_in_bytes' => (string) ( 1 << 61 ),
				'/sys/fs/cgroup/memory/memory.usage_in_bytes' => '1024',
				'/proc/meminfo'                               => "MemTotal:       16384 kB\nMemAvailable:    4096 kB\n",
			)
		);
		$snapshot = $manager->system_memory_snapshot();

		$this->assertSame( 'proc-meminfo', $snapshot['source'] );
		$this->assertSame( 16384, $snapshot['limit_kb'] );
		$this->assertSame( 12288, $snapshot['used_kb'] );
		$this->assertSame( 4096, $snapshot['available_kb'] );
	}

	public function test_system_memory_snapshot_returns_null_when_no_source_is_available(): void {
		$manager = $this->manager_with_files( array() );

		$this->assertNull( $manager->system_memory_snapshot() );
	}

	private function manager_with_files( array $files ): ResourceManager {
		$conn = $this->getMockBuilder( Connection::class )
			->disableOriginalConstructor()
			->getMock();

		return new class( $conn, $files ) extends ResourceManager {
			public function __construct( Connection $conn, private array $files ) {
				parent::__construct( $conn );
			}

			protected function read_trimmed_file( string $path ): ?string {
				return $this->files[ $path ] ?? null;
			}
		};
	}
}
