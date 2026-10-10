<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockSource;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockSource::outline
 */
final class BlockSourceOutlineTest extends TestCase {

	public function test_reads_names_attributes_and_nesting(): void {
		$html = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">"
			. "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->"
			. '<!-- wp:acme/card {"title":"A \\u0026 B"} /-->'
			. "</div>\n<!-- /wp:group -->";

		self::assertSame(
			[
				[
					'name'     => 'core/group',
					'attrs'    => [ 'layout' => [ 'type' => 'constrained' ] ],
					'children' => [
						[ 'name' => 'core/paragraph', 'attrs' => [], 'children' => [] ],
						[ 'name' => 'acme/card', 'attrs' => [ 'title' => 'A & B' ], 'children' => [] ],
					],
				],
			],
			BlockSource::outline( $html )
		);
	}

	public function test_whitespace_between_top_level_blocks_is_allowed_and_a_blank_document_has_no_blocks(): void {
		$html = "\n<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->\n\n<!-- wp:separator /-->\n";

		self::assertSame( [ 'core/paragraph', 'core/separator' ], array_column( (array) BlockSource::outline( $html ), 'name' ) );
		self::assertSame( [], BlockSource::outline( " \n\t" ) );
		self::assertSame( [], BlockSource::outline( '' ) );
	}

	/** @dataProvider malformedProvider */
	public function test_refuses_anything_that_is_not_one_well_formed_run_of_blocks( string $html ): void {
		self::assertNull( BlockSource::outline( $html ) );
	}

	/** @return array<string, array{0:string}> */
	public static function malformedProvider(): array {
		return [
			'closer without opener'          => [ '<!-- /wp:paragraph -->' ],
			'opener never closed'            => [ '<!-- wp:paragraph --><p>x</p>' ],
			'nested opener never closed'     => [ '<!-- wp:group --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ],
			'closer names another block'     => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:heading -->' ],
			'stray closer after a block'     => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:paragraph -->' ],
			'text before the first block'    => [ 'text<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ],
			'text after the last block'      => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><p>tail</p>' ],
			'markup between top-level blocks' => [ '<!-- wp:paragraph --><!-- /wp:paragraph --><script>x</script><!-- wp:paragraph --><!-- /wp:paragraph -->' ],
			'closer carrying attributes'     => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph {"a":1} -->' ],
			'closer marked void'             => [ '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph /-->' ],
			'attributes that are not JSON'   => [ '<!-- wp:paragraph {"a":} --><p>x</p><!-- /wp:paragraph -->' ],
			'no block markup at all'         => [ '<p>Example</p>' ],
		];
	}

	public function test_refuses_a_document_with_more_delimiters_than_the_budget(): void {
		$html = str_repeat( '<!-- wp:separator /-->', 5 );

		self::assertCount( 5, (array) BlockSource::outline( $html ) );
		self::assertCount( 5, (array) BlockSource::outline( $html, 5 ) );
		self::assertNull( BlockSource::outline( $html, 4 ) );
	}
}
