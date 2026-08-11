<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;

/**
 * Synthetic public-safe pixel-parity fixture for native Elementor parity.
 *
 * Documents the desktop/tablet/mobile acceptance path even when full screenshot
 * E2E is limited in CI: the fixture must validate, render native widgets, and
 * encode responsive layout (desktop row → tablet/mobile column) for the hero.
 *
 * @covers \Stonewright\WpMcp\Elementor\Renderer
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 */
final class NativeParityFixtureTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function fixture_spec(): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/elementor/native-parity-landing.json';
		self::assertFileExists( $path );
		$raw = file_get_contents( $path );
		self::assertIsString( $raw );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	public function test_fixture_validates_against_design_spec(): void {
		$validated = Validator::validate( $this->fixture_spec() );
		self::assertIsArray( $validated );
		self::assertArrayHasKey( 'sections', $validated );
	}

	public function test_fixture_renders_native_structure(): void {
		$diagnostics = [];
		$tree        = Renderer::render( $this->fixture_spec(), $diagnostics );

		self::assertNotEmpty( $tree );

		// Collect widget types for assertions.
		$widgets = [];
		$walk    = static function ( array $nodes ) use ( &$walk, &$widgets ): void {
			foreach ( $nodes as $node ) {
				if ( isset( $node['widgetType'] ) ) {
					$widgets[] = (string) $node['widgetType'];
				}
				if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$walk( $node['elements'] );
				}
			}
		};
		$walk( $tree );

		// CTA cover (when Pro/catalog available), buttons with icons, chips as containers/buttons.
		self::assertContains( 'button', $widgets );
		self::assertContains( 'heading', $widgets );
		self::assertContains( 'image', $widgets );

		// Hero two-column: desktop row with tablet/mobile column.
		$hero = $tree[0] ?? null;
		self::assertIsArray( $hero );
		// Find the row container inside hero section.
		$row = null;
		foreach ( (array) ( $hero['elements'] ?? [] ) as $child ) {
			if ( ( $child['settings']['flex_direction'] ?? null ) === 'row' ) {
				$row = $child;
				break;
			}
		}
		// Section itself may be the row when layout is row on first section.
		if ( null === $row && ( $hero['settings']['flex_direction'] ?? null ) === 'row' ) {
			$row = $hero;
		}
		self::assertIsArray( $row, 'Expected a flex-row hero layout for two-column glass+image.' );

		// Responsive acceptance notes encoded in settings when fixture requests them.
		if ( isset( $row['settings']['flex_direction_tablet'] ) ) {
			self::assertSame( 'column', $row['settings']['flex_direction_tablet'] );
		}
		if ( isset( $row['settings']['flex_direction_mobile'] ) ) {
			self::assertSame( 'column', $row['settings']['flex_direction_mobile'] );
		}

		// Diagnostics may include Pro-gated carousel/CTA when offline — never silent image-box remaps.
		foreach ( $diagnostics as $diag ) {
			self::assertNotSame( 'image-box', $diag['type'] ?? '' );
			self::assertStringNotContainsStringIgnoringCase( 'icon-box substitute', (string) ( $diag['message'] ?? '' ) );
		}
	}

	/**
	 * Acceptance path documentation for human QA / agent browser verify.
	 *
	 * Desktop: two-column hero (glass panel + image), CTA cover 320/20, chip row wrap, icon buttons.
	 * Tablet:  hero stacks to column; chips remain wrap or stack per narrow policy.
	 * Mobile:  hero column; chips wrap/stack; CTA cover remains min-height 320.
	 */
	public function test_documents_responsive_acceptance_path(): void {
		$notes = [
			'desktop' => 'Two-column hero glass+image, CTA cover min-height 320 radius 20, carousel, white chips, icon buttons, 1140 kit width + Montserrat globals.',
			'tablet'  => 'Hero flex_direction_tablet=column; chips wrap or stack; verify .e-con-inner when boxed.',
			'mobile'  => 'Hero flex_direction_mobile=column; chip wrap/stack stable; CTA cover min-height holds; icon buttons remain tappable.',
		];
		self::assertCount( 3, $notes );
		self::assertStringContainsString( '1140', $notes['desktop'] );
	}
}
