<?php
/**
 * Shared fixture for the option, menu and widget ledger tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\Adapters\AdapterSupport;
use Stonewright\WpMcp\Security\ChangeLedger;

require_once dirname( __DIR__, 3 ) . '/Support/options-adapter-wp-stubs.php';

/**
 * The post ledger fixture, plus options, theme mods, nav menus and sidebars in a known empty state.
 */
abstract class FamilyLedgerTestCase extends PostLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		AdapterSupport::reset_for_tests();
		$GLOBALS['stonewright_test_nav_menus']             = [];
		$GLOBALS['stonewright_test_theme_mods']            = [];
		$GLOBALS['stonewright_test_registered_nav_menus']  = [ 'primary' => 'Primary Menu', 'footer' => 'Footer Menu' ];
		$GLOBALS['stonewright_test_next_nav_menu_id']      = 7001;
		$GLOBALS['stonewright_test_next_nav_menu_item_id'] = 7101;
		$GLOBALS['stonewright_test_sidebars']              = [ 'sidebar-1' => [ 'text-1', 'search-2' ], 'footer-1' => [] ];
		$GLOBALS['stonewright_test_acf_active']            = true;
		$GLOBALS['stonewright_test_acf_groups']            = [];
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
	}

	protected function tearDown(): void {
		AdapterSupport::reset_for_tests();
		$GLOBALS['stonewright_test_nav_menus']  = [];
		$GLOBALS['stonewright_test_theme_mods'] = [];
		$GLOBALS['stonewright_test_sidebars']   = [ 'sidebar-1' => [ 'text-1' ] ];
		unset( $GLOBALS['stonewright_test_acf_active'], $GLOBALS['stonewright_test_acf_groups'], $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
		parent::tearDown();
	}

	/**
	 * Everything the ledger wrote to disk, raw and decompressed, joined, to search for a value that must not be there.
	 */
	protected function everything_stored(): string {
		$all = '';
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$raw = (string) file_get_contents( $file->getPathname() );
			$all .= "\n" . $raw;
			$plain = @gzdecode( $raw );
			if ( is_string( $plain ) ) {
				$all .= "\n" . $plain;
			}
		}
		foreach ( $this->ledger_rows() as $row ) {
			$all .= "\n" . (string) json_encode( $row );
		}
		foreach ( $this->db->tables as $rows ) {
			$all .= "\n" . (string) json_encode( $rows );
		}
		return $all;
	}

	/**
	 * Build a nav menu the way the abilities do.
	 *
	 * Each item is [ key, title, url, parent key or '' ]. Items are added in the order given.
	 *
	 * @param list<array{0:string,1:string,2:string,3:string}> $items
	 * @return array{menu:int,items:array<string,int>}
	 */
	protected function make_menu( string $name, array $items ): array {
		$menu_id = wp_create_nav_menu( $name );
		self::assertIsInt( $menu_id );
		$ids = [];
		foreach ( $items as $index => [ $key, $title, $url, $parent ] ) {
			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-title'     => $title,
					'menu-item-url'       => $url,
					'menu-item-status'    => 'publish',
					'menu-item-type'      => 'custom',
					'menu-item-parent-id' => '' === $parent ? 0 : $ids[ $parent ],
					'menu-item-position'  => $index + 1,
					'menu-item-classes'   => 'item-' . $key,
				]
			);
			self::assertIsInt( $item_id );
			$ids[ $key ] = $item_id;
		}
		return [ 'menu' => $menu_id, 'items' => $ids ];
	}

	/**
	 * The menu as a reader sees it: items in order, each as title, url and the title of its parent. Ids are left
	 * out, because a restore may give an item a new one.
	 *
	 * @return list<array{0:string,1:string,2:string}>
	 */
	protected function menu_shape( int $menu_id ): array {
		$items  = wp_get_nav_menu_items( $menu_id ) ?: [];
		$titles = [];
		foreach ( $items as $item ) {
			$titles[ (int) $item->db_id ] = (string) $item->title;
		}
		$shape = [];
		foreach ( $items as $item ) {
			$shape[] = [ (string) $item->title, (string) $item->url, $titles[ (int) $item->menu_item_parent ] ?? '' ];
		}
		return $shape;
	}

	/** @return array<string, mixed> */
	protected function only_row_of( string $family ): array {
		$rows = array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => $family === $row['family'] ) );
		self::assertCount( 1, $rows, 'One ' . $family . ' row was expected.' );
		return $rows[0];
	}

	protected function ledger_count(): int {
		return ChangeLedger::count();
	}
}
