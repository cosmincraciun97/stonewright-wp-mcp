<?php
/**
 * The diff of one ledger change: which engine reads which kind of image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;
use Stonewright\WpMcp\Tests\Unit\Admin\Fixtures\LedgerFixture;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\ChangeDiff
 */
final class ChangeDiffTest extends TestCase {

	use LedgerFixture;

	protected function setUp(): void {
		$this->ledger_up();
	}

	protected function tearDown(): void {
		$this->ledger_down();
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<string>
	 */
	private static function kinds( array $sections ): array {
		return array_map( static fn ( array $section ): string => (string) $section['result']['kind'], $sections );
	}

	public function test_a_file_image_is_a_text_diff_with_one_section_named_after_the_file(): void {
		$row = $this->seed_change(
			[ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/functions.php', 'ability' => 'stonewright/theme-file-write' ],
			"<?php\nadd_action( 'init', 'a' );\n",
			"<?php\nadd_action( 'init', 'a' );\nadd_action( 'init', 'b' );\n"
		);

		$diff = ChangeDiff::for_row( $row );

		self::assertSame( 'ok', $diff['status'] );
		self::assertSame( [ 'text' ], self::kinds( $diff['sections'] ) );
		self::assertSame( 'example-theme/functions.php', $diff['sections'][0]['title'] );
		self::assertSame( 1, $diff['sections'][0]['result']['summary']['added'] );
		self::assertTrue( $diff['changed'] );
	}

	public function test_block_markup_is_diffed_block_by_block_and_the_other_fields_as_fields(): void {
		$old = "<!-- wp:paragraph -->\n<p>Old</p>\n<!-- /wp:paragraph -->";
		$new = "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->";
		$row = $this->seed_change( [ 'family' => 'gutenberg' ], [ 'post_title' => 'Old title', 'post_content' => $old ], [ 'post_title' => 'New title', 'post_content' => $new ] );

		$diff = ChangeDiff::for_row( $row );

		self::assertSame( [ 'blocks', 'fields' ], self::kinds( $diff['sections'] ) );
		self::assertSame( [ 'Content', 'Fields' ], array_column( $diff['sections'], 'title' ) );
		self::assertSame( 'core/paragraph', $diff['sections'][0]['result']['items'][0]['name'] );
		self::assertSame( 'post_title', $diff['sections'][1]['result']['fields'][0]['path'] );
	}

	public function test_plain_post_content_without_blocks_is_a_text_diff(): void {
		$row = $this->seed_change( [], [ 'post_content' => "Line one\nLine two" ], [ 'post_content' => "Line one\nLine 2" ] );

		self::assertSame( [ 'text' ], self::kinds( ChangeDiff::for_row( $row )['sections'] ) );
	}

	public function test_an_elementor_tree_is_diffed_by_element_and_the_page_fields_beside_it(): void {
		$tree = static fn ( string $title ): string => (string) json_encode( [ [ 'id' => 'a1b2c3d', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $title ], 'elements' => [] ] ] );
		$row  = $this->seed_change(
			[ 'family' => 'elementor' ],
			[ 'post_title' => 'Home', 'meta' => [ '_elementor_data' => $tree( 'Old' ), '_elementor_edit_mode' => 'builder' ] ],
			[ 'post_title' => 'Home', 'meta' => [ '_elementor_data' => $tree( 'New' ), '_elementor_edit_mode' => 'builder' ] ]
		);

		$diff = ChangeDiff::for_row( $row );

		self::assertSame( [ 'elementor' ], self::kinds( $diff['sections'] ), 'Fields that did not change add no section.' );
		self::assertSame( 'Elementor elements', $diff['sections'][0]['title'] );
		self::assertSame( 'title', $diff['sections'][0]['result']['elements'][0]['settings'][0]['path'] );
	}

	public function test_a_json_tree_given_as_the_whole_image_of_an_elementor_change_is_read_as_a_tree(): void {
		$old = (string) json_encode( [ [ 'id' => 'a1b2c3d', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] );
		$new = (string) json_encode( [ [ 'id' => 'a1b2c3d', 'elType' => 'container', 'settings' => [ 'gap' => '8' ], 'elements' => [] ] ] );
		$row = $this->seed_change( [ 'family' => 'elementor', 'resource_type' => 'elementor_data' ], $old, $new );

		self::assertSame( [ 'elementor' ], self::kinds( ChangeDiff::for_row( $row )['sections'] ) );
	}

	public function test_an_options_image_is_a_field_diff(): void {
		$row  = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname' ], [ 'value' => 'Old name' ], [ 'value' => 'New name' ] );
		$diff = ChangeDiff::for_row( $row );

		self::assertSame( [ 'fields' ], self::kinds( $diff['sections'] ) );
		self::assertSame( 'New name', $diff['sections'][0]['result']['fields'][0]['after'] );
	}

	public function test_a_created_resource_is_diffed_against_nothing(): void {
		$row  = $this->seed_change( [ 'restorable' => true ], null, [ 'post_title' => 'Brand new', 'post_content' => 'Hello' ] );
		$diff = ChangeDiff::for_row( $row );

		self::assertSame( 'ok', $diff['status'] );
		self::assertSame( [ 'text', 'fields' ], self::kinds( $diff['sections'] ) );
		self::assertSame( 1, $diff['sections'][0]['result']['summary']['added'] );
		self::assertSame( 'added', $diff['sections'][1]['result']['fields'][0]['op'] );
	}

	public function test_a_change_that_has_no_after_image_yet_has_nothing_to_compare(): void {
		$row  = $this->seed_change( [], [ 'post_title' => 'Only before' ], null, 'armed' );
		$diff = ChangeDiff::for_row( $row );

		self::assertSame( 'before_only', $diff['status'] );
		self::assertSame( [], $diff['sections'] );
		self::assertNotSame( '', $diff['message'] );
	}

	public function test_a_change_without_any_image_says_so(): void {
		$row  = $this->seed_change( [ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_api_secret' ], [ 'value' => 'x' ], [ 'value' => 'y' ] );
		$diff = ChangeDiff::for_row( $row );

		self::assertSame( 'no_images', $diff['status'] );
		self::assertSame( [], $diff['sections'] );
	}

	public function test_a_blob_that_retention_removed_is_reported_and_never_throws(): void {
		$row = $this->seed_change( [], [ 'post_title' => 'a' ], [ 'post_title' => 'b' ] );
		$this->drop_blobs();

		$diff = ChangeDiff::for_row( $row );

		self::assertSame( 'unreadable', $diff['status'] );
		self::assertSame( [], $diff['sections'] );
		self::assertStringContainsString( 'no longer', $diff['message'] );
	}

	public function test_a_value_that_looks_like_a_credential_never_reaches_the_diff_and_the_row_says_its_image_was_masked(): void {
		$row  = $this->seed_change(
			[ 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_settings' ],
			[ 'mailer' => [ 'host' => 'smtp.example.test', 'password' => 'sentinel-OLD-1234567' ] ],
			[ 'mailer' => [ 'host' => 'smtp2.example.test', 'password' => 'sentinel-NEW-7654321' ] ]
		);
		$diff = ChangeDiff::for_row( $row );

		self::assertStringNotContainsString( 'sentinel-', (string) json_encode( $diff ) );
		self::assertTrue( $diff['image_masked'], 'The image was masked before it was stored, so the row cannot be restored from it.' );
	}

	public function test_what_the_engines_cut_is_reported_on_the_whole_diff(): void {
		$old  = implode( "\n", array_map( static fn ( int $n ): string => 'line ' . $n, range( 1, 1500 ) ) );
		$new  = implode( "\n", array_map( static fn ( int $n ): string => 'LINE ' . $n, range( 1, 1500 ) ) );
		$row  = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/style.css' ], $old, $new );
		$diff = ChangeDiff::for_row( $row );

		self::assertTrue( $diff['truncated'] );
	}

	public function test_the_engines_can_be_given_smaller_caps(): void {
		$row  = $this->seed_change( [ 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'a.css' ], "a\nb\nc\nd\n", "A\nB\nC\nD\n" );
		$diff = ChangeDiff::for_row( $row, [ 'text' => [ 'max_output_lines' => 3 ] ] );

		self::assertTrue( $diff['truncated'] );
	}

	public function test_a_row_with_an_invalid_id_is_not_readable(): void {
		$diff = ChangeDiff::for_row( [ 'change_id' => "x' OR 1=1", 'family' => 'post' ] );

		self::assertSame( 'unreadable', $diff['status'] );
	}
}
