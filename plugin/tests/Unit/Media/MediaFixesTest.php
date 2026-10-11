<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Media\ListMedia;
use Stonewright\WpMcp\Abilities\Media\SetAlt;
use Stonewright\WpMcp\Abilities\Media\StockImageImport;
use Stonewright\WpMcp\Abilities\Media\UploadMedia;
use Stonewright\WpMcp\Abilities\Media\UploadMediaBatch;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Media writes: one token covers a whole batch, uploads and imports are capped and go through the media
 * sideload helper, set-alt asks for a token, and a search looks at the whole library.
 *
 * @covers \Stonewright\WpMcp\Abilities\Media\UploadMedia
 * @covers \Stonewright\WpMcp\Abilities\Media\UploadMediaBatch
 * @covers \Stonewright\WpMcp\Abilities\Media\SetAlt
 * @covers \Stonewright\WpMcp\Abilities\Media\ListMedia
 * @covers \Stonewright\WpMcp\Abilities\Media\StockImageImport
 * @covers \Stonewright\WpMcp\Media\RemoteDownload
 */
final class MediaFixesTest extends TestCase {

	/** A real 1x1 PNG, so a type check on the downloaded bytes has something to read. */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']      = true;
		$GLOBALS['stonewright_test_current_user_id']     = 1;
		$GLOBALS['stonewright_test_user_caps']           = [ 'upload_files' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback']   = null;
		$GLOBALS['stonewright_test_options']             = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_posts']               = [];
		$GLOBALS['stonewright_test_next_post_id']        = 2001;
		$GLOBALS['stonewright_test_wpdb_inserts']        = [];
		$GLOBALS['stonewright_test_sideloads']           = [];
		unset( $GLOBALS['stonewright_test_download_url'], $GLOBALS['stonewright_test_media_handle_sideload'], $GLOBALS['stonewright_test_filters']['stonewright_media_max_bytes'] );
		AuditLog::reset_request_state();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_posts']             = [];
		unset( $GLOBALS['stonewright_test_download_url'], $GLOBALS['stonewright_test_media_handle_sideload'], $GLOBALS['stonewright_test_filters']['stonewright_media_max_bytes'] );
		AuditLog::reset_request_state();
	}

	/** @return list<array<string, mixed>> */
	private function rows_for( string $ability ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? '' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	private function production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
	}

	private function attachment( int $id, string $title, string $alt = '', string $excerpt = '' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'             => $id,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => $title,
			'post_content'   => '',
			'post_excerpt'   => $excerpt,
			'post_parent'    => 0,
			'post_name'      => sanitize_title( $title ),
			'post_mime_type' => 'image/jpeg',
			'meta'           => [ '_wp_attachment_image_alt' => $alt ],
		];
	}

	// --- P0: the outer token covers a batch ----------------------------------------------------------

	public function test_a_production_safe_batch_uploads_every_item_with_one_token(): void {
		$this->production_safe();
		$args = [
			'items' => [
				[ 'url' => 'https://assets.example.test/a.png' ],
				[ 'url' => 'https://assets.example.test/b.png' ],
			],
		];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/media-upload-batch', $args );

		$result = ( new UploadMediaBatch() )->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], json_encode( $result ) );
		self::assertSame( 2, $result['uploaded'] );
		self::assertSame( 0, $result['failed'] );
		// One audit row for the batch and one for each item, as before.
		self::assertCount( 1, $this->rows_for( 'stonewright/media-upload-batch' ) );
		self::assertCount( 2, $this->rows_for( 'stonewright/media-upload' ) );
	}

	public function test_a_production_safe_batch_without_a_token_uploads_nothing(): void {
		$this->production_safe();

		$result = ( new UploadMediaBatch() )->execute( [ 'items' => [ [ 'url' => 'https://assets.example.test/a.png' ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $this->rows_for( 'stonewright/media-upload' ) );
	}

	public function test_a_production_safe_batch_does_not_accept_a_token_for_another_batch(): void {
		$this->production_safe();
		$signed = [ 'items' => [ [ 'url' => 'https://assets.example.test/a.png' ] ] ];
		$token  = ConfirmationToken::issue( 'stonewright/media-upload-batch', $signed );

		$result = ( new UploadMediaBatch() )->execute( [ 'items' => [ [ 'url' => 'https://assets.example.test/other.png' ] ], 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_a_single_upload_still_asks_for_its_own_token_in_production_safe_mode(): void {
		$this->production_safe();

		$result = ( new UploadMedia() )->execute( [ 'url' => 'https://assets.example.test/a.png' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_a_failed_item_is_reported_and_does_not_hide_the_others(): void {
		$GLOBALS['stonewright_test_download_url'] = static function ( string $url ) {
			if ( str_contains( $url, 'broken' ) ) {
				return new \WP_Error( 'download_failed', 'Not found.' );
			}
			$path = tempnam( sys_get_temp_dir(), 'sw-dl-' );
			file_put_contents( $path, base64_decode( self::PNG ) );
			return $path;
		};

		$result = ( new UploadMediaBatch() )->execute(
			[ 'items' => [ [ 'url' => 'https://assets.example.test/broken.png' ], [ 'url' => 'https://assets.example.test/ok.png' ] ] ]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( 1, $result['uploaded'] );
		self::assertSame( 1, $result['failed'] );
		self::assertFalse( $result['items'][0]['ok'] );
	}

	// --- P2: media-upload parent and size ------------------------------------------------------------

	public function test_upload_needs_edit_access_to_the_parent_post(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return 5 === (int) ( $args[0] ?? 0 );
			}
			return true;
		};

		self::assertTrue( ( new UploadMedia() )->permission_callback( [ 'url' => 'https://a.example.test/a.png', 'parent_post_id' => 5 ] ) );

		$denied = ( new UploadMedia() )->permission_callback( [ 'url' => 'https://a.example.test/a.png', 'parent_post_id' => 6 ] );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_forbidden', $denied->get_error_code() );

		$batch = ( new UploadMediaBatch() )->permission_callback( [ 'items' => [ [ 'url' => 'https://a.example.test/a.png' ], [ 'url' => 'https://a.example.test/b.png', 'parent_post_id' => 6 ] ] ] );
		self::assertInstanceOf( \WP_Error::class, $batch );
		self::assertSame( 'stonewright_forbidden', $batch->get_error_code() );
	}

	public function test_upload_refuses_a_payload_over_the_size_cap(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_media_max_bytes'] = static fn (): int => 30;

		$base64 = ( new UploadMedia() )->execute( [ 'base64' => base64_encode( str_repeat( 'x', 100 ) ), 'filename' => 'big.png' ] );
		self::assertInstanceOf( \WP_Error::class, $base64 );
		self::assertSame( 'stonewright_media_too_large', $base64->get_error_code() );

		$GLOBALS['stonewright_test_download_url'] = static function (): string {
			$path = tempnam( sys_get_temp_dir(), 'sw-dl-' );
			file_put_contents( $path, str_repeat( 'x', 100 ) );
			return $path;
		};
		$remote = ( new UploadMedia() )->execute( [ 'url' => 'https://assets.example.test/big.png' ] );
		self::assertInstanceOf( \WP_Error::class, $remote );
		self::assertSame( 'stonewright_media_too_large', $remote->get_error_code() );
	}

	// --- P2: set-alt asks for a token ----------------------------------------------------------------

	public function test_set_alt_requires_a_token_in_production_safe_mode_and_writes_in_development(): void {
		$this->attachment( 30, 'Photo' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn (): bool => true;

		$dev = ( new SetAlt() )->execute( [ 'id' => 30, 'alt' => 'A stone wall' ] );
		self::assertIsArray( $dev );
		self::assertSame( 'A stone wall', get_post_meta( 30, '_wp_attachment_image_alt', true ) );

		$this->production_safe();
		$blocked = ( new SetAlt() )->execute( [ 'id' => 30, 'alt' => 'Another' ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertSame( 'A stone wall', get_post_meta( 30, '_wp_attachment_image_alt', true ) );

		$args                       = [ 'id' => 30, 'alt' => 'Another' ];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/media-set-alt', $args );
		$ok                         = ( new SetAlt() )->execute( $args );
		self::assertIsArray( $ok );
		self::assertSame( 'Another', get_post_meta( 30, '_wp_attachment_image_alt', true ) );
	}

	// --- P1: media-list search covers the whole library -----------------------------------------------

	public function test_search_finds_an_old_item_beyond_the_newest_two_hundred(): void {
		$this->attachment( 1, 'Granite quarry survey', 'old granite photo' );
		for ( $id = 2; $id <= 260; $id++ ) {
			$this->attachment( $id, 'Filler ' . $id, 'filler' );
		}

		$by_title = ( new ListMedia() )->execute( [ 'search' => 'granite quarry' ] );
		self::assertIsArray( $by_title );
		self::assertSame( [ 1 ], array_column( $by_title['items'], 'id' ) );

		$by_alt = ( new ListMedia() )->execute( [ 'search' => 'old granite' ] );
		self::assertSame( [ 1 ], array_column( $by_alt['items'], 'id' ) );
	}

	public function test_search_pages_through_the_matches_and_without_search_pages_the_library(): void {
		for ( $id = 1; $id <= 30; $id++ ) {
			$this->attachment( $id, 'Timber frame ' . $id );
		}

		$page_two = ( new ListMedia() )->execute( [ 'search' => 'timber', 'per_page' => 10, 'page' => 2 ] );
		self::assertSame( range( 20, 11 ), array_column( $page_two['items'], 'id' ) );

		$plain = ( new ListMedia() )->execute( [ 'per_page' => 5, 'page' => 2 ] );
		self::assertSame( range( 25, 21 ), array_column( $plain['items'], 'id' ) );
	}

	// --- P1: stock image import ------------------------------------------------------------------------

	public function test_stock_import_sideloads_a_downloaded_file_even_when_the_url_has_no_extension(): void {
		$seen = [];
		$GLOBALS['stonewright_test_download_url'] = static function ( string $url ) use ( &$seen ): string {
			$seen[] = $url;
			$path   = tempnam( sys_get_temp_dir(), 'sw-dl-' );
			file_put_contents( $path, base64_decode( self::PNG ) );
			return $path;
		};
		$GLOBALS['stonewright_test_media_handle_sideload'] = static function ( array $file_array, int $post_id ) use ( &$seen ): int {
			$seen[] = $file_array;
			$id     = (int) $GLOBALS['stonewright_test_next_post_id']++;
			$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => (string) $file_array['name'],
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_parent'    => $post_id,
				'post_name'      => 'x',
				'post_mime_type' => 'image/png',
				'meta'           => [],
			];
			return $id;
		};

		$result = ( new StockImageImport() )->execute(
			[
				'url'      => 'https://images.example.test/photo-1234?w=1200&fit=crop',
				'provider' => 'unsplash',
				'creator'  => 'A. Photographer',
				'title'    => 'Stone steps',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'https://images.example.test/photo-1234?w=1200&fit=crop', $seen[0] );
		self::assertSame( 'png', pathinfo( (string) $seen[1]['name'], PATHINFO_EXTENSION ) );
		self::assertSame( 'unsplash', get_post_meta( $result['id'], '_stonewright_stock_provider', true ) );
	}

	public function test_stock_import_refuses_a_download_that_is_not_an_image(): void {
		$GLOBALS['stonewright_test_download_url'] = static function (): string {
			$path = tempnam( sys_get_temp_dir(), 'sw-dl-' );
			file_put_contents( $path, '<html>not an image</html>' );
			return $path;
		};

		$result = ( new StockImageImport() )->execute( [ 'url' => 'https://images.example.test/page' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_stock_image_invalid', $result->get_error_code() );
	}

	public function test_stock_import_refuses_a_download_over_the_size_cap(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_media_max_bytes'] = static fn (): int => 30;
		$GLOBALS['stonewright_test_download_url'] = static function (): string {
			$path = tempnam( sys_get_temp_dir(), 'sw-dl-' );
			file_put_contents( $path, base64_decode( self::PNG ) . str_repeat( 'x', 100 ) );
			return $path;
		};

		$result = ( new StockImageImport() )->execute( [ 'url' => 'https://images.example.test/big.png' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_media_too_large', $result->get_error_code() );
	}

	public function test_stock_import_checks_edit_access_to_the_parent_post(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'edit_post' !== $cap;

		$result = ( new StockImageImport() )->permission_callback( [ 'url' => 'https://images.example.test/a.png', 'parent_post_id' => 9 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}
}
