<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Expertise;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Expertise\IntegrationCatalog;

/**
 * A plugin that is active for the whole network is detected like one that is active on the site.
 *
 * @covers \Stonewright\WpMcp\Expertise\IntegrationCatalog
 */
final class IntegrationCatalogNetworkActiveTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $id ): array {
		foreach ( IntegrationCatalog::inspect() as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		self::fail( 'No row for ' . $id );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_site_options'] = [];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_network_activated_plugin_is_detected(): void {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/sw-no-plugins' );
		$GLOBALS['stonewright_test_options']      = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_site_options'] = [ 'active_sitewide_plugins' => [ 'meta-box/meta-box.php' => 1700000000 ] ];

		$row = self::row( 'meta-box' );

		self::assertSame( 'active', $row['version'] );
		self::assertSame( 'discovery-only', $row['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_plugin_that_is_active_nowhere_is_still_unavailable(): void {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/sw-no-plugins' );
		$GLOBALS['stonewright_test_options']      = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_site_options'] = [ 'active_sitewide_plugins' => [ 'other/other.php' => 1700000000 ] ];

		self::assertSame( 'unavailable', self::row( 'meta-box' )['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_plugin_that_is_active_on_the_site_is_still_detected(): void {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/sw-no-plugins' );
		$GLOBALS['stonewright_test_options']      = [ 'active_plugins' => [ 'meta-box/meta-box.php' ] ];
		$GLOBALS['stonewright_test_site_options'] = [];

		self::assertSame( 'active', self::row( 'meta-box' )['version'] );
	}
}
