<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\PluginActionLinks;

/**
 * @covers \Stonewright\WpMcp\Admin\PluginActionLinks
 */
final class PluginActionLinksTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_filters']   = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_filters']   = [];
	}

	public function test_it_hooks_the_plugin_list_row_for_this_plugin_only(): void {
		PluginActionLinks::register();

		self::assertArrayHasKey( 'plugin_action_links_stonewright/stonewright.php', $GLOBALS['stonewright_test_filters'] );
		self::assertArrayHasKey( 'plugin_row_meta', $GLOBALS['stonewright_test_filters'] );
	}

	public function test_overview_and_setup_come_before_the_links_wordpress_prints(): void {
		$links = PluginActionLinks::action_links( [ 'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>' ] );

		self::assertSame( [ 'stonewright-overview', 'stonewright-setup', 'deactivate' ], array_keys( $links ) );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=stonewright-status"', $links['stonewright-overview'] );
		self::assertStringContainsString( '>Overview</a>', $links['stonewright-overview'] );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=stonewright"', $links['stonewright-setup'] );
		self::assertStringContainsString( '>Setup</a>', $links['stonewright-setup'] );
	}

	public function test_someone_who_cannot_manage_options_gets_the_list_unchanged(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];
		$original                              = [ 'deactivate' => '<a href="#">Deactivate</a>' ];

		self::assertSame( $original, PluginActionLinks::action_links( $original ) );
	}

	public function test_the_docs_link_is_added_to_this_plugins_row_meta_only(): void {
		$mine  = PluginActionLinks::row_meta( [ 'Version 1.0' ], 'stonewright/stonewright.php' );
		$other = PluginActionLinks::row_meta( [ 'Version 2.0' ], 'other/other.php' );

		self::assertCount( 2, $mine );
		self::assertStringContainsString( '>Docs</a>', $mine[1] );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $mine[1] );
		self::assertStringContainsString( 'target="_blank"', $mine[1] );
		self::assertStringStartsWith( '<a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/tree/main/docs"', $mine[1] );
		self::assertSame( [ 'Version 2.0' ], $other );
	}

	public function test_the_docs_link_names_the_new_tab(): void {
		$meta = PluginActionLinks::row_meta( [], 'stonewright/stonewright.php' );

		self::assertStringContainsString( 'aria-label="Stonewright documentation (opens in a new tab)"', $meta[0] );
	}
}
