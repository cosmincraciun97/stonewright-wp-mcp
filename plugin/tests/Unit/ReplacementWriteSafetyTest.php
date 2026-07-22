<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToGutenberg
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToElementorV3
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class ReplacementWriteSafetyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_posts']   = [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Contract Page',
				'post_content' => '<p>Old</p>',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'contract-page',
				'meta'         => [
					'_elementor_data' => '[]',
				],
			],
		];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts']   = [];
	}

	public function test_gutenberg_full_replacement_requires_token_in_production_safe_mode(): void {
		$result = ( new SpecToGutenberg() )->execute(
			[
				'post_id' => 1,
				'append'  => false,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_full_replacement_requires_token_in_production_safe_mode(): void {
		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => true,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_elementor_write_failure_returns_wp_error(): void {
		$GLOBALS['stonewright_test_options']                 = [];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => false,
				'spec'    => $this->spec(),
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		// Surfaced from ElementorData::last_write_error(), not the generic write_failed wrapper.
		$this->assertSame( 'stonewright_elementor_readback_failed', $result->get_error_code() );
	}

	public function test_elementor_page_replacement_hides_theme_title_and_uses_header_footer_template(): void {
		$GLOBALS['stonewright_test_options'] = [];

		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => true,
				'spec'    => $this->spec(),
			]
		);

		$this->assertIsArray( $result );
		$post_meta = (array) $GLOBALS['stonewright_test_posts'][1]->meta;
		$this->assertSame( 'elementor_header_footer', $post_meta['_wp_page_template'] ?? null );
		$this->assertIsArray( $post_meta['_elementor_page_settings'] ?? null );
		$this->assertSame( 'yes', $post_meta['_elementor_page_settings']['hide_title'] ?? null );
	}

	public function test_elementor_v3_sideloads_background_image_refs_before_write(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_asset_responses'] = [
			'https://cdn.example.com/bg/hero-glow.png' => [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'image/png' ],
				'body'     => str_repeat( 'G', 200 ),
			],
		];

		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => true,
				'spec'    => [
					'page'     => [ 'title' => 'Background Page' ],
					'assets'   => [
						[ 'id' => 'asset_glow', 'url' => 'https://cdn.example.com/bg/hero-glow.png' ],
					],
					'sections' => [
						[
							'id'         => 'hero',
							'background' => [
								'imageRef' => 'asset_glow',
								'position' => 'center center',
								'size'     => 'cover',
							],
							'blocks'     => [
								[ 'type' => 'heading', 'text' => 'Hero' ],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['sideloaded_assets'] );

		$post_meta = (array) $GLOBALS['stonewright_test_posts'][1]->meta;
		$tree      = json_decode( stripslashes( (string) $post_meta['_elementor_data'] ), true );
		$asset_id  = (int) $result['sideloaded_assets'][0];

		$this->assertSame( 'https://example.test/wp-content/uploads/attachment-' . $asset_id . '.txt', $tree[0]['settings']['background_image']['url'] );
		$this->assertSame( $asset_id, $tree[0]['settings']['background_image']['id'] );
		$this->assertSame( 'center center', $tree[0]['settings']['background_position'] );
		$this->assertSame( 'cover', $tree[0]['settings']['background_size'] );
	}

	public function test_elementor_v3_sideloads_gallery_image_refs_before_write(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_asset_responses'] = [
			'https://cdn.example.com/gallery/one.png' => [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'image/png' ],
				'body'     => str_repeat( 'A', 200 ),
			],
			'https://cdn.example.com/gallery/two.png' => [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'image/png' ],
				'body'     => str_repeat( 'B', 200 ),
			],
		];

		$result = ( new SpecToElementorV3() )->execute(
			[
				'post_id' => 1,
				'replace' => true,
				'spec'    => [
					'page'     => [ 'title' => 'Gallery Page' ],
					'assets'   => [
						[ 'id' => 'asset_one', 'url' => 'https://cdn.example.com/gallery/one.png' ],
						[ 'id' => 'asset_two', 'url' => 'https://cdn.example.com/gallery/two.png' ],
					],
					'sections' => [
						[
							'id'     => 'gallery',
							'blocks' => [
								[
									'type'    => 'image-gallery',
									'columns' => 2,
									'images'  => [
										[ 'assetRef' => 'asset_one', 'url' => 'https://cdn.example.com/gallery/one.png' ],
										[ 'assetRef' => 'asset_two', 'url' => 'https://cdn.example.com/gallery/two.png' ],
									],
								],
							],
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['sideloaded_assets'] );

		$post_meta = (array) $GLOBALS['stonewright_test_posts'][1]->meta;
		$tree      = json_decode( stripslashes( (string) $post_meta['_elementor_data'] ), true );
		$gallery   = $tree[0]['elements'][0];
		$first_id  = (int) $result['sideloaded_assets'][0];
		$second_id = (int) $result['sideloaded_assets'][1];

		$this->assertSame( 'image-gallery', $gallery['widgetType'] );
		$this->assertSame( $first_id, $gallery['settings']['wp_gallery'][0]['id'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/attachment-' . $first_id . '.txt', $gallery['settings']['wp_gallery'][0]['url'] );
		$this->assertSame( $second_id, $gallery['settings']['wp_gallery'][1]['id'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/attachment-' . $second_id . '.txt', $gallery['settings']['wp_gallery'][1]['url'] );
	}

	private function spec(): array {
		return [
			'page'     => [ 'title' => 'Contract Page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[
							'type' => 'heading',
							'text' => 'Hello',
						],
					],
				],
			],
		];
	}
}
