<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Backup;

/**
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class BackupPostMetaRoundTripTest extends TestCase {

	private const POST_ID = 101;

	private const SLASH_SENSITIVE_ELEMENTOR_DATA =
		'[{"id":"a1b2c3","elType":"widget","widgetType":"heading",'
		. '"settings":{"title":"Say \"hi\" \\\\ back","link":{"url":"https:\/\/example.com\/x?a=1"}}}]';

	private const SLASH_SENSITIVE_POST_CONTENT = 'Keep this literal \\ slash';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );

		$GLOBALS['stonewright_test_posts'][ self::POST_ID ] = (object) [
			'ID'           => self::POST_ID,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Backup round-trip fixture',
			'post_content' => self::SLASH_SENSITIVE_POST_CONTENT,
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'backup-round-trip-fixture',
			'meta'         => [
				'_elementor_data' => self::SLASH_SENSITIVE_ELEMENTOR_DATA,
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_snapshot_round_trips_slash_sensitive_payload_byte_faithfully(): void {
		$snapshot_id = Backup::snapshot_post( self::POST_ID );
		self::assertNotSame( '', $snapshot_id, 'snapshot_post() must succeed for slash-sensitive content' );

		$snapshot = Backup::get_snapshot( self::POST_ID, $snapshot_id );
		self::assertIsArray( $snapshot );
		self::assertSame(
			self::SLASH_SENSITIVE_ELEMENTOR_DATA,
			$snapshot['meta']['_elementor_data'],
			'stored snapshot must contain the exact original bytes'
		);
		self::assertSame(
			self::SLASH_SENSITIVE_POST_CONTENT,
			$snapshot['post_content'],
			'stored snapshot must keep a literal backslash in post_content'
		);
	}

	public function test_snapshot_fails_closed_when_storage_short_circuits(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		self::assertSame( '', Backup::snapshot_post( self::POST_ID ), 'verification must fail when readback differs' );

		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_restore_reproduces_original_bytes(): void {
		$snapshot_id = Backup::snapshot_post( self::POST_ID );
		self::assertNotSame( '', $snapshot_id );

		$post = $GLOBALS['stonewright_test_posts'][ self::POST_ID ];
		$meta = (array) ( $post->meta ?? [] );
		$meta['_elementor_data'] = '[{"id":"mutated"}]';
		$post->meta         = $meta;
		$post->post_content = 'mutated-content';
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ] = $post;

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );
		self::assertSame(
			self::SLASH_SENSITIVE_ELEMENTOR_DATA,
			get_post_meta( self::POST_ID, '_elementor_data', true )
		);
		self::assertSame(
			self::SLASH_SENSITIVE_POST_CONTENT,
			(string) get_post( self::POST_ID )->post_content
		);
	}
}
