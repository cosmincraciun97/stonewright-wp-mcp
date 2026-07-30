<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Registers Stonewright's dynamic Gutenberg blocks.
 *
 * Block metadata lives under `plugin/blocks/<slug>/block.json` and uses
 * `wp_register_block_metadata_collection()` so all blocks are picked up
 * with a single lookup table.
 */
final class BlockRegistry {

	public static function register(): void {
		$blocks_dir = STONEWRIGHT_DIR . 'blocks';
		if ( ! is_dir( $blocks_dir ) ) {
			return;
		}

		$manifest = $blocks_dir . '/blocks-manifest.php';
		if ( function_exists( 'wp_register_block_types_from_metadata_collection' ) && is_readable( $manifest ) ) {
			wp_register_block_types_from_metadata_collection( $blocks_dir, $manifest );
			return;
		}

		$entries = glob( $blocks_dir . '/*/block.json' );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			register_block_type( dirname( $entry ) );
		}
	}
}
