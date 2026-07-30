<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Media\ListMedia;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Media\ListMedia
 */
final class MediaListTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			101 => (object) [
				'ID'             => 101,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Hero timber frame',
				'post_content'   => 'Reference render for passive house hero.',
				'post_excerpt'   => 'Hero image caption',
				'post_parent'    => 0,
				'post_name'      => 'hero-timber-frame',
				'post_mime_type' => 'image/jpeg',
				'meta'           => [
					'_wp_attachment_image_alt' => 'nZEB hero timber house',
				],
			],
			102 => (object) [
				'ID'             => 102,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Speaker portrait',
				'post_content'   => '',
				'post_excerpt'   => 'Portrait crop',
				'post_parent'    => 0,
				'post_name'      => 'speaker-portrait',
				'post_mime_type' => 'image/png',
				'meta'           => [
					'_wp_attachment_image_alt' => 'speaker headshot',
				],
			],
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_lists_existing_media_by_alt_title_and_caption(): void {
		$result = ( new ListMedia() )->execute(
			[
				'search'    => 'nZEB',
				'mime_type' => 'image',
				'per_page'  => 10,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['count'] );
		self::assertSame( 101, $result['items'][0]['id'] );
		self::assertSame( 'Hero timber frame', $result['items'][0]['title'] );
		self::assertSame( 'nZEB hero timber house', $result['items'][0]['alt'] );
		self::assertSame( 'image/jpeg', $result['items'][0]['mime'] );
		self::assertSame( 'https://example.test/wp-content/uploads/attachment-101.txt', $result['items'][0]['url'] );
	}

	public function test_registry_exposes_media_list_for_asset_reuse(): void {
		$names = array_map(
			static fn ( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);

		self::assertContains( 'stonewright/media-list', $names );
	}
}
