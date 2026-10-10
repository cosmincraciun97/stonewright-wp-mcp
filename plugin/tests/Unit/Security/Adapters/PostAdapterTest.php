<?php
/**
 * The post family adapter: the image of a post, its restore, and the undo of a created post.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\PostAdapter
 */
final class PostAdapterTest extends PostLedgerTestCase {

	private const POST = 41;

	private const ELEMENTOR_DATA = '[{"id":"a1b2c3","elType":"widget","widgetType":"heading","settings":{"title":"Say \"hi\" \\\\ back","link":{"url":"https:\/\/example.com\/x?a=1"}}}]';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_object_taxonomies']['page'] = [ 'category', 'post_tag' ];
		$GLOBALS['stonewright_test_seo_plugin']                = 'yoast';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_seo_plugin'] );
		parent::tearDown();
	}

	/** A page that uses every part of the image. */
	private function rich_post(): void {
		$this->make_post(
			self::POST,
			[
				'post_title'   => 'Original title',
				'post_name'    => 'original-slug',
				'post_parent'  => 12,
				'menu_order'   => 5,
				'post_status'  => 'publish',
				'post_date'    => '2026-02-03 04:05:06',
				'post_date_gmt' => '2026-02-03 04:05:06',
				'post_content' => "Line one\nLine two",
			]
		);
		$GLOBALS['stonewright_test_posts'][77] = (object) [ 'ID' => 77, 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta' => [] ];
		$GLOBALS['stonewright_test_posts'][78] = (object) [ 'ID' => 78, 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta' => [] ];
		$this->set_terms( self::POST, 'category', [ [ 11, 'news', 'News' ], [ 12, 'blog', 'Blog' ] ] );
		$this->set_terms( self::POST, 'post_tag', [ [ 21, 'alpha', 'Alpha' ] ] );
		$this->set_meta( self::POST, '_thumbnail_id', 77 );
		$this->set_meta( self::POST, '_elementor_data', self::ELEMENTOR_DATA );
		$this->set_meta( self::POST, '_elementor_page_settings', [ 'hide_title' => 'yes', 'margin' => [ 'top' => '4' ] ] );
		$this->set_meta( self::POST, '_elementor_conditions', [ 'include/general' ] );
		$this->set_meta( self::POST, '_stonewright_spec_sections', [ [ 'id' => 'hero', 'hash' => 'abc' ] ] );
		$this->set_meta( self::POST, '_wp_page_template', 'full-width.php' );
		$this->set_meta( self::POST, '_yoast_wpseo_title', 'SEO title' );
		$this->set_meta( self::POST, '_yoast_wpseo_metadesc', 'SEO description' );
		$this->set_meta( self::POST, 'hero_title', 'Welcome' );
		$this->set_meta( self::POST, '_hero_title', 'field_abc123' );
		$this->set_meta( self::POST, 'plain_custom', 'not an acf field' );
	}

	private function scribble(): void {
		$post                = $GLOBALS['stonewright_test_posts'][ self::POST ];
		$post->post_title    = 'Changed title';
		$post->post_name     = 'changed-slug';
		$post->post_parent   = 0;
		$post->menu_order    = 99;
		$post->post_status   = 'draft';
		$post->post_date     = '2026-12-31 23:59:59';
		$post->post_date_gmt = '2026-12-31 23:59:59';
		$post->post_content  = 'Rewritten';
		$post->post_excerpt  = '';
		delete_post_meta( self::POST, '_thumbnail_id' );
		$this->set_terms( self::POST, 'category', [ [ 13, 'other', 'Other' ] ] );
		$this->set_terms( self::POST, 'post_tag', [] );
		$this->set_meta( self::POST, '_elementor_data', '[{"id":"zzz"}]' );
		delete_post_meta( self::POST, '_elementor_page_settings' );
		delete_post_meta( self::POST, '_elementor_conditions' );
		$this->set_meta( self::POST, '_elementor_version', '3.99.0' );
		$this->set_meta( self::POST, '_wp_page_template', 'default' );
		delete_post_meta( self::POST, '_yoast_wpseo_title' );
		$this->set_meta( self::POST, '_yoast_wpseo_metadesc', 'Changed description' );
		$this->set_meta( self::POST, '_yoast_wpseo_focuskw', 'new keyword' );
		$this->set_meta( self::POST, 'hero_title', 'Changed' );
		$this->set_meta( self::POST, 'cta_text', 'Buy' );
		$this->set_meta( self::POST, '_cta_text', 'field_def456' );
	}

	public function test_an_image_holds_every_part_of_the_post(): void {
		$this->rich_post();

		$image = PostAdapter::image( self::POST );

		self::assertIsArray( $image );
		self::assertSame( 1, $image['v'] );
		self::assertSame( 'page', $image['post_type'] );
		self::assertSame(
			[
				'post_title'    => 'Original title',
				'post_status'   => 'publish',
				'post_content'  => "Line one\nLine two",
				'post_excerpt'  => 'Synthetic excerpt',
				'post_name'     => 'original-slug',
				'post_parent'   => 12,
				'menu_order'    => 5,
				'post_date'     => '2026-02-03 04:05:06',
				'post_date_gmt' => '2026-02-03 04:05:06',
			],
			$image['post']
		);
		self::assertSame( 77, $image['featured_image'] );
		self::assertSame( [ 11, 12 ], array_column( $image['terms']['category'], 'term_id' ) );
		self::assertSame( [ 'news', 'blog' ], array_column( $image['terms']['category'], 'slug' ) );
		self::assertSame( [ 21 ], array_column( $image['terms']['post_tag'], 'term_id' ) );
		foreach ( [ '_elementor_data', '_elementor_page_settings', '_elementor_conditions', '_stonewright_spec_sections', '_wp_page_template', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'hero_title', '_hero_title' ] as $key ) {
			self::assertArrayHasKey( $key, $image['meta'], $key );
		}
		self::assertSame( self::ELEMENTOR_DATA, $image['meta']['_elementor_data'] );
		self::assertArrayNotHasKey( 'plain_custom', $image['meta'], 'A key outside the allowlist is not imaged.' );
		self::assertArrayNotHasKey( '_thumbnail_id', $image['meta'], 'The featured image has a field of its own.' );
		self::assertContains( '_elementor_version', $image['meta_absent'] );
		self::assertContains( 'rank_math_title', $image['meta_absent'], 'Keys of the other SEO plugins are known too.' );
	}

	public function test_an_image_never_holds_the_post_password(): void {
		$this->make_post( self::POST, [ 'post_password' => 'synthetic-pass' ] );

		self::assertStringNotContainsString( 'synthetic-pass', (string) json_encode( PostAdapter::image( self::POST ) ) );
	}

	public function test_a_missing_post_has_no_image(): void {
		self::assertNull( PostAdapter::image( 999 ) );
	}

	public function test_extra_meta_keys_join_the_image_and_a_missing_one_is_listed_as_absent(): void {
		$this->make_post( self::POST );
		$this->set_meta( self::POST, 'section_slug', 'hero' );

		$image = PostAdapter::image( self::POST, [ 'section_slug', 'section_order', '_edit_lock', '' ] );

		self::assertSame( 'hero', $image['meta']['section_slug'] );
		self::assertContains( 'section_order', $image['meta_absent'] );
		self::assertArrayNotHasKey( '_edit_lock', $image['meta'], 'A lock is not content.' );
		self::assertNotContains( '_edit_lock', $image['meta_absent'] );
	}

	public function test_restore_writes_every_part_of_the_image_back(): void {
		$this->rich_post();
		$image = PostAdapter::image( self::POST );
		$this->scribble();
		self::assertNotSame( ChangeImage::hash_of( $image, 'post', '41' ), ChangeImage::hash_of( PostAdapter::image( self::POST ), 'post', '41' ) );

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], 'Differences: ' . implode( ',', $result['differences'] ) );
		self::assertSame( [], $result['differences'] );
		self::assertSame( [], $result['skipped'] );
		$post = $GLOBALS['stonewright_test_posts'][ self::POST ];
		self::assertSame( 'Original title', $post->post_title );
		self::assertSame( 'original-slug', $post->post_name );
		self::assertSame( 12, (int) $post->post_parent );
		self::assertSame( 5, (int) $post->menu_order );
		self::assertSame( 'publish', $post->post_status );
		self::assertSame( '2026-02-03 04:05:06', $post->post_date );
		self::assertSame( "Line one\nLine two", $post->post_content );
		self::assertSame( 'Synthetic excerpt', $post->post_excerpt );
		self::assertSame( 77, (int) $this->meta( self::POST, '_thumbnail_id' ) );
		self::assertSame( [ 'news', 'blog' ], $GLOBALS['stonewright_test_object_terms'][ self::POST ]['category'] );
		self::assertSame( [ 'alpha' ], $GLOBALS['stonewright_test_object_terms'][ self::POST ]['post_tag'] );
		self::assertSame( self::ELEMENTOR_DATA, $this->meta( self::POST, '_elementor_data' ), 'The JSON comes back byte for byte.' );
		self::assertSame( [ 'hide_title' => 'yes', 'margin' => [ 'top' => '4' ] ], $this->meta( self::POST, '_elementor_page_settings' ) );
		self::assertSame( [ 'include/general' ], $this->meta( self::POST, '_elementor_conditions' ) );
		self::assertNull( $this->meta( self::POST, '_elementor_version' ), 'A key the change added is removed.' );
		self::assertSame( 'full-width.php', $this->meta( self::POST, '_wp_page_template' ) );
		self::assertSame( 'SEO title', $this->meta( self::POST, '_yoast_wpseo_title' ) );
		self::assertSame( 'SEO description', $this->meta( self::POST, '_yoast_wpseo_metadesc' ) );
		self::assertNull( $this->meta( self::POST, '_yoast_wpseo_focuskw' ), 'An SEO key the change added is removed.' );
		self::assertSame( 'Welcome', $this->meta( self::POST, 'hero_title' ) );
		self::assertNull( $this->meta( self::POST, 'cta_text' ), 'An ACF value the change added is removed.' );
		self::assertNull( $this->meta( self::POST, '_cta_text' ), 'So is its field reference.' );
		self::assertSame( 'not an acf field', $this->meta( self::POST, 'plain_custom' ), 'A key outside the image is left alone.' );
		self::assertSame(
			ChangeImage::hash_of( $image, 'post', '41' ),
			ChangeImage::hash_of( PostAdapter::image( self::POST ), 'post', '41' )
		);
	}

	public function test_an_image_that_went_through_the_ledger_restores_the_same(): void {
		$this->rich_post();
		$change = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		self::assertNotSame( '', $change );
		$this->scribble();

		$stored = ChangeLedger::read_image( $change, 'before' );
		self::assertIsArray( $stored );
		$result = PostAdapter::restore( self::POST, $stored );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], 'Differences: ' . implode( ',', $result['differences'] ) );
		self::assertSame( 'Original title', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_title );
		self::assertSame( self::ELEMENTOR_DATA, $this->meta( self::POST, '_elementor_data' ) );
	}

	public function test_restore_reports_a_term_and_a_featured_image_that_no_longer_exist(): void {
		$this->rich_post();
		$image = PostAdapter::image( self::POST );
		$this->scribble();
		unset( $GLOBALS['stonewright_test_terms']['category']['blog'], $GLOBALS['stonewright_test_posts'][77] );

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'], 'The post is not as it was, so the restore does not claim it is.' );
		self::assertContains( 'terms.category.12', $result['skipped'] );
		self::assertContains( 'featured_image', $result['skipped'] );
		self::assertContains( 'terms.category', $result['differences'] );
		self::assertSame( 'Original title', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_title, 'The rest is restored.' );
		self::assertSame( [ 'news' ], $GLOBALS['stonewright_test_object_terms'][ self::POST ]['category'] );
	}

	public function test_restore_refuses_an_image_that_was_masked(): void {
		$this->rich_post();
		$this->set_meta( self::POST, 'hero_title', 'Synthetic' );
		$image                                  = PostAdapter::image( self::POST );
		$image['meta']['hero_title']            = ChangeImage::MASK;
		$masked_content                         = $image;
		$masked_content['post']['post_content'] = "Fine\n[masked line 2]";
		$GLOBALS['stonewright_test_posts'][ self::POST ]->post_title = 'Live title';

		foreach ( [ $image, $masked_content ] as $bad ) {
			$result = PostAdapter::restore( self::POST, $bad );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_image_masked', $result->get_error_code() );
		}
		self::assertSame( 'Live title', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_title, 'Nothing was written.' );
	}

	public function test_restore_refuses_an_unusable_image_a_missing_post_and_another_post_type(): void {
		$this->rich_post();
		$image = PostAdapter::image( self::POST );

		self::assertSame( 'stonewright_image_invalid', PostAdapter::restore( self::POST, [ 'v' => 2 ] )->get_error_code() );
		self::assertSame( 'stonewright_image_invalid', PostAdapter::restore( self::POST, [ 'v' => 1, 'post' => 'x' ] )->get_error_code() );
		self::assertSame( 'stonewright_post_missing', PostAdapter::restore( 999, $image )->get_error_code() );

		$GLOBALS['stonewright_test_posts'][ self::POST ]->post_type = 'wp_template';
		self::assertSame( 'stonewright_post_type_mismatch', PostAdapter::restore( self::POST, $image )->get_error_code() );
	}

	public function test_restore_does_not_write_a_protected_key_that_an_image_asks_for(): void {
		$this->rich_post();
		$image                          = PostAdapter::image( self::POST );
		$image['meta']['_edit_lock']    = '1:2';
		$image['meta']['_wp_attached_file'] = 'x.png';
		$image['meta_absent'][]         = '_stonewright_backups';

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertNull( $this->meta( self::POST, '_edit_lock' ) );
		self::assertContains( 'meta._edit_lock', $result['skipped'] );
		self::assertContains( 'meta._stonewright_backups', $result['skipped'] );
	}

	public function test_a_credential_in_meta_is_masked_by_the_ledger_and_the_row_cannot_be_restored(): void {
		$marker = 'SYNTHETIC-SECRET-7f3a91';
		$this->make_post( self::POST );
		$this->set_meta( self::POST, 'payment_api_key', $marker );
		$this->set_meta( self::POST, '_payment_api_key', 'field_aaa111' );
		$this->set_meta( self::POST, '_yoast_wpseo_metadesc', 'password: ' . $marker );

		$change = PostAdapter::record_before( 'stonewright/acf-value-update', self::POST );

		$row = ChangeLedger::get( $change );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		$stored = ChangeLedger::read_image( $change, 'before' );
		self::assertIsArray( $stored );
		self::assertStringNotContainsString( $marker, (string) json_encode( $stored ) );
		self::assertSame( ChangeImage::MASK, $stored['meta']['payment_api_key'] );
		self::assertSame( 'stonewright_image_masked', PostAdapter::restore( self::POST, $stored )->get_error_code() );
		$blobs = glob( $this->uploads . '/stonewright-state/blobs/*.gz' ) ?: [];
		self::assertNotSame( [], $blobs );
		foreach ( $blobs as $blob ) {
			self::assertStringNotContainsString( $marker, (string) gzdecode( (string) file_get_contents( $blob ) ) );
		}
	}

	public function test_trash_created_moves_a_post_to_the_trash_and_never_deletes_it(): void {
		$this->make_post( 51, [ 'post_status' => 'draft' ] );

		$result = PostAdapter::trash_created( 51 );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_posts'][51]->post_status );
		self::assertSame( [ 51 ], $GLOBALS['stonewright_test_trashed_posts'] );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'], 'Nothing was force deleted.' );

		$again = PostAdapter::trash_created( 51 );
		self::assertTrue( $again['ok'] );
		self::assertSame( [ 51 ], $GLOBALS['stonewright_test_trashed_posts'], 'A post in the trash is not trashed twice.' );
		self::assertSame( 'stonewright_post_missing', PostAdapter::trash_created( 999 )->get_error_code() );
	}

	public function test_undo_trashes_a_created_post_and_restores_a_changed_one(): void {
		$this->rich_post();
		$changed = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$this->scribble();
		$this->make_post( 52, [ 'post_status' => 'draft' ] );
		$created = PostAdapter::record_create( 'stonewright/content-create-page', 52 );

		$undo_change  = PostAdapter::undo( $changed );
		$undo_created = PostAdapter::undo( $created );

		self::assertIsArray( $undo_change );
		self::assertTrue( $undo_change['ok'] );
		self::assertSame( 'Original title', $GLOBALS['stonewright_test_posts'][ self::POST ]->post_title );
		self::assertIsArray( $undo_created );
		self::assertTrue( $undo_created['ok'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_posts'][52]->post_status );
		self::assertSame( 'stonewright_change_not_found', PostAdapter::undo( 'cs-' . str_repeat( '0', 24 ) )->get_error_code() );
	}

	public function test_undo_refuses_a_change_the_ledger_marked_not_restorable_or_of_another_resource(): void {
		$this->make_post( self::POST );
		$this->set_meta( self::POST, 'payment_api_key', 'SYNTHETIC-SECRET-7f3a91' );
		$this->set_meta( self::POST, '_payment_api_key', 'field_aaa111' );
		$masked = PostAdapter::record_before( 'stonewright/acf-value-update', self::POST );
		$other  = ChangeLedger::record( [ 'ability' => 'stonewright/settings-update', 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'before' => [ 'value' => 'x' ] ] );

		$refused = PostAdapter::undo( $masked );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_change_not_restorable', $refused->get_error_code() );
		self::assertSame( 'stonewright_change_not_a_post', PostAdapter::undo( $other['change_id'] )->get_error_code() );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function families(): array {
		return [
			'content page'      => [ 'stonewright/content-update-page', 'page', 'post' ],
			'content post'      => [ 'stonewright/content-update-post', 'post', 'post' ],
			'seo'               => [ 'stonewright/seo-meta-update', 'page', 'post' ],
			'acf'               => [ 'stonewright/acf-value-update', 'page', 'post' ],
			'elementor v3'      => [ 'stonewright/elementor-v3-update-element', 'page', 'elementor' ],
			'elementor v4'      => [ 'stonewright/elementor-v4-update-node', 'page', 'elementor' ],
			'page settings'     => [ 'stonewright/elementor-v3-update-page-settings', 'page', 'elementor' ],
			'kit'               => [ 'stonewright/elementor-v3-update-kit-colors', 'elementor_library', 'elementor' ],
			'section reuse'     => [ 'stonewright/section-reuse-extract', 'page', 'elementor' ],
			'theme builder'     => [ 'stonewright/theme-builder-apply-template', 'elementor_library', 'elementor' ],
			'gutenberg apply'   => [ 'stonewright/gutenberg-apply-to-post', 'page', 'gutenberg' ],
			'blocks update'     => [ 'stonewright/blocks-update', 'post', 'gutenberg' ],
			'pattern'           => [ 'stonewright/patterns-update', 'wp_block', 'gutenberg' ],
			'fse template'      => [ 'stonewright/fse-update-template', 'wp_template', 'fse' ],
			'fse part'          => [ 'stonewright/fse-write-template-part', 'wp_template_part', 'fse' ],
			'navigation'        => [ 'stonewright/fse-navigation', 'wp_navigation', 'fse' ],
			'global styles'     => [ 'stonewright/fse-update-global-styles', 'wp_global_styles', 'global_styles' ],
			'fse ability only'  => [ 'stonewright/fse-update-template', 'page', 'fse' ],
		];
	}

	/**
	 * @dataProvider families
	 */
	public function test_the_family_comes_from_the_post_type_then_the_ability( string $ability, string $post_type, string $family ): void {
		self::assertSame( $family, PostAdapter::ledger_family( $ability, $post_type ) );
		self::assertContains( $family, ChangeLedger::FAMILIES );
	}
}
