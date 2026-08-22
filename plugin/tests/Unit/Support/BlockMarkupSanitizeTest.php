<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;
use Stonewright\WpMcp\Abilities\Gutenberg\ApplyToPost;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * @covers \Stonewright\WpMcp\Support\BlockMarkup
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToGutenberg
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ApplyToPost
 */
final class BlockMarkupSanitizeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts'         => true,
			'edit_post'          => true,
			'edit_pages'         => true,
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_posts']           = [
			9 => (object) [
				'ID'           => 9,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Sanitize target',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_sanitize_strips_style_tags_and_keeps_block_markup(): void {
		$html = '<!-- wp:paragraph --><p>Hello</p><style>.x{color:red}</style><!-- /wp:paragraph -->';
		$out  = BlockMarkup::sanitize( $html );
		self::assertIsString( $out );
		self::assertStringContainsString( 'Hello', $out );
		self::assertStringNotContainsString( '<style', $out );
	}

	public function test_spec_to_gutenberg_write_runs_block_markup_sanitize(): void {
		$result = ( new SpecToGutenberg() )->execute(
			[
				'post_id' => 9,
				'spec'    => [
					'page'     => [ 'title' => 'Safe' ],
					'sections' => [
						[
							'id'     => 'hero',
							'blocks' => [
								[ 'type' => 'heading', 'level' => 2, 'text' => 'Safe heading' ],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		$content = (string) ( $result['content'] ?? $GLOBALS['stonewright_test_posts'][9]->post_content );
		self::assertStringContainsString( 'Safe heading', $content );
		self::assertStringNotContainsString( '<style', $content );
	}

	public function test_gutenberg_apply_to_post_write_runs_block_markup_sanitize(): void {
		$result = ( new ApplyToPost() )->execute(
			[
				'post_id' => 9,
				'spec'    => [
					'page'     => [ 'title' => 'Apply' ],
					'sections' => [
						[
							'id'     => 's0',
							'blocks' => [
								[ 'type' => 'paragraph', 'text' => 'Applied copy' ],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertStringNotContainsString( '<style', (string) $GLOBALS['stonewright_test_posts'][9]->post_content );
		self::assertStringContainsString( 'Applied copy', (string) $GLOBALS['stonewright_test_posts'][9]->post_content );
	}
}
