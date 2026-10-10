<?php
/**
 * Block parser for {@see BlockDiff} backed by WordPress.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

use Stonewright\WpMcp\Support\BlockTree;

/**
 * Parses block markup with `parse_blocks()`, through {@see BlockTree::parse()} so
 * block paths match the ones the block abilities report.
 *
 * Pass `[ WordPressBlockParser::class, 'parse' ]` as the parser of
 * {@see BlockDiff::diff()}.
 */
final class WordPressBlockParser {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $markup ): array {
		return BlockTree::parse( $markup );
	}
}
