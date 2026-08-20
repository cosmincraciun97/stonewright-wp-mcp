<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\ListRegisteredBlocks;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ListRegisteredBlocks
 */
final class ListRegisteredBlocksTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/paragraph' => (object) [
				'title'           => 'Paragraph',
				'category'        => 'text',
				'description'     => 'Start with the basic building block of all narrative.',
				'icon'            => 'editor-paragraph',
				'keywords'        => [ 'text' ],
				'supports'        => [ 'anchor' => true ],
				'example'         => [ 'attributes' => [ 'content' => 'Hello' ] ],
				'render_callback' => null,
			],
			'vendor/card'    => (object) [
				'title'           => 'Vendor Card',
				'category'        => 'widgets',
				'description'     => 'Third-party card.',
				'icon'            => 'index-card',
				'keywords'        => [ 'card' ],
				'supports'        => [],
				'example'         => [],
				'render_callback' => static fn(): string => '<div></div>',
			],
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_list_registered_blocks_summary_is_name_and_title_only(): void {
		$result = ( new ListRegisteredBlocks() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertSame( 'core/paragraph', $result['blocks'][0]['name'] );
		self::assertSame( 'Paragraph', $result['blocks'][0]['title'] );
		self::assertArrayNotHasKey( 'description', $result['blocks'][0] );
		self::assertArrayNotHasKey( 'supports', $result['blocks'][0] );
		self::assertArrayHasKey( 'truncated', $result );
	}
}
