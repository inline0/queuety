<?php
/**
 * CronBridge unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\CronBridge;

/**
 * Tests for CronBridge structure and method signatures.
 *
 * WordPress is not loaded during unit tests, so we verify the class
 * structure and behaviour when WP functions are unavailable.
 */
class CronBridgeTest extends TestCase {

	protected function tearDown(): void {
		// Ensure uninstall is called to reset static state.
		CronBridge::uninstall();
		parent::tearDown();
	}

	public function test_install_method_exists(): void {
		$this->assertTrue( method_exists( CronBridge::class, 'install' ) );
	}

	public function test_uninstall_method_exists(): void {
		$this->assertTrue( method_exists( CronBridge::class, 'uninstall' ) );
	}

	public function test_process_cron_event_method_exists(): void {
		$this->assertTrue( method_exists( CronBridge::class, 'process_cron_event' ) );
	}

	public function test_is_installed_method_exists(): void {
		$this->assertTrue( method_exists( CronBridge::class, 'is_installed' ) );
	}

	public function test_install_without_wordpress_does_nothing(): void {
		// When WordPress is not loaded, add_filter does not exist.
		// install() should return silently.
		CronBridge::install();
		$this->assertFalse( CronBridge::is_installed() );
	}

	public function test_uninstall_without_install_does_nothing(): void {
		CronBridge::uninstall();
		$this->assertFalse( CronBridge::is_installed() );
	}

	public function test_process_cron_event_without_wordpress_does_nothing(): void {
		// Should not throw when do_action is not available.
		CronBridge::process_cron_event( 'my_hook', array( 'arg1' ) );
		$this->assertTrue( true ); // No exception thrown.
	}

	public function test_intercept_schedule_event_returns_null_for_null_event(): void {
		$result = CronBridge::intercept_schedule_event( null, (object) array() );
		$this->assertNull( $result );
	}

	public function test_intercept_schedule_event_passes_through_non_null_pre(): void {
		$result = CronBridge::intercept_schedule_event( true, (object) array( 'hook' => 'test' ) );
		$this->assertTrue( $result );
	}

	public function test_intercept_unschedule_event_passes_through_non_null_pre(): void {
		$result = CronBridge::intercept_unschedule_event( false, time(), 'test', array() );
		$this->assertFalse( $result );
	}

	public function test_intercept_get_scheduled_event_passes_through_non_null_pre(): void {
		$fake = (object) array( 'hook' => 'test' );
		$result = CronBridge::intercept_get_scheduled_event( $fake, 'test', array(), null );
		$this->assertSame( $fake, $result );
	}
}
