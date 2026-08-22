<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\SerializeBlocks;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\SerializeBlocks
 */
final class SerializeBlocksTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	public function test_serialize_preserves_button_url_heading_level_and_group_layout(): void {
		$result = ( new SerializeBlocks() )->execute(
			[
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [ 'level' => 3, 'content' => 'Title' ],
					],
					[
						'name'       => 'core/button',
						'attributes' => [
							'url'  => 'https://example.com/go',
							'text' => 'Go',
						],
					],
					[
						'name'        => 'core/group',
						'attributes'  => [
							'layout' => [
								'type'           => 'constrained',
								'justifyContent' => 'center',
							],
						],
						'innerBlocks' => [
							[
								'name'       => 'core/paragraph',
								'attributes' => [ 'content' => 'Inside' ],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		$html = (string) $result['html'];
		self::assertStringContainsString( '"level":3', $html );
		self::assertStringContainsString( 'example.com', $html );
		self::assertStringContainsString( '"type":"constrained"', $html );
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?paragraph/', $html );
		self::assertDoesNotMatchRegularExpression( '/wp:(?:core\/)?group\s+\/>/', $html );
	}
}
