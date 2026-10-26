<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;

/**
 * Deactivation removes the runtime state and keeps everything a reactivation or a reinstall needs.
 *
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::on_deactivate
 */
final class DeactivationTest extends TestCase {

	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['stonewright_test_is_multisite'] );
		StorageRig::reset_globals();
	}

	private function deactivate( ?bool $network_wide = null ): void {
		$registration = ( new ReflectionClass( PluginRegistration::class ) )->newInstanceWithoutConstructor();
		if ( null === $network_wide ) {
			$registration->on_deactivate();
			return;
		}
		$registration->on_deactivate( $network_wide );
	}

	public function test_deactivation_clears_runtime_state_only(): void {
		$wpdb            = UninstallSite::reset();
		$GLOBALS['wpdb'] = $wpdb;
		$tables          = $wpdb->tables;
		$kept            = array_diff_key( $GLOBALS['stonewright_test_options'], array_flip( UninstallSite::RUNTIME_OPTIONS ) );

		$this->deactivate();

		self::assertSame( [], array_intersect( UninstallSite::RUNTIME_OPTIONS, array_keys( $GLOBALS['stonewright_test_options'] ) ), 'the runtime state is gone' );
		self::assertSame( $kept, $GLOBALS['stonewright_test_options'], 'settings, keys, schema versions and every other option stay' );
		self::assertSame( $tables, $wpdb->tables, 'no table is dropped' );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ), 'the events are unscheduled as before and the others stay' );
	}

	public function test_a_network_wide_deactivation_clears_every_site(): void {
		UninstallSite::reset( 1, 2, 3 );
		$GLOBALS['wpdb']                          = new \Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallWpdb();
		$GLOBALS['stonewright_test_is_multisite'] = true;

		$this->deactivate( true );

		foreach ( [ 1, 2, 3 ] as $site ) {
			self::assertSame( [], array_intersect( UninstallSite::RUNTIME_OPTIONS, array_keys( UninstallSite::options( $site ) ) ), 'site ' . $site );
			self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( UninstallSite::hooks( $site ) ), 'events of site ' . $site );
		}
		self::assertSame( 1, UninstallSite::$current, 'each switch is undone' );
	}

	public function test_a_single_site_deactivation_on_a_network_clears_that_site_only(): void {
		UninstallSite::reset( 1, 2 );
		$GLOBALS['wpdb']                          = new \Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallWpdb();
		$GLOBALS['stonewright_test_is_multisite'] = true;

		$this->deactivate( false );

		self::assertSame( [], array_intersect( UninstallSite::RUNTIME_OPTIONS, array_keys( UninstallSite::options( 1 ) ) ) );
		self::assertSame( UninstallSite::RUNTIME_OPTIONS, array_values( array_intersect( UninstallSite::RUNTIME_OPTIONS, array_keys( UninstallSite::options( 2 ) ) ) ), 'the other site is not this site\'s to clean' );
		self::assertSame( UninstallSite::PLUGIN_HOOKS, array_values( array_intersect( UninstallSite::PLUGIN_HOOKS, array_keys( UninstallSite::hooks( 2 ) ) ) ), 'nor are its events' );
	}
}
