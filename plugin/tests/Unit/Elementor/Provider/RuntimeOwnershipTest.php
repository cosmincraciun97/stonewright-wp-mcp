<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\RuntimeOwnership;

/** @covers \Stonewright\WpMcp\Elementor\Provider\RuntimeOwnership */
final class RuntimeOwnershipTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_context_resolves_active_main_file_when_filename_differs_from_folder(): void {
		$plugin_dir = WP_CONTENT_DIR . '/plugins';
		$folder = $plugin_dir . '/custom-folder';
		mkdir( $folder . '/src', 0700, true );
		file_put_contents( $folder . '/bootstrap.php', "<?php\n/*\nPlugin Name: Active Provider\nVersion: 2.4.6\n*/\n" );
		file_put_contents( $folder . '/custom-folder.php', "<?php\n/*\nPlugin Name: Inactive Decoy\nVersion: 9.9.9\n*/\n" );
		file_put_contents( $folder . '/src/Provider.php', "<?php\nnamespace StonewrightOwnershipFixture;\nfinal class Provider {}\n" );
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $plugin_dir );
		}
		$GLOBALS['stonewright_test_options']['active_plugins'] = [ 'custom-folder/bootstrap.php' ];
		require_once $folder . '/src/Provider.php';

		$result = RuntimeOwnership::describe( new \StonewrightOwnershipFixture\Provider() );

		self::assertSame( 'custom-folder/bootstrap.php', $result['source_plugin'] );
		self::assertSame( '2.4.6', $result['source_version'] );
		self::assertSame( 'plugin:custom-folder', $result['provider_id'] );
		self::assertSame( 'active_plugin_header', $result['provenance']['ownership'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_root_plugin_cannot_claim_an_inactive_nested_plugin_class(): void {
		$plugin_dir = WP_CONTENT_DIR . '/plugins';
		$nested = $plugin_dir . '/inactive-provider';
		mkdir( $nested . '/src', 0700, true );
		file_put_contents( $plugin_dir . '/root-owner.php', "<?php\n/*\nPlugin Name: Root Owner\nVersion: 1.0.0\n*/\n" );
		file_put_contents( $nested . '/src/Provider.php', "<?php\nnamespace StonewrightInactiveOwnershipFixture;\nfinal class Provider {}\n" );
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $plugin_dir );
		}
		$GLOBALS['stonewright_test_options']['active_plugins'] = [ 'root-owner.php' ];
		require_once $nested . '/src/Provider.php';

		$result = RuntimeOwnership::describe( new \StonewrightInactiveOwnershipFixture\Provider() );

		self::assertSame( 'unknown', $result['source_plugin'] );
		self::assertSame( '', $result['source_version'] );
		self::assertSame( 'registration_callback', $result['provenance']['ownership'] );
	}
}
