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

	/** Shipped with their own tests; the Design Library UI is not registered, so nothing enqueues them. */
	private const NOT_ENQUEUED = [ 'assets/admin/design-studio.css', 'assets/admin/visual-workspace.css' ];

	public function test_every_stylesheet_in_the_admin_folders_is_loaded_by_some_code(): void {
		$plugin  = dirname( __DIR__, 3 );
		$sources = '';
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugin . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources .= (string) file_get_contents( $file->getPathname() ) . "\n";
			}
		}

		$unloaded = [];
		foreach ( [ 'assets/admin', 'assets/css', 'assets/admin/pages' ] as $folder ) {
			foreach ( glob( $plugin . '/' . $folder . '/*.css' ) ?: [] as $path ) {
				$relative = $folder . '/' . basename( $path );
				if ( in_array( $relative, self::NOT_ENQUEUED, true ) ) {
					continue;
				}
				// A file is named by its path, or by its name inside the page map and the console's own base URL;
				// the pages folder keeps its folder in the map ("pages/setup.css").
				$name = 'assets/admin/pages' === $folder ? 'pages/' . basename( $path ) : basename( $path );
				if ( false === strpos( $sources, $relative ) && 1 !== preg_match( '#(?<![\w/.-])' . preg_quote( $name, '#' ) . '#', $sources ) ) {
					$unloaded[] = $relative;
				}
			}
		}

		self::assertSame( [], $unloaded, 'Stylesheets nothing loads: delete them or map them to a page.' );
	}

	public function test_every_file_the_page_style_map_branches_on_is_a_file_the_map_names(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );
		preg_match_all( '/=>\s*\'([\w\/-]+\.css)\'/', $source, $mapped );
		preg_match_all( '/\'([\w\/-]+\.css)\'\s*===\s*\$page_styles/', $source, $branches );

		self::assertNotEmpty( $mapped[1] );
		foreach ( $branches[1] as $name ) {
			self::assertContains( $name, $mapped[1], $name . ' is never a value of the page map, so its branch can not run.' );
		}
		foreach ( $mapped[1] as $file ) {
			self::assertFileExists( dirname( __DIR__, 3 ) . '/assets/admin/' . $file, $file );
		}
	}
}
