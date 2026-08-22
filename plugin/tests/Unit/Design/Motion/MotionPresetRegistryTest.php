<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Motion\MotionAssetLoader;
use Stonewright\WpMcp\Design\Motion\MotionPresetRegistry;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\MotionPresetRegistry
 * @covers \Stonewright\WpMcp\Design\Motion\MotionAssetLoader
 */
final class MotionPresetRegistryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
	}

	public function test_registry_contains_exactly_the_seven_approved_presets(): void {
		self::assertSame(
			[ 'fade-in', 'fade-up', 'slide-in-inline', 'scale-in-subtle', 'card-lift', 'link-underline', 'stagger-reveal' ],
			MotionPresetRegistry::slugs()
		);
	}

	public function test_every_preset_declares_namespace_class_and_reduced_motion_replacement(): void {
		foreach ( MotionPresetRegistry::presets() as $slug => $preset ) {
			self::assertSame( 'stw-motion-' . $slug, $preset['class'], $slug );
			self::assertNotEmpty( (string) $preset['reduced_motion'], $slug );
			self::assertIsArray( $preset['triggers'], $slug );
			self::assertArrayHasKey( 'requires_runtime', $preset, $slug );
		}
	}

	public function test_hover_presets_carry_focus_parity_and_never_need_runtime(): void {
		foreach ( MotionPresetRegistry::presets() as $preset ) {
			if ( in_array( 'hover', $preset['triggers'], true ) ) {
				self::assertTrue( $preset['focus_parity'] );
				self::assertFalse( $preset['requires_runtime'] );
				self::assertContains( 'focus-visible', $preset['triggers'] );
			}
		}
	}

	public function test_stagger_reveal_is_orchestration_not_a_keyframe(): void {
		$preset = MotionPresetRegistry::get( 'stagger-reveal' );

		self::assertTrue( $preset['orchestration'] );
		self::assertTrue( $preset['requires_runtime'] );
		self::assertSame( 'fade-up', $preset['child_effect'] );
	}

	public function test_fingerprint_is_stable_and_hex(): void {
		$first  = MotionPresetRegistry::fingerprint();
		$second = MotionPresetRegistry::fingerprint();

		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
	}

	public function test_manifest_checksums_match_real_asset_files(): void {
		$manifest = MotionPresetRegistry::manifest();

		foreach ( [ 'css', 'js' ] as $kind ) {
			$asset   = $manifest['assets'][ $kind ];
			$raw     = file_get_contents( STONEWRIGHT_DIR . $asset['path'] );
			self::assertNotFalse( $raw, $kind );
			self::assertSame( hash( 'sha256', $raw ), $asset['sha256'], "manifest checksum drift for {$kind}" );
			self::assertTrue( $asset['exists'], "{$kind} asset missing" );
		}
	}

	public function test_css_assets_are_safe_original_product_code(): void {
		$css = (string) file_get_contents( STONEWRIGHT_DIR . 'assets/frontend/motion-core.css' );

		// Reduced-motion replacement present.
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
		// No blur in core default.
		self::assertDoesNotMatchRegularExpression( '/blur\s*\(/i', $css );
		// No broad permanent will-change.
		self::assertDoesNotMatchRegularExpression( '/will-change/i', $css );
		// Zero remote references: no url(), no @import, no CDN hosts.
		self::assertDoesNotMatchRegularExpression( '/url\s*\(/i', $css );
		self::assertDoesNotMatchRegularExpression( '/@import/i', $css );
		// Every preset class ships with a reduced-motion-safe definition.
		foreach ( MotionPresetRegistry::slugs() as $slug ) {
			self::assertStringContainsString( 'stw-motion-' . $slug, $css );
		}
		self::assertStringContainsString( 'background-image: linear-gradient(currentColor, currentColor)', $css );
		// The played selector must outrank the initial html.stw-motion-js hidden
		// state. A class-only selector leaves successfully played targets hidden.
		self::assertStringContainsString( 'html.stw-motion-js .stw-motion-fade-up.stw-motion-played', $css );
		self::assertStringContainsString( 'html.stw-motion-js .stw-motion-stagger-reveal > .stw-motion-played', $css );
	}

	public function test_js_runtime_has_no_eval_and_no_remote_references(): void {
		$js = (string) file_get_contents( STONEWRIGHT_DIR . 'assets/frontend/motion-core.js' );

		self::assertDoesNotMatchRegularExpression( '/\beval\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/new\s+Function/', $js );
		self::assertDoesNotMatchRegularExpression( '/https?:\/\//i', $js );
		self::assertStringContainsString( 'prefers-reduced-motion', $js );
		self::assertStringContainsString( 'IntersectionObserver', $js );
		self::assertStringContainsString( 'failOpen', $js );
		self::assertStringContainsString( "addEventListener('pageshow'", $js );
		self::assertStringContainsString( 'stw-motion-trigger--load', $js );
		self::assertStringContainsString( "querySelectorAll(':scope > *')", $js );
	}

	public function test_bundle_budgets_are_measured_not_assumed(): void {
		$manifest = MotionPresetRegistry::manifest();

		foreach ( [ 'css', 'js' ] as $kind ) {
			$asset  = $manifest['assets'][ $kind ];
			$raw    = (string) file_get_contents( STONEWRIGHT_DIR . $asset['path'] );
			$gz     = strlen( gzencode( $raw, 9 ) ?: $raw );
			self::assertLessThanOrEqual(
				(int) $asset['budget_bytes_gzip'],
				$gz,
				sprintf( '%s gzip budget exceeded: %d bytes > %d', $kind, $gz, $asset['budget_bytes_gzip'] )
			);
		}
	}

	public function test_loader_loads_nothing_without_motion_markers(): void {
		$result = MotionAssetLoader::enqueue_for_content( '<!-- wp:paragraph --><p>Plain content</p><!-- /wp:paragraph -->' );

		self::assertFalse( $result['css'] );
		self::assertFalse( $result['js'] );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'] );
	}

	public function test_css_only_hover_motion_does_not_load_the_runtime(): void {
		$content = '<div class="stw-motion-card-lift stw-motion-target--pricing-card">Card</div>';
		$result  = MotionAssetLoader::enqueue_for_content( $content );

		self::assertTrue( $result['css'] );
		self::assertFalse( $result['js'] );
		self::assertSame( [ MotionAssetLoader::STYLE_HANDLE ], $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'] );
	}

	public function test_viewport_entrance_loads_both_css_and_runtime(): void {
		$content = '<div class="stw-motion-fade-up stw-motion-target--hero-copy" data-stw-motion-trigger="viewport-enter">Copy</div>';
		$result  = MotionAssetLoader::enqueue_for_content( $content );

		self::assertTrue( $result['css'] );
		self::assertTrue( $result['js'] );
		self::assertSame( [ MotionAssetLoader::STYLE_HANDLE ], $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertSame( [ MotionAssetLoader::SCRIPT_HANDLE ], $GLOBALS['stonewright_test_enqueued_scripts'] );
	}

	public function test_load_triggered_entrance_also_needs_the_runtime(): void {
		$content = '<div class="stw-motion-fade-in stw-motion-target--hero-copy">Copy</div>';
		$result  = MotionAssetLoader::enqueue_for_content( $content );

		self::assertTrue( $result['css'] );
		self::assertTrue( $result['js'] );
	}

	public function test_unknown_motion_classes_do_not_trigger_loading(): void {
		$result = MotionAssetLoader::enqueue_for_content( '<div class="stw-motion-super-spin stw-motion-fade-up-extra user-css">Nope</div>' );

		self::assertFalse( $result['css'] );
	}
}
