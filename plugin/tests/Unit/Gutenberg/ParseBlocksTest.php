<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks
 */
final class ParseBlocksTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_posts']          = [
			12 => (object) [
				'ID'           => 12,
				'post_content' => '<!-- wp:paragraph --><p>Hello from a long innerHTML dump that must not leak in summary.</p><!-- /wp:paragraph -->',
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_posts']          = [];
	}

	public function test_omitted_response_mode_defaults_to_summary_without_inner_html(): void {
		$result = ( new ParseBlocks() )->execute( [ 'post_id' => 12 ] );

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertArrayHasKey( 'counts_by_name', $result );
		self::assertNotEmpty( $result['blocks'] );
		self::assertArrayNotHasKey( 'innerHTML', $result['blocks'][0] );
		self::assertArrayNotHasKey( 'attrs', $result['blocks'][0] );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( 'long innerHTML dump', $encoded );
	}

	public function test_full_response_mode_restores_inner_html_and_attrs(): void {
		$result = ( new ParseBlocks() )->execute(
			[
				'html'         => '<!-- wp:paragraph --><p>Keep the dump</p><!-- /wp:paragraph -->',
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'full', $result['response_mode'] );
		self::assertArrayHasKey( 'innerHTML', $result['blocks'][0] );
		self::assertArrayHasKey( 'attrs', $result['blocks'][0] );
	}
}
