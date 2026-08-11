<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\CallToAction;
use Stonewright\WpMcp\Elementor\Renderer\ProGate;

/**
 * @covers \Stonewright\WpMcp\Elementor\Renderer\CallToAction
 */
final class CallToActionTest extends TestCase {

	public function test_cover_skin_defaults_min_height_radius_alignment(): void {
		// Catalog has call-to-action even offline.
		$diagnostics = [];
		$result      = CallToAction::render(
			[
				'type'        => 'call-to-action',
				'skin'        => 'cover',
				'title'       => 'Book a consult',
				'description' => 'Native cover CTA',
				'button'      => 'Get started',
				'url'         => 'https://example.test/book',
				'image'       => [ 'url' => 'https://example.test/cover.jpg', 'id' => 7 ],
				'overlay'     => '#00000080',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertIsArray( $result );
		self::assertSame( 'call-to-action', $result['widgetType'] );
		self::assertSame( 'cover', $result['settings']['skin'] );
		self::assertSame( 320, $result['settings']['min-height']['size'] );
		self::assertSame( '20', $result['settings']['border_radius']['top'] );
		self::assertSame( 'center', $result['settings']['alignment'] );
		self::assertSame( 'middle', $result['settings']['vertical_position'] );
		self::assertSame( 'https://example.test/cover.jpg', $result['settings']['bg_image']['url'] );
		self::assertSame( '#00000080', $result['settings']['overlay_color'] );
		self::assertSame( [], $diagnostics );
	}

	public function test_never_substitutes_image_box_when_skin_invalid(): void {
		$diagnostics = [];
		$result      = CallToAction::render(
			[
				'type'  => 'call-to-action',
				'skin'  => 'hologram',
				'title' => 'Nope',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertNotEmpty( $diagnostics );
		self::assertSame( CallToAction::ERROR_CODE, $diagnostics[0]['code'] );
		self::assertSame( 'unsupported_skin', $diagnostics[0]['reason'] );
	}

	public function test_custom_min_height_and_radius(): void {
		$result = CallToAction::render(
			[
				'type'          => 'cta',
				'skin'          => 'cover',
				'title'         => 'CTA',
				'min_height'    => 400,
				'border_radius' => 24,
				'alignment'     => 'start',
			],
			new Resolver( [] ),
			's1.b0'
		);

		self::assertIsArray( $result );
		self::assertSame( 400, $result['settings']['min-height']['size'] );
		self::assertSame( '24', $result['settings']['border_radius']['left'] );
		self::assertSame( 'start', $result['settings']['alignment'] );
	}
}
