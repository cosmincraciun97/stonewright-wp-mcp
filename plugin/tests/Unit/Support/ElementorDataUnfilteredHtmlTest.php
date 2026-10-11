<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Elementor registers `_elementor_data` with a sanitize callback that runs the whole tree through kses when the
 * current user cannot `unfiltered_html` (a role without it, or DISALLOW_UNFILTERED_HTML on the site), which is
 * also what its own editor does on save. A write that stored the tree as given then read back something else
 * and failed as "readback failed"; now the tree is filtered the way Elementor's editor filters it before it is
 * written, so it is stored, verified and reported as filtered.
 *
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class ElementorDataUnfilteredHtmlTest extends TestCase {

	private const POST = 8810;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_posts'][ self::POST ] = (object) [
			'ID'           => self::POST,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Limited role page',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'limited',
			'meta'         => [ '_elementor_data' => '[]', '_elementor_edit_mode' => 'builder' ],
		];
		// Core's kses, as far as this test needs it: a script element goes, the rest stays.
		$GLOBALS['stonewright_test_wp_kses_post_callback'] = static fn( string $content ): string => (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
		// Elementor's sanitize callback of the meta, which runs inside update_post_meta() for a user without the capability.
		add_filter(
			'update_post_metadata',
			static function ( mixed $check, int $post_id, string $key, mixed $value ): mixed {
				if ( '_elementor_data' !== $key || ! is_string( $value ) || current_user_can( 'unfiltered_html' ) ) {
					return $check;
				}
				$decoded = json_decode( wp_unslash( $value ), true );
				$walk    = static function ( mixed $node ) use ( &$walk ): mixed {
					if ( is_string( $node ) ) {
						return wp_kses_post( $node );
					}
					return is_array( $node ) ? array_map( $walk, $node ) : $node;
				};
				$GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_elementor_data'] = wp_json_encode( $walk( $decoded ) );
				return true;
			},
			10,
			4
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_wp_kses_post_callback'], $GLOBALS['stonewright_test_posts'][ self::POST ] );
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_caps']      = [];
	}

	/** @return list<array<string,mixed>> */
	private static function tree( string $html ): array {
		return [ [ 'id' => 'sec0001', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'wdg0001', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => $html ], 'elements' => [] ] ] ] ];
	}

	public function test_a_user_without_unfiltered_html_writes_the_tree_the_way_elementors_editor_stores_it(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_pages' => true ];

		$written = ElementorData::write( self::POST, self::tree( '<p>Hello</p><script>alert(1)</script>' ), [ 'skip_integrity' => true ] );

		self::assertTrue( $written, ElementorData::last_write_error()?->get_error_message() ?? '' );
		self::assertSame( '<p>Hello</p>', ElementorData::read( self::POST )[0]['elements'][0]['settings']['editor'], 'the stored tree is what Elementor itself would have stored' );
		$receipt = ElementorData::last_elementor_write_receipt();
		self::assertSame( 'verified', $receipt['verification_status'] );
		self::assertSame( 'not_needed', $receipt['rollback_status'] );
		self::assertTrue( $receipt['content_filtered'], 'the receipt says markup was filtered' );
		self::assertSame( $receipt['planned_hash'], $receipt['after_hash'], 'the planned hash is the hash of what was written' );
	}

	public function test_an_administrator_with_unfiltered_html_disallowed_by_the_site_is_treated_the_same(): void {
		// DISALLOW_UNFILTERED_HTML takes the capability from everyone, an administrator included.
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap ): bool => 'unfiltered_html' !== $cap;

		$written = ElementorData::write( self::POST, self::tree( '<p>Hello</p><script>alert(1)</script>' ), [ 'skip_integrity' => true ] );

		self::assertTrue( $written, ElementorData::last_write_error()?->get_error_message() ?? '' );
		self::assertSame( '<p>Hello</p>', ElementorData::read( self::POST )[0]['elements'][0]['settings']['editor'] );
		self::assertTrue( ElementorData::last_elementor_write_receipt()['content_filtered'] );
	}

	public function test_a_tree_kses_leaves_alone_is_written_unchanged_and_not_reported_as_filtered(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		$tree = self::tree( '<p>Hello <strong>world</strong></p>' );

		self::assertTrue( ElementorData::write( self::POST, $tree, [ 'skip_integrity' => true ] ) );

		self::assertSame( $tree, ElementorData::read( self::POST ) );
		self::assertArrayNotHasKey( 'content_filtered', ElementorData::last_elementor_write_receipt() );
	}

	public function test_a_user_with_unfiltered_html_stores_the_tree_as_given(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'unfiltered_html' => true ];
		$tree = self::tree( '<p>Hello</p><script>alert(1)</script>' );

		self::assertTrue( ElementorData::write( self::POST, $tree, [ 'skip_integrity' => true ] ) );

		self::assertSame( $tree, ElementorData::read( self::POST ) );
		self::assertArrayNotHasKey( 'content_filtered', ElementorData::last_elementor_write_receipt() );
	}

	public function test_a_real_readback_mismatch_is_still_a_failure_with_its_own_error(): void {
		$GLOBALS['stonewright_test_user_caps']               = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$written = ElementorData::write( self::POST, self::tree( '<p>Hello</p>' ), [ 'skip_integrity' => true ] );
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );

		self::assertFalse( $written );
		self::assertSame( 'stonewright_elementor_readback_failed_restored', ElementorData::last_write_error()?->get_error_code() );
	}
}
