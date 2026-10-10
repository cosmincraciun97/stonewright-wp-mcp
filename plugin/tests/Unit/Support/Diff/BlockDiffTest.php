<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\BlockDiff;
use Stonewright\WpMcp\Support\Diff\WordPressBlockParser;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\BlockDiff
 * @covers \Stonewright\WpMcp\Support\Diff\WordPressBlockParser
 */
final class BlockDiffTest extends TestCase {

	private function para( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";
	}

	private function heading( int $level, string $text ): string {
		return "<!-- wp:heading {\"level\":{$level}} -->\n<h2>{$text}</h2>\n<!-- /wp:heading -->\n\n";
	}

	private function group( string $inner ): string {
		return "<!-- wp:group -->\n<div class=\"wp-block-group\">{$inner}</div>\n<!-- /wp:group -->\n\n";
	}

	/**
	 * @return array<string, mixed>
	 */
	private function diff( string $old, string $new, array $options = [] ): array {
		return BlockDiff::diff( $old, $new, [ WordPressBlockParser::class, 'parse' ], $options );
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<array<string, mixed>>
	 */
	private function items( array $result, string $op ): array {
		return array_values( array_filter( $result['items'], static fn( array $item ): bool => $op === $item['op'] ) );
	}

	public function test_attribute_change(): void {
		$r = $this->diff( $this->heading( 2, 'Title' ), $this->heading( 3, 'Title' ) );

		$this->assertSame( 'blocks', $r['kind'] );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 1 ], $r['summary'] );
		$this->assertCount( 1, $r['items'] );
		$item = $r['items'][0];
		$this->assertSame( 'changed', $item['op'] );
		$this->assertSame( 'core/heading', $item['name'] );
		$this->assertSame( [ 0 ], $item['path'] );
		$this->assertCount( 1, $item['attrs'] );
		$this->assertSame( [ 'level', 'changed', '2', '3' ], [ $item['attrs'][0]['path'], $item['attrs'][0]['op'], $item['attrs'][0]['before'], $item['attrs'][0]['after'] ] );
		$this->assertNull( $item['text'] );
	}

	public function test_inner_html_text_change_carries_a_text_diff(): void {
		$r = $this->diff( $this->para( 'Hello' ), $this->para( 'Hello world' ) );

		$item = $r['items'][0];
		$this->assertSame( 'changed', $item['op'] );
		$this->assertSame( [], $item['attrs'] );
		$this->assertSame( 'text', $item['text']['kind'] );
		$lines = [];
		foreach ( $item['text']['hunks'][0]['lines'] as $line ) {
			$lines[] = $line['op'] . ':' . $line['text'];
		}
		$this->assertContains( 'del:<p>Hello</p>', $lines );
		$this->assertContains( 'add:<p>Hello world</p>', $lines );
	}

	public function test_a_block_added_at_a_path(): void {
		$r = $this->diff( $this->para( 'One' ), $this->para( 'One' ) . $this->heading( 2, 'New' ) );

		$this->assertSame( [ 'added' => 1, 'removed' => 0, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( [ 'added', 'core/heading', [ 1 ] ], [ $r['items'][0]['op'], $r['items'][0]['name'], $r['items'][0]['path'] ] );
	}

	public function test_a_block_inserted_first_does_not_report_the_rest_as_changed(): void {
		$r = $this->diff( $this->para( 'One' ) . $this->para( 'Two' ), $this->heading( 2, 'Top' ) . $this->para( 'One' ) . $this->para( 'Two' ) );

		$this->assertSame( [ 'added' => 1, 'removed' => 0, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( [ 0 ], $r['items'][0]['path'] );
	}

	public function test_a_block_removed(): void {
		$r = $this->diff( $this->para( 'One' ) . $this->heading( 2, 'Gone' ), $this->para( 'One' ) );

		$this->assertSame( [ 'added' => 0, 'removed' => 1, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( [ 'removed', 'core/heading', [ 1 ] ], [ $r['items'][0]['op'], $r['items'][0]['name'], $r['items'][0]['path'] ] );
	}

	public function test_nested_block_change_names_the_inner_path_only(): void {
		$old = $this->group( "\n" . $this->para( 'Inner' ) );
		$new = $this->group( "\n" . $this->para( 'Inner changed' ) );

		$r = $this->diff( $old, $new );

		$this->assertSame( 1, $r['summary']['changed'] );
		$this->assertCount( 1, $r['items'] );
		$this->assertSame( [ 'changed', 'core/paragraph', [ 0, 0 ] ], [ $r['items'][0]['op'], $r['items'][0]['name'], $r['items'][0]['path'] ] );
	}

	public function test_a_nested_block_added_and_a_group_with_children_removed(): void {
		$old = $this->group( "\n" . $this->para( 'A' ) ) . $this->group( "\n" . $this->para( 'B' ) . $this->para( 'C' ) );
		$new = $this->group( "\n" . $this->para( 'A' ) . $this->para( 'New' ) );

		$r = $this->diff( $old, $new );

		$added = $this->items( $r, 'added' );
		$this->assertCount( 1, $added );
		$this->assertSame( [ 0, 1 ], $added[0]['path'] );
		$removed = $this->items( $r, 'removed' );
		$this->assertCount( 1, $removed );
		$this->assertSame( [ 1 ], $removed[0]['path'] );
		$this->assertSame( 'core/group', $removed[0]['name'] );
		$this->assertSame( 2, $removed[0]['descendants'] );
	}

	public function test_reordering_reports_a_moved_block(): void {
		$r = $this->diff( $this->para( 'One' ) . $this->heading( 2, 'Two' ), $this->heading( 2, 'Two' ) . $this->para( 'One' ) );

		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 1, 'changed' => 0 ], $r['summary'] );
		$moved = $r['items'][0];
		$this->assertSame( 'moved', $moved['op'] );
		$this->assertSame( [ 'core/paragraph', [ 0 ], [ 1 ] ], [ $moved['name'], $moved['from'], $moved['path'] ] );
	}

	public function test_moving_a_block_into_a_group_is_a_move_not_add_plus_remove(): void {
		$old = $this->para( 'Loose' ) . $this->group( "\n" );
		$new = $this->group( "\n" . $this->para( 'Loose' ) );

		$r = $this->diff( $old, $new );

		$this->assertSame( 1, $r['summary']['moved'] );
		$moved = $this->items( $r, 'moved' )[0];
		$this->assertSame( [ [ 0 ], [ 0, 0 ] ], [ $moved['from'], $moved['path'] ] );
	}

	public function test_identical_markup(): void {
		$r = $this->diff( $this->para( 'Same' ), $this->para( 'Same' ) );
		$this->assertSame( 'identical', $r['status'] );
		$this->assertSame( [], $r['items'] );
	}

	public function test_works_with_an_injected_parser_and_no_wordpress_function(): void {
		$parser = static function ( string $markup ): array {
			$blocks = [];
			foreach ( array_filter( explode( '|', $markup ) ) as $name ) {
				$blocks[] = [ 'blockName' => $name, 'attrs' => [ 'k' => 'v' ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ];
			}
			return $blocks;
		};

		$r = BlockDiff::diff( 'core/a|core/b', 'core/a|core/c', $parser );

		$this->assertSame( [ 'added' => 1, 'removed' => 1, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
	}

	public function test_secret_attributes_and_credentials_in_html_are_masked(): void {
		$old = "<!-- wp:html {\"api_key\":\"old-key-1234567\"} -->\n<p>x</p>\n<!-- /wp:html -->\n";
		$new = "<!-- wp:html {\"api_key\":\"new-key-7654321\"} -->\n<p>token = abc123456789</p>\n<!-- /wp:html -->\n";

		$r    = $this->diff( $old, $new );
		$json = (string) json_encode( $r );

		$this->assertStringNotContainsString( 'old-key', $json );
		$this->assertStringNotContainsString( 'new-key', $json );
		$this->assertStringNotContainsString( 'abc123456789', $json );
		$this->assertSame( 'api_key', $r['items'][0]['attrs'][0]['path'] );
		$this->assertTrue( $r['items'][0]['attrs'][0]['redacted'] );
	}

	public function test_item_cap_cuts_and_counts_but_the_summary_is_exact(): void {
		$old = '';
		$new = '';
		for ( $i = 0; $i < 30; $i++ ) {
			$new .= $this->para( 'Paragraph ' . $i );
		}

		$r = $this->diff( $old, $new, [ 'max_items' => 10 ] );

		$this->assertCount( 10, $r['items'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( 20, $r['cut']['items'] );
		$this->assertSame( 30, $r['summary']['added'] );
	}

	public function test_attribute_changes_per_block_are_capped(): void {
		$old_attrs = [];
		$new_attrs = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$old_attrs[ 'a' . $i ] = 1;
			$new_attrs[ 'a' . $i ] = 2;
		}
		$old = "<!-- wp:spacer " . json_encode( $old_attrs ) . " /-->\n";
		$new = "<!-- wp:spacer " . json_encode( $new_attrs ) . " /-->\n";

		$r = $this->diff( $old, $new, [ 'max_attrs' => 5 ] );

		$this->assertCount( 5, $r['items'][0]['attrs'] );
		$this->assertSame( 25, $r['items'][0]['attrs_cut'] );
		$this->assertTrue( $r['truncated'] );
	}

	public function test_too_large_markup_returns_a_summary(): void {
		$r = $this->diff( str_repeat( $this->para( 'x' ), 20 ), 'x', [ 'max_bytes' => 100 ] );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( 'bytes', $r['reason'] );
		$this->assertSame( [], $r['items'] );

		$r = $this->diff( str_repeat( $this->para( 'x' ), 20 ), 'x', [ 'max_blocks' => 5 ] );
		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( 'blocks', $r['reason'] );
	}

	public function test_output_is_valid_json(): void {
		$r = $this->diff( $this->para( 'Caf' . "\xC3\xA9" ), $this->para( 'Caf' . "\xC3\xA9" . ' au lait' ) );
		$this->assertNotFalse( json_encode( $r ) );
	}
}
