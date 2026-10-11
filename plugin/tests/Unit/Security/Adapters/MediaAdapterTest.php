<?php
/**
 * The media family in the change ledger: attachment fields and metadata, uploads as creates.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Media\SetAlt;
use Stonewright\WpMcp\Security\Adapters\MediaAdapter;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\MediaAdapter
 */
final class MediaAdapterTest extends OtherFamilyLedgerTestCase {

	private const SIZES_BEFORE = [ 'file' => '2026/01/hero.jpg', 'width' => 1200, 'height' => 800, 'sizes' => [ 'thumbnail' => [ 'file' => 'hero-150x150.jpg', 'width' => 150, 'height' => 150 ] ] ];

	private const SIZES_AFTER = [ 'file' => '2026/01/hero.jpg', 'width' => 1200, 'height' => 800, 'sizes' => [ 'thumbnail' => [ 'file' => 'hero-150x150.jpg', 'width' => 150, 'height' => 150 ], 'medium' => [ 'file' => 'hero-300x200.jpg', 'width' => 300, 'height' => 200 ] ] ];

	private function make_attachment( int $id = 70, array $overrides = [], array $meta = [] ): void {
		$this->make_post(
			$id,
			array_merge(
				[
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Hero image',
					'post_excerpt'   => 'A caption',
					'post_content'   => 'A description',
					'post_name'      => 'hero-image',
					'post_mime_type' => 'image/jpeg',
				],
				$overrides
			)
		);
		foreach ( array_merge( [ '_wp_attachment_image_alt' => 'Old alt', '_wp_attachment_metadata' => self::SIZES_BEFORE, '_wp_attached_file' => '2026/01/hero.jpg' ], $meta ) as $key => $value ) {
			$this->set_meta( $id, $key, $value );
		}
	}

	public function test_a_new_alt_text_is_recorded_and_restored(): void {
		$this->make_attachment();

		$result = ( new SetAlt() )->execute( [ 'id' => 70, 'alt' => 'New alt' ] );

		self::assertSame( [ 'id' => 70, 'alt' => 'New alt' ], $result );
		$row = $this->row_of( 'media' );
		self::assertSame( [ 'attachment', '70', 'stonewright/media-set-alt', 'verified', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringStartsWith( 'Updated media', $row['summary'] );
		self::assertSame( 'Old alt', ChangeLedger::read_image( $row['change_id'], 'before' )['meta']['_wp_attachment_image_alt'] );
		self::assertSame( 'New alt', ChangeLedger::read_image( $row['change_id'], 'after' )['meta']['_wp_attachment_image_alt'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'Old alt', $this->meta( 70, '_wp_attachment_image_alt' ) );
		self::assertCount( 1, $this->rows_of_kind( 'rollback' ) );
	}

	public function test_title_caption_and_description_are_recorded_and_restored(): void {
		$this->make_attachment();

		$this->call(
			'stonewright/media-set-alt',
			[ 'id' => 70, 'title' => 'x' ],
			static function (): void {
				$post               = $GLOBALS['stonewright_test_posts'][70];
				$post->post_title   = 'Renamed';
				$post->post_excerpt = 'New caption';
				$post->post_content = 'New description';
			}
		);

		$row    = $this->row_of( 'media' );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( [ 'Hero image', 'A caption', 'A description' ], [ $before['post']['post_title'], $before['post']['post_excerpt'], $before['post']['post_content'] ] );
		OtherFamilies::restore( $row['change_id'] );
		$post = $GLOBALS['stonewright_test_posts'][70];
		self::assertSame( [ 'Hero image', 'A caption', 'A description' ], [ $post->post_title, $post->post_excerpt, $post->post_content ] );
	}

	public function test_an_optimize_records_the_metadata_and_says_the_size_files_are_not_kept(): void {
		$this->make_attachment();

		$this->call(
			'stonewright/media-optimize',
			[ 'id' => 70 ],
			function (): void {
				$this->set_meta( 70, '_wp_attachment_metadata', self::SIZES_AFTER );
			},
			[ 'id' => 70, 'sizes' => [ 'thumbnail', 'medium' ] ]
		);

		$row = $this->row_of( 'media' );
		self::assertTrue( $row['restorable'] );
		self::assertStringContainsString( 'metadata only', $row['summary'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertEquals( self::SIZES_BEFORE, $before['meta']['_wp_attachment_metadata'] );
		self::assertSame( '2026/01/hero.jpg', $before['file']['path'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertEquals( self::SIZES_BEFORE, $this->meta( 70, '_wp_attachment_metadata' ) );
		self::assertStringContainsString( 'size files', implode( ' ', $restore['limits'] ) );
	}

	public function test_a_restore_never_rewrites_the_path_of_the_file(): void {
		$this->make_attachment();
		$this->call(
			'stonewright/media-optimize',
			[ 'id' => 70 ],
			function (): void {
				$this->set_meta( 70, '_wp_attached_file', '2026/01/other.jpg' );
				$this->set_meta( 70, '_wp_attachment_metadata', self::SIZES_AFTER );
			}
		);

		OtherFamilies::restore( $this->row_of( 'media' )['change_id'] );

		self::assertSame( '2026/01/other.jpg', $this->meta( 70, '_wp_attached_file' ), 'The path of the file is recorded, not restored.' );
		self::assertEquals( self::SIZES_BEFORE, $this->meta( 70, '_wp_attachment_metadata' ) );
	}

	public function test_an_upload_is_recorded_as_a_create_and_its_undo_never_deletes_without_a_decision(): void {
		$this->call(
			'stonewright/media-upload',
			[ 'url' => 'https://example.test/hero.jpg' ],
			function (): void {
				$this->make_attachment( 77, [ 'post_title' => 'Uploaded' ] );
			},
			[ 'id' => 77, 'url' => 'https://example.test/wp-content/uploads/hero.jpg', 'mime' => 'image/jpeg' ]
		);

		$row = $this->row_of( 'media' );
		self::assertSame( '77', $row['resource_id'] );
		self::assertStringStartsWith( 'Created media', $row['summary'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertSame( 'Uploaded', ChangeLedger::read_image( $row['change_id'], 'after' )['post']['post_title'] );

		$undo = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'permanent_delete_not_confirmed' ], [ $undo['status'], $undo['detail'] ] );
		self::assertArrayHasKey( 77, $GLOBALS['stonewright_test_posts'], 'Nothing was deleted.' );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_attachments'] );

		$undo = OtherFamilies::restore( $row['change_id'], [ 'permanent' => true ] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertArrayNotHasKey( 77, $GLOBALS['stonewright_test_posts'] );
		self::assertSame( [ [ 77, true ] ], $GLOBALS['stonewright_test_deleted_attachments'] );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'], [ 'permanent' => true ] )['status'] );
	}

	public function test_a_batch_is_recorded_through_its_uploads_only(): void {
		\Stonewright\WpMcp\Security\RescueGuard::enter( 'stonewright/media-upload-batch', [ 'items' => [ [ 'url' => 'https://example.test/a.jpg' ], [ 'url' => 'https://example.test/b.jpg' ] ] ] );
		foreach ( [ 71, 72 ] as $id ) {
			$this->call( 'stonewright/media-upload', [ 'url' => 'https://example.test/' . $id . '.jpg' ], fn () => $this->make_attachment( $id ), [ 'id' => $id, 'url' => 'https://example.test/' . $id, 'mime' => 'image/jpeg' ] );
		}
		\Stonewright\WpMcp\Security\RescueGuard::leave( [ 'ok' => true, 'uploaded' => 2, 'failed' => 0, 'items' => [ [ 'ok' => true, 'id' => 71 ], [ 'ok' => true, 'id' => 72 ] ] ] );

		$rows = $this->ledger_rows();
		self::assertSame( [ '71', '72' ], array_column( $rows, 'resource_id' ) );
		self::assertSame( [ 'stonewright/media-upload', 'stonewright/media-upload' ], array_column( $rows, 'ability' ) );
	}

	public function test_stock_import_and_asset_normalization_record_each_attachment_they_add(): void {
		$this->call( 'stonewright/stock-image-import', [ 'url' => 'https://example.test/s.jpg' ], fn () => $this->make_attachment( 75 ), [ 'ok' => true, 'id' => 75, 'url' => 'u', 'caption' => 'c', 'provider' => 'openverse', 'attribution' => 'a' ] );
		$this->call( 'stonewright/design-normalize-assets', [ 'spec' => [] ], function (): void {
			$this->make_attachment( 81 );
			$this->make_attachment( 82 );
		}, [ 'spec' => [], 'replaced' => 2, 'attachments' => [ 81, 82 ] ] );
		$this->call( 'stonewright/design-normalize-assets', [ 'spec' => [], 'sideload' => false ], static function (): void {}, [ 'spec' => [], 'replaced' => 0, 'attachments' => [] ] );

		self::assertSame( [ '75', '81', '82' ], array_column( $this->ledger_rows(), 'resource_id' ) );
		self::assertSame( [ 'Created media' ], array_unique( array_map( static fn ( array $row ): string => substr( $row['summary'], 0, 13 ), $this->ledger_rows() ) ) );
	}

	public function test_the_design_abilities_that_sideload_assets_record_the_attachments_they_add(): void {
		$this->call( 'stonewright/design-apply-to-post', [ 'post_id' => 31 ], fn () => $this->make_attachment( 91 ), [ 'post_id' => 31, 'spec_sha8' => 'abcd1234', 'sideloaded_assets' => [ 91 ], 'diagnostics' => [] ] );
		$this->call( 'stonewright/design-spec-to-elementor-v3', [ 'spec' => [] ], fn () => $this->make_attachment( 92 ), [ 'sideloaded_assets' => [ 92 ] ] );
		$this->call( 'stonewright/design-spec-to-elementor-v3', [ 'spec' => [] ], static function (): void {}, [ 'sideloaded_assets' => [] ] );

		self::assertSame( [ '91', '92' ], array_column( $this->ledger_rows(), 'resource_id' ) );
		self::assertSame( [ 'stonewright/design-apply-to-post', 'stonewright/design-spec-to-elementor-v3' ], array_column( $this->ledger_rows(), 'ability' ) );
	}

	public function test_a_failed_upload_records_nothing(): void {
		$this->call( 'stonewright/media-upload', [ 'url' => 'https://example.test/hero.jpg' ], static function (): void {}, new \WP_Error( 'http_request_failed', 'No.' ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_a_description_with_a_credential_is_masked_and_not_restorable(): void {
		$this->make_attachment( 70, [ 'post_content' => "Shot by someone\napi_key: sk_live_abcd1234efgh5678" ] );

		( new SetAlt() )->execute( [ 'id' => 70, 'alt' => 'New alt' ] );

		$row = $this->row_of( 'media' );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'sk_live_abcd1234efgh5678', $this->stored_text() );
	}

	public function test_restore_needs_the_upload_capability_and_an_existing_attachment(): void {
		$this->make_attachment();
		( new SetAlt() )->execute( [ 'id' => 70, 'alt' => 'New alt' ] );
		$row                                           = $this->row_of( 'media' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'upload_files' !== $cap;

		self::assertSame( 'permission_denied', OtherFamilies::restore( $row['change_id'] )['detail'] );
		self::assertSame( 'New alt', $this->meta( 70, '_wp_attachment_image_alt' ) );

		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => true;
		unset( $GLOBALS['stonewright_test_posts'][70] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'attachment_missing' ], [ $restore['status'], $restore['detail'] ] );
	}

	public function test_the_image_has_the_fields_the_meta_and_the_file_facts(): void {
		$this->make_attachment();

		$image = MediaAdapter::image( 'attachment', '70' );

		self::assertSame( [ 'file', 'meta', 'meta_absent', 'post', 'v' ], array_keys( $image ) );
		self::assertSame( 'image/jpeg', $image['post']['post_mime_type'] );
		self::assertNull( MediaAdapter::image( 'attachment', '404' ) );
		$this->make_post( 12, [ 'post_type' => 'page' ] );
		self::assertNull( MediaAdapter::image( 'attachment', '12' ), 'Only attachments are media.' );
	}
}
