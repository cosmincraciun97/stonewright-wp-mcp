<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;

/**
 * The shared UI layer is registered before everything else, and the legacy assets load after it.
 *
 * @covers \Stonewright\WpMcp\Admin\AdminBootstrap::enqueue_assets
 */
final class AdminBootstrapAssetsTest extends TestCase {

	private const URL = 'https://example.test/wp-content/plugins/stonewright/';

	protected function setUp(): void {
		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', self::URL );
		}
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$GLOBALS['stonewright_test_enqueue_details']  = [];
		$_GET                                         = [];
	}

	protected function tearDown(): void {
		$_GET = [];
	}

	public function test_the_ui_layer_is_the_first_style_and_the_first_script(): void {
		AdminBootstrap::enqueue_assets( 'stonewright_page_stonewright-status' );

		self::assertSame( 'stonewright-ui', $GLOBALS['stonewright_test_enqueued_styles'][0] );
		self::assertSame( 'stonewright-ui', $GLOBALS['stonewright_test_enqueued_scripts'][0] );
	}

	public function test_the_ui_layer_files_are_the_ones_that_ship(): void {
		AdminBootstrap::enqueue_assets( 'stonewright_page_stonewright-status' );

		$details = $GLOBALS['stonewright_test_enqueue_details'];
		self::assertSame( self::URL . 'assets/admin/sw-ui.css', $details['style']['stonewright-ui']['src'] );
		self::assertSame( self::URL . 'assets/admin/sw-ui.js', $details['script']['stonewright-ui']['src'] );
		self::assertTrue( $details['script']['stonewright-ui']['in_footer'] );
		self::assertSame( [], $details['style']['stonewright-ui']['deps'], 'Nothing may load before the layer.' );
		self::assertSame( [], $details['script']['stonewright-ui']['deps'] );

		$plugin = dirname( __DIR__, 3 );
		self::assertFileExists( $plugin . '/assets/admin/sw-ui.css' );
		self::assertFileExists( $plugin . '/assets/admin/sw-ui.js' );
	}

	public function test_the_legacy_shell_loads_after_the_layer_by_dependency_not_by_luck(): void {
		AdminBootstrap::enqueue_assets( 'stonewright_page_stonewright-status' );

		$details = $GLOBALS['stonewright_test_enqueue_details'];
		self::assertContains( 'stonewright-ui', $details['style']['stonewright-admin-shell']['deps'] );
		self::assertContains( 'stonewright-ui', $details['script']['stonewright-admin-shell']['deps'] );
	}

	public function test_the_existing_assets_are_still_enqueued(): void {
		AdminBootstrap::enqueue_assets( 'stonewright_page_stonewright-status' );

		foreach ( [ 'stonewright-admin-shell', 'stonewright-admin', 'stonewright-admin-ds' ] as $handle ) {
			self::assertContains( $handle, $GLOBALS['stonewright_test_enqueued_styles'], $handle );
		}
		foreach ( [ 'stonewright-admin-shell', 'stonewright-admin' ] as $handle ) {
			self::assertContains( $handle, $GLOBALS['stonewright_test_enqueued_scripts'], $handle );
		}
	}

	public function test_nothing_is_enqueued_outside_stonewright_pages(): void {
		AdminBootstrap::enqueue_assets( 'edit.php' );

		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'] );
	}

	public function test_a_page_stylesheet_still_depends_on_the_shell_and_so_loads_after_the_layer(): void {
		$_GET['page'] = 'stonewright-abilities';

		AdminBootstrap::enqueue_assets( 'stonewright_page_stonewright-abilities' );

		$details = $GLOBALS['stonewright_test_enqueue_details']['style'];
		self::assertSame( [ 'stonewright-admin-shell', 'stonewright-admin' ], $details['stonewright-admin-abilities']['deps'] );
		self::assertSame( 'stonewright-ui', $GLOBALS['stonewright_test_enqueued_styles'][0] );
	}
}
