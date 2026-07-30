<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Renderers;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;

/**
 * @covers \Stonewright\WpMcp\Renderers\GutenbergSpecRenderer
 */
final class GutenbergSpecRendererColumnsTest extends TestCase {

	public function test_row_with_three_columns_maps_to_core_columns(): void {
		$spec = [
			'page'     => [ 'title' => 'Columns test' ],
			'tokens'   => [
				'colors' => [
					'primary'    => '#0ea5e9',
					'background' => '#0f172a',
					'text'       => '#f8fafc',
				],
			],
			'sections' => [
				[
					'id'         => 'pricing',
					'background' => [ 'color' => '#0f172a' ],
					'padding'    => [
						'top'    => 48,
						'right'  => 24,
						'bottom' => 48,
						'left'   => 24,
					],
					'blocks'     => [
						[
							'type'   => 'row',
							'blocks' => [
								[
									'type'   => 'column',
									'blocks' => [
										[ 'type' => 'heading', 'level' => 3, 'text' => 'A' ],
									],
								],
								[
									'type'   => 'column',
									'blocks' => [
										[ 'type' => 'heading', 'level' => 3, 'text' => 'B' ],
									],
								],
								[
									'type'   => 'column',
									'blocks' => [
										[ 'type' => 'heading', 'level' => 3, 'text' => 'C' ],
									],
								],
							],
						],
					],
				],
			],
		];

		$diagnostics = [];
		$result      = GutenbergSpecRenderer::render( $spec, $diagnostics );
		self::assertIsArray( $result );
		self::assertSame( [], $diagnostics );

		$section = $result[0] ?? null;
		self::assertIsArray( $section );
		self::assertSame( 'core/group', $section['blockName'] );
		self::assertSame( 'full', $section['attrs']['align'] ?? null );
		self::assertStringContainsString( 'alignfull', (string) ( $section['innerHTML'] ?? '' ) );
		self::assertStringContainsString( 'has-background', (string) ( $section['innerHTML'] ?? '' ) );

		$row = $section['innerBlocks'][0] ?? null;
		self::assertIsArray( $row );
		self::assertSame( 'core/columns', $row['blockName'] );
		self::assertCount( 3, $row['innerBlocks'] );
		foreach ( $row['innerBlocks'] as $col ) {
			self::assertSame( 'core/column', $col['blockName'] );
		}
	}

	public function test_button_uses_primary_token_inline_and_has_background_class(): void {
		$spec = [
			'page'     => [ 'title' => 'Button test' ],
			'tokens'   => [
				'colors' => [
					'primary' => '#2563eb',
				],
			],
			'sections' => [
				[
					'id'     => 'cta',
					'blocks' => [
						[
							'type' => 'button',
							'text' => 'Book now',
							'url'  => 'https://example.com/book',
						],
					],
				],
			],
		];

		$diagnostics = [];
		$result      = GutenbergSpecRenderer::render( $spec, $diagnostics );
		self::assertIsArray( $result );
		$buttons = $result[0]['innerBlocks'][0] ?? null;
		self::assertIsArray( $buttons );
		self::assertSame( 'core/buttons', $buttons['blockName'] );
		$btn = $buttons['innerBlocks'][0] ?? null;
		self::assertIsArray( $btn );
		self::assertSame( 'core/button', $btn['blockName'] );
		self::assertSame( '#2563eb', $btn['attrs']['style']['color']['background'] ?? null );
		self::assertStringContainsString( 'has-background', (string) ( $btn['innerHTML'] ?? '' ) );
		self::assertStringContainsString( 'background-color:#2563eb', (string) ( $btn['innerHTML'] ?? '' ) );
	}

	public function test_orphan_column_becomes_group(): void {
		$spec = [
			'page'     => [ 'title' => 'Orphan column' ],
			'sections' => [
				[
					'id'     => 'orphan',
					'blocks' => [
						[
							'type'   => 'column',
							'blocks' => [
								[ 'type' => 'paragraph', 'text' => 'solo' ],
							],
						],
					],
				],
			],
		];

		$diagnostics = [];
		$result      = GutenbergSpecRenderer::render( $spec, $diagnostics );
		self::assertIsArray( $result );
		$orphan = $result[0]['innerBlocks'][0] ?? null;
		self::assertIsArray( $orphan );
		self::assertSame( 'core/group', $orphan['blockName'] );
	}

	public function test_hero_role_with_image_and_text_uses_media_text(): void {
		$spec = [
			'page'     => [ 'title' => 'Hero' ],
			'tokens'   => [ 'colors' => [ 'primary' => '#111827' ] ],
			'sections' => [
				[
					'id'         => 'hero',
					'role'       => 'hero',
					'background' => [ 'color' => '#0f172a' ],
					'blocks'     => [
						[
							'type' => 'image',
							'url'  => 'https://example.com/hero.jpg',
							'alt'  => 'Hero',
						],
						[
							'type'  => 'heading',
							'level' => 1,
							'text'  => 'Welcome',
						],
						[
							'type' => 'button',
							'text' => 'Start',
							'url'  => 'https://example.com',
						],
					],
				],
			],
		];

		$diagnostics = [];
		$result      = GutenbergSpecRenderer::render( $spec, $diagnostics );
		self::assertIsArray( $result );
		$hero = $result[0] ?? null;
		self::assertIsArray( $hero );
		self::assertSame( 'core/media-text', $hero['blockName'] );
		self::assertSame( 'full', $hero['attrs']['align'] ?? null );
	}
}
