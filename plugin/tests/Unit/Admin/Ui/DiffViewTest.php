<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\DiffView;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Support\Diff\BlockDiff;
use Stonewright\WpMcp\Support\Diff\ElementorTreeDiff;
use Stonewright\WpMcp\Support\Diff\FieldDiff;
use Stonewright\WpMcp\Support\Diff\TextDiff;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\DiffView
 */
final class DiffViewTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	/** @param array<string, mixed> $args */
	private static function text( string $old, string $new, array $args = [] ): string {
		return DiffView::render( TextDiff::diff( $old, $new ), array_merge( [ 'title' => 'functions.php' ], $args ) );
	}

	/** A parser with the shape of parse_blocks() for the one-line markup these tests use: one block per line. */
	private static function parser(): callable {
		return static function ( string $markup ): array {
			$blocks = [];
			foreach ( array_filter( array_map( 'trim', explode( "\n", $markup ) ) ) as $line ) {
				[ $name, $html ] = array_pad( explode( '|', $line, 2 ), 2, '' );
				$blocks[]        = [ 'blockName' => $name, 'attrs' => [], 'innerHTML' => $html, 'innerBlocks' => [], 'innerContent' => [ $html ] ];
			}

			return $blocks;
		};
	}

	// ---- Text: lines, numbers, markers -------------------------------------------------------------

	public function test_a_text_diff_is_a_table_of_lines_with_both_line_numbers_and_a_marker_word(): void {
		$html = self::text( "one\ntwo\nthree\n", "one\nTWO\nthree\nfour\n" );

		self::assertStringContainsString( '<div class="sw-ui-diff sw-ui-diff--text" data-sw-ui-diff="text">', $html );
		self::assertStringContainsString( '<table class="sw-ui-diff__table">', $html );
		self::assertStringContainsString( '<tr class="sw-ui-diff__line sw-ui-diff__line--del">', $html );
		self::assertStringContainsString( '<tr class="sw-ui-diff__line sw-ui-diff__line--add">', $html );
		self::assertStringContainsString( '<tr class="sw-ui-diff__line">', $html );
		// The removed line keeps its old number and has no new one; the added line is the reverse.
		self::assertMatchesRegularExpression( '#--del"><td class="sw-ui-diff__num" aria-hidden="true">2</td><td class="sw-ui-diff__num" aria-hidden="true"></td>#', $html );
		self::assertMatchesRegularExpression( '#--add"><td class="sw-ui-diff__num" aria-hidden="true"></td><td class="sw-ui-diff__num" aria-hidden="true">2</td>#', $html );
		// Colour is never the only cue: a marker and words for assistive technology.
		self::assertStringContainsString( '<span aria-hidden="true">+</span><span class="sw-ui-visually-hidden">Added line 2: </span>', $html );
		self::assertStringContainsString( '<span aria-hidden="true">-</span><span class="sw-ui-visually-hidden">Removed line 2: </span>', $html );
		self::assertStringContainsString( '<span class="sw-ui-diff__code">TWO</span>', $html );
	}

	public function test_a_hunk_has_its_unified_header_and_the_head_states_the_counts_in_words(): void {
		$html = self::text( "a\nb\nc\n", "a\nB\nc\nd\n" );

		self::assertStringContainsString( '@@ -1,3 +1,4 @@', $html );
		self::assertStringContainsString( '<span class="sw-ui-diff__title">functions.php</span>', $html );
		self::assertMatchesRegularExpression( '#sw-ui-diff__stat--add"><span aria-hidden="true">\+\d+</span><span class="sw-ui-visually-hidden">\d+ lines? added</span>#', $html );
		self::assertMatchesRegularExpression( '#sw-ui-diff__stat--del"><span aria-hidden="true">-\d+</span><span class="sw-ui-visually-hidden">\d+ lines? removed</span>#', $html );
	}

	public function test_the_scroll_region_is_focusable_and_named_so_a_long_line_can_be_read_from_the_keyboard(): void {
		$html = self::text( 'a', 'b' );

		self::assertMatchesRegularExpression( '#<div class="sw-ui-diff__body" role="region" tabindex="0" aria-label="functions\.php: changed lines">#', $html );
		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">', $html );
	}

	public function test_a_blank_line_still_has_height_and_a_missing_final_newline_is_said(): void {
		$html = self::text( "a\n\nb", "a\n\nb\n" );

		self::assertStringContainsString( '<span class="sw-ui-diff__code"></span>', $html );
		self::assertStringContainsString( 'No newline at end of file', $html );
	}

	// ---- Escaping -----------------------------------------------------------------------------------

	public function test_a_script_tag_in_a_line_renders_as_text(): void {
		$html = self::text( "<?php\n", "<?php\n<script>alert('x')</script>\n" );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', $html );
	}

	public function test_the_title_and_every_value_of_every_kind_are_escaped(): void {
		$evil   = '"><img src=x onerror=alert(1)>';
		$blocks = BlockDiff::diff( 'core/paragraph|<p>a</p>', 'core/paragraph|<p>' . $evil . '</p>', self::parser() );
		$fields = FieldDiff::diff( [ $evil => 'a' ], [ $evil => $evil ] );
		$tree   = ElementorTreeDiff::diff(
			[ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'x' ], 'elements' => [] ] ],
			[ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $evil ], 'elements' => [] ] ]
		);

		foreach ( [ $blocks, $fields, $tree, TextDiff::diff( 'a', $evil ) ] as $result ) {
			$html = DiffView::render( $result, [ 'title' => $evil ] );
			self::assertStringNotContainsString( '<img', $html, (string) $result['kind'] );
			self::assertStringNotContainsString( 'onerror="', $html, (string) $result['kind'] );
			self::assertStringContainsString( '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html, (string) $result['kind'] );
		}
	}

	// ---- Statuses and notes -------------------------------------------------------------------------

	public function test_identical_input_says_so_and_prints_no_table(): void {
		$html = self::text( "a\n", "a\n" );

		self::assertStringContainsString( 'sw-ui-diff-view--empty', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringContainsString( 'No difference', $html );
	}

	public function test_a_result_that_could_not_be_computed_shows_its_message_and_no_table(): void {
		$result = TextDiff::diff( str_repeat( "line\n", 3000 ), str_repeat( "other\n", 3000 ) );
		self::assertSame( 'too_large', $result['status'] );

		$html = DiffView::render( $result, [ 'title' => 'big.css' ] );

		self::assertStringContainsString( htmlspecialchars( (string) $result['message'], ENT_QUOTES ), $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringContainsString( 'sw-ui-callout--warn', $html );
	}

	public function test_truncation_and_masking_are_stated_above_the_diff_as_callouts(): void {
		$old    = "a\nb\n";
		$new    = "a\npassword = 'sentinel-SECRET-1234567890'\n";
		$result = TextDiff::diff( $old, $new );
		$result['truncated'] = true;
		$result['cut']       = [ 'hunks' => 2, 'changed_lines' => 40 ];

		$html = DiffView::render( $result, [ 'title' => 't' ] );

		self::assertStringNotContainsString( 'sentinel-SECRET', $html );
		self::assertStringContainsString( 'Not everything is shown', $html );
		self::assertStringContainsString( 'were left out', $html );
		self::assertSame( 1, substr_count( $html, 'Values were masked' ) );
		self::assertLessThan( strpos( $html, '<table' ), strpos( $html, 'Not everything is shown' ), 'The note comes before the lines.' );
	}

	public function test_an_unknown_kind_prints_nothing(): void {
		self::assertSame( '', DiffView::render( [ 'kind' => 'other' ], [ 'title' => 'x' ] ) );
		self::assertSame( '', DiffView::render( [], [ 'title' => 'x' ] ) );
	}

	// ---- Blocks, elements, fields ---------------------------------------------------------------------

	public function test_block_items_say_what_happened_to_which_block_and_where(): void {
		$result = BlockDiff::diff( "core/heading|<h2>A</h2>\ncore/paragraph|<p>old</p>", "core/heading|<h2>A</h2>\ncore/paragraph|<p>new</p>\ncore/image|<img>", self::parser() );
		$html   = DiffView::render( $result, [ 'title' => 'Content' ] );

		self::assertStringContainsString( 'data-sw-ui-diff="blocks"', $html );
		self::assertStringContainsString( '<ol class="sw-ui-diff__items">', $html );
		self::assertStringContainsString( 'sw-ui-badge sw-ui-badge--warn', $html );
		self::assertStringContainsString( '<code>core/paragraph</code>', $html );
		self::assertStringContainsString( '<code>core/image</code>', $html );
		self::assertStringContainsString( 'Block 2', $html, 'A path is said in words (1-based).' );
		self::assertStringContainsString( 'sw-ui-diff--text', $html, 'A changed block shows its own text diff.' );
	}

	public function test_elements_are_a_disclosure_each_with_a_table_of_their_setting_changes(): void {
		$result = ElementorTreeDiff::diff(
			[ [ 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old', 'align' => 'left' ], 'elements' => [] ] ],
			[
				[ 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'New', 'align' => 'left' ], 'elements' => [] ],
				[ 'id' => 'new5678', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [], 'elements' => [] ],
			]
		);
		$html   = DiffView::render( $result, [ 'title' => 'Elements' ] );

		self::assertStringContainsString( 'data-sw-ui-diff="elementor"', $html );
		self::assertStringContainsString( '<details class="sw-ui-disclosure sw-ui-diff__element"', $html );
		self::assertStringContainsString( 'abc1234', $html );
		self::assertStringContainsString( 'heading', $html );
		self::assertStringContainsString( '<code class="sw-ui-diff__value">Old</code>', $html );
		self::assertStringContainsString( '<code class="sw-ui-diff__value">New</code>', $html );
		self::assertStringContainsString( 'new5678', $html );
		self::assertStringContainsString( 'Added', $html );
	}

	public function test_fields_are_a_table_with_the_value_before_and_after_and_a_word_for_the_change(): void {
		$result = FieldDiff::diff( [ 'blogname' => 'Old name', 'gone' => 'x' ], [ 'blogname' => 'New name', 'fresh' => 'y' ] );
		$html   = DiffView::render( $result, [ 'title' => 'Fields' ] );

		self::assertStringContainsString( 'data-sw-ui-diff="fields"', $html );
		self::assertStringContainsString( '<table class="sw-ui-table', $html );
		self::assertStringContainsString( '<code>blogname</code>', $html );
		self::assertStringContainsString( '<code class="sw-ui-diff__value">Old name</code>', $html );
		self::assertStringContainsString( '<code class="sw-ui-diff__value">New name</code>', $html );
		self::assertStringContainsString( 'Not set', $html, 'An added field has no value before.' );
		self::assertSame( 1, substr_count( $html, '>Changed<' ) );
		self::assertSame( 1, substr_count( $html, '>Removed<' ) );
		self::assertSame( 1, substr_count( $html, '>Added<' ) );
	}

	public function test_a_redacted_field_is_said_to_be_masked_and_its_value_never_shows(): void {
		$result = FieldDiff::diff( [ 'api_key' => 'sentinel-OLD-VALUE-1' ], [ 'api_key' => 'sentinel-NEW-VALUE-2' ] );
		$html   = DiffView::render( $result, [ 'title' => 'Fields' ] );

		self::assertStringNotContainsString( 'sentinel-', $html );
		self::assertStringContainsString( '[redacted]', $html );
		self::assertStringContainsString( 'Values were masked', $html );
	}
}
