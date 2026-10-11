<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Media\GetMedia;
use Stonewright\WpMcp\Abilities\Media\ListMedia;
use Stonewright\WpMcp\Abilities\Media\OptimizeMedia;
use Stonewright\WpMcp\Media\AttachmentMetaSnapshot;
use Stonewright\WpMcp\Security\AuditLog;

/**
 * media-get and media-list check read_post per attachment, and media-optimize snapshots the attachment
 * metadata and merges the regenerated metadata into it.
 *
 * @covers \Stonewright\WpMcp\Abilities\Media\GetMedia
 * @covers \Stonewright\WpMcp\Abilities\Media\ListMedia
 * @covers \Stonewright\WpMcp\Abilities\Media\OptimizeMedia
 * @covers \Stonewright\WpMcp\Media\AttachmentMetaSnapshot
 */
final class MediaReadAndOptimizeTest extends TestCase {

	/** @var list<int> Attachment ids the user may read. */
	private array $readable = [];

	protected function setUp(): void {
		$this->readable                                 = [ 301 ];
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_user_caps']          = [ 'read' => true, 'upload_files' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_can_callback']  = function ( string $cap, mixed ...$args ): bool {
			if ( 'read_post' === $cap ) {
				return in_array( (int) ( $args[0] ?? 0 ), $this->readable, true );
			}
			return in_array( $cap, [ 'read', 'upload_files', 'edit_post' ], true );
		};
		$GLOBALS['stonewright_test_options']            = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']              = [
			301 => $this->attachment( 301, 'Readable' ),
			302 => $this->attachment( 302, 'Private one' ),
		];
		$GLOBALS['stonewright_test_attachment_meta']    = [];
		unset( $GLOBALS['stonewright_test_generated_attachment_meta'], $GLOBALS['stonewright_test_update_post_meta_return'], $GLOBALS['stonewright_test_update_post_meta_returns'] );
		AuditLog::reset_request_state();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_attachment_meta']   = [];
		unset( $GLOBALS['stonewright_test_generated_attachment_meta'], $GLOBALS['stonewright_test_update_post_meta_return'], $GLOBALS['stonewright_test_update_post_meta_returns'] );
		AuditLog::reset_request_state();
	}

	private function attachment( int $id, string $title ): object {
		return (object) [
			'ID'             => $id,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => $title,
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_parent'    => 0,
			'post_name'      => sanitize_title( $title ),
			'post_mime_type' => 'image/jpeg',
			'meta'           => [ '_wp_attachment_image_alt' => '' ],
		];
	}

	public function test_get_refuses_an_attachment_the_user_cannot_read(): void {
		$ok = ( new GetMedia() )->execute( [ 'id' => 301 ] );
		self::assertIsArray( $ok );
		self::assertSame( 301, $ok['id'] );

		$denied = ( new GetMedia() )->execute( [ 'id' => 302 ] );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_forbidden', $denied->get_error_code() );
	}

	public function test_list_leaves_out_attachments_the_user_cannot_read(): void {
		$result = ( new ListMedia() )->execute( [ 'mime_type' => '' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 301 ], array_column( $result['items'], 'id' ) );
		self::assertSame( 1, $result['count'] );
	}

	public function test_optimize_merges_the_new_metadata_into_the_existing_metadata(): void {
		$GLOBALS['stonewright_test_attachment_meta'][301]         = [
			'width'      => 800,
			'height'     => 600,
			'file'       => '2026/01/a.jpg',
			'image_meta' => [ 'caption' => 'kept' ],
			'sizes'      => [ 'thumbnail' => [ 'file' => 'a-150.jpg' ], 'legacy' => [ 'file' => 'a-legacy.jpg' ] ],
			'plugin_key' => 'kept',
		];
		$GLOBALS['stonewright_test_generated_attachment_meta']    = [
			'width' => 800,
			'file'  => '2026/01/a.jpg',
			'sizes' => [ 'thumbnail' => [ 'file' => 'a-150-new.jpg' ], 'medium' => [ 'file' => 'a-300.jpg' ] ],
		];

		$result = ( new OptimizeMedia() )->execute( [ 'id' => 301 ] );

		self::assertIsArray( $result );
		$stored = $GLOBALS['stonewright_test_attachment_meta'][301];
		self::assertSame( 'kept', $stored['plugin_key'] );
		self::assertSame( [ 'caption' => 'kept' ], $stored['image_meta'] );
		self::assertSame( 600, $stored['height'] );
		self::assertSame( 'a-150-new.jpg', $stored['sizes']['thumbnail']['file'] );
		self::assertSame( 'a-legacy.jpg', $stored['sizes']['legacy']['file'] );
		self::assertSame( 'a-300.jpg', $stored['sizes']['medium']['file'] );
		self::assertEqualsCanonicalizing( [ 'thumbnail', 'legacy', 'medium' ], $result['sizes'] );
	}

	public function test_optimize_snapshots_the_previous_metadata_and_returns_the_snapshot_id(): void {
		$before                                                = [ 'width' => 800, 'file' => 'a.jpg', 'sizes' => [ 'thumbnail' => [ 'file' => 'a-150.jpg' ] ] ];
		$GLOBALS['stonewright_test_attachment_meta'][301]      = $before;
		$GLOBALS['stonewright_test_generated_attachment_meta'] = [ 'width' => 800, 'file' => 'a.jpg', 'sizes' => [ 'thumbnail' => [ 'file' => 'new.jpg' ] ] ];

		$result = ( new OptimizeMedia() )->execute( [ 'id' => 301 ] );

		self::assertIsArray( $result );
		self::assertMatchesRegularExpression( '/^mmeta_[a-f0-9]{16}$/', $result['snapshot_id'] );
		$snapshot = AttachmentMetaSnapshot::get( 301, $result['snapshot_id'] );
		self::assertSame( $before, $snapshot['metadata'] ?? null );
	}

	public function test_optimize_stops_when_the_snapshot_cannot_be_stored(): void {
		$GLOBALS['stonewright_test_attachment_meta'][301]       = [ 'file' => 'a.jpg' ];
		$GLOBALS['stonewright_test_generated_attachment_meta']  = [ 'file' => 'b.jpg' ];
		$GLOBALS['stonewright_test_update_post_meta_returns']   = [ AttachmentMetaSnapshot::META_KEY => false ];

		$result = ( new OptimizeMedia() )->execute( [ 'id' => 301 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [ 'file' => 'a.jpg' ], $GLOBALS['stonewright_test_attachment_meta'][301] );
	}

	public function test_the_snapshot_history_is_bounded(): void {
		$GLOBALS['stonewright_test_attachment_meta'][301] = [ 'file' => 'a.jpg' ];
		$ids                                              = [];
		for ( $i = 0; $i < AttachmentMetaSnapshot::LIMIT + 2; $i++ ) {
			$ids[] = AttachmentMetaSnapshot::take( 301 );
		}

		self::assertNotContains( '', $ids );
		self::assertCount( AttachmentMetaSnapshot::LIMIT, AttachmentMetaSnapshot::all( 301 ) );
		self::assertNull( AttachmentMetaSnapshot::get( 301, $ids[0] ) );
		self::assertNotNull( AttachmentMetaSnapshot::get( 301, $ids[ count( $ids ) - 1 ] ) );
	}
}
