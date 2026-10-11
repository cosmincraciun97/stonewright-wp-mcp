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
use Stonewright\WpMcp\Support\Diff\DiffText;
use Stonewright\WpMcp\Support\Diff\ElementorTreeDiff;
use Stonewright\WpMcp\Support\Diff\FieldDiff;
use Stonewright\WpMcp\Support\Diff\TextDiff;
use Stonewright\WpMcp\Support\Diff\WordPressBlockParser;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\DiffText
 */
final class DiffTextTest extends TestCase {

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function diff( string $title, array $result, array $extra = [] ): array {
		return array_merge( [ 'status' => 'ok', 'message' => '', 'sections' => [ [ 'id' => 'content', 'title' => $title, 'result' => $result ] ], 'changed' => true, 'truncated' => false, 'masked' => 0, 'image_masked' => false ], $extra );
	}

	public function test_text_is_printed_as_unified_hunks(): void {
		$lines = DiffText::lines( $this->diff( 'functions.php', TextDiff::diff( "one\ntwo\nthree\n", "one\nTWO\nthree\n" ) ) );

		self::assertSame( [ '--- functions.php', '@@ -1,3 +1,3 @@', ' one', '-two', '+TWO', ' three' ], $lines );
	}

	public function test_fields_are_printed_one_per_path(): void {
		$lines = DiffText::lines( $this->diff( 'Fields', FieldDiff::diff( [ 'a' => '1', 'b' => '2' ], [ 'a' => '1', 'b' => '3', 'c' => '4' ] ) ) );

		self::assertContains( '~ b: 2 -> 3', $lines );
		self::assertContains( '+ c: (none) -> 4', $lines );
	}

	public function test_elements_are_printed_with_their_setting_changes(): void {
		$result = ElementorTreeDiff::diff(
			[ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Before' ], 'elements' => [] ] ],
			[ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'After' ], 'elements' => [] ] ]
		);

		$lines = DiffText::lines( $this->diff( 'Elementor elements', $result ) );

		self::assertContains( '~ heading a1 "After"', $lines );
		self::assertContains( '  ~ title: Before -> After', $lines );
	}

	public function test_blocks_are_printed_with_their_name_and_path(): void {
		$old = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n";
		$new = $old . "<!-- wp:heading -->\n<h2>Two</h2>\n<!-- /wp:heading -->\n\n";

		$lines = DiffText::lines( $this->diff( 'Content', BlockDiff::diff( $old, $new, [ WordPressBlockParser::class, 'parse' ] ) ) );

		self::assertContains( '+ core/heading [1]', $lines );
	}

	public function test_a_part_that_could_not_be_computed_or_was_cut_says_so(): void {
		$big   = TextDiff::diff( str_repeat( "line\n", 3000 ), str_repeat( "other\n", 3000 ) );
		$lines = DiffText::lines( $this->diff( 'big.css', $big ) );
		self::assertSame( [ '--- big.css', (string) $big['message'] ], $lines );

		$cut = TextDiff::diff( implode( "\n", range( 1, 60 ) ), implode( "\n", range( 101, 160 ) ), [ 'max_output_lines' => 10 ] );
		self::assertContains( 'Only part of the diff is shown.', DiffText::lines( $this->diff( 'a.txt', $cut ) ) );
	}

	public function test_a_diff_with_nothing_to_compare_prints_its_message_and_masked_values_are_counted(): void {
		self::assertSame( [ 'No content is kept.' ], DiffText::lines( [ 'status' => 'no_images', 'message' => 'No content is kept.', 'sections' => [] ] ) );

		$lines = DiffText::lines( $this->diff( 'Fields', FieldDiff::diff( [ 'password' => 'a' ], [ 'password' => 'b' ] ), [ 'masked' => 1 ] ) );
		self::assertContains( '1 value(s) were masked.', $lines );
		self::assertStringNotContainsString( 'password: a', implode( "\n", $lines ) );
	}
}
