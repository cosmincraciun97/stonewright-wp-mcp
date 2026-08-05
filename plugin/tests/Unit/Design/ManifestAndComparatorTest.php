<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Assets\AssetNormalizer;
use Stonewright\WpMcp\Design\Diagnostics\ThirdPartyControlRiskMap;
use Stonewright\WpMcp\Design\Manifest\ArrowAssetContract;
use Stonewright\WpMcp\Design\Manifest\CarouselIntent;
use Stonewright\WpMcp\Design\Manifest\SectionManifest;
use Stonewright\WpMcp\Design\Planning\NativeRendererDecision;
use Stonewright\WpMcp\Design\Quality\VisualComparator;

/**
 * @covers \Stonewright\WpMcp\Design\Assets\AssetNormalizer
 * @covers \Stonewright\WpMcp\Design\Manifest\ArrowAssetContract
 * @covers \Stonewright\WpMcp\Design\Manifest\CarouselIntent
 * @covers \Stonewright\WpMcp\Design\Manifest\SectionManifest
 * @covers \Stonewright\WpMcp\Design\Planning\NativeRendererDecision
 * @covers \Stonewright\WpMcp\Design\Quality\VisualComparator
 * @covers \Stonewright\WpMcp\Design\Diagnostics\ThirdPartyControlRiskMap
 */
final class ManifestAndComparatorTest extends TestCase {

	public function test_manifest_is_deterministic_and_deduplicates_reference_assets(): void {
		$input = $this->manifest();
		$input['assets'] = [
			[ 'id' => 'hero', 'url' => 'https://example.test/hero.png', 'alt' => 'Hero' ],
			[ 'id' => 'hero-copy', 'url' => 'https://example.test/hero.png', 'alt' => 'Hero' ],
		];
		$first  = SectionManifest::validate( $input );
		$second = SectionManifest::validate( $input );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['digest_hash'], $second['digest_hash'] );
		self::assertCount( 1, $first['manifest']['assets'] );
		self::assertFalse( $first['manifest']['assets'][0]['hash_verified'] );
		self::assertSame( 'reference', $first['manifest']['assets'][0]['hash_basis'] );
		self::assertSame( 64, strlen( $first['digest_hash'] ) );
		$revalidated = SectionManifest::validate( $first['manifest'] );
		self::assertIsArray( $revalidated );
		self::assertSame( $first['digest_hash'], $revalidated['digest_hash'] );
	}

	/** @dataProvider unsafe_svg_provider */
	public function test_svg_sanitizer_rejects_executable_and_external_constructs( string $markup ): void {
		$svg = AssetNormalizer::safe_svg( $markup );
		self::assertInstanceOf( \WP_Error::class, $svg );
		self::assertContains( $svg->get_error_code(), [ 'stonewright_svg_unsafe', 'stonewright_svg_invalid' ] );
	}

	/** @return iterable<string,array{string}> */
	public function unsafe_svg_provider(): iterable {
		yield 'script' => [ '<svg><script>alert(1)</script></svg>' ];
		yield 'external image href' => [ '<svg><image href="https://evil.example/pixel.png"/></svg>' ];
		yield 'foreign object' => [ '<svg><foreignObject><p>unsafe</p></foreignObject></svg>' ];
		yield 'event handler' => [ '<svg><path d="M0 0" onclick="alert(1)"/></svg>' ];
		yield 'css import' => [ '<svg><style>@import url(https://evil.example/x.css)</style></svg>' ];
		yield 'external use' => [ '<svg><use href="https://evil.example/icons.svg#next"/></svg>' ];
		yield 'entity declaration' => [ '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg><text>&xxe;</text></svg>' ];
	}

	public function test_svg_sanitizer_accepts_only_local_references_and_verifies_hash(): void {
		$markup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs><!-- remove --><path fill="url(#g)" d="M8 4l8 8-8 8"/></svg>';
		$safe   = AssetNormalizer::safe_svg( $markup );
		self::assertIsString( $safe );
		self::assertStringNotContainsString( '<!--', $safe );

		$asset = AssetNormalizer::normalize( [ 'id' => 'next', 'svg_markup' => $markup ] );
		self::assertIsArray( $asset );
		self::assertTrue( $asset['hash_verified'] );
		self::assertSame( 'sanitized', $asset['sanitization_status'] );
		self::assertSame( hash( 'sha256', (string) $asset['svg_markup'] ), $asset['content_hash'] );

		$mismatch = AssetNormalizer::normalize( [ 'id' => 'next', 'svg_markup' => $markup, 'content_hash' => str_repeat( 'a', 64 ) ] );
		self::assertInstanceOf( \WP_Error::class, $mismatch );
		self::assertSame( 'stonewright_asset_hash_mismatch', $mismatch->get_error_code() );
	}

	public function test_carousel_without_active_arrows_does_not_require_arrow_assets_or_invent_defaults(): void {
		$valid = CarouselIntent::validate(
			[
				'content_source' => 'manual-product-reference',
				'slides_visible' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
				'gap'            => [ 'desktop' => 24, 'tablet' => 16, 'mobile' => 12 ],
				'arrows_enabled' => [ 'desktop' => false, 'tablet' => false, 'mobile' => false ],
				'dots_enabled'   => [ 'desktop' => false, 'tablet' => false, 'mobile' => true ],
				'confidence'     => 1,
			]
		);

		self::assertIsArray( $valid );
		self::assertNull( $valid['arrow_contract'] );
		self::assertNull( $valid['loop'] );
		self::assertNull( $valid['autoplay'] );
		self::assertNull( $valid['duration_ms'] );
	}

	public function test_active_arrows_require_exactly_one_explicit_asset_source(): void {
		$invalid = CarouselIntent::validate(
			[
				'content_source' => 'manual-product-reference',
				'slides_visible' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
				'gap'            => [ 'desktop' => 24, 'tablet' => 16, 'mobile' => 12 ],
				'arrows_enabled' => [ 'desktop' => true, 'tablet' => true, 'mobile' => false ],
				'dots_enabled'   => [ 'desktop' => false, 'tablet' => false, 'mobile' => true ],
				'arrow_contract' => [
					'previous' => [ 'aria_label' => 'Previous slide' ],
					'next'     => [ 'library_icon' => 'chevron-right', 'aria_label' => 'Next slide' ],
				],
				'confidence' => 1,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $invalid );
		$diagnostics = $invalid->get_error_data()['diagnostics'];
		self::assertContains( 'stonewright_arrow_asset_missing', array_column( $diagnostics, 'code' ) );

		$ambiguous = ArrowAssetContract::validate(
			[
				'previous' => [ 'media_id' => 10, 'library_icon' => 'chevron-left', 'aria_label' => 'Previous slide' ],
				'next'     => [ 'library_icon' => 'chevron-right', 'aria_label' => 'Next slide' ],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $ambiguous );
		self::assertSame( 'stonewright_arrow_asset_ambiguous', $ambiguous->get_error_code() );
	}

	public function test_arrow_asset_union_supports_verified_manifest_assets_without_default_geometry(): void {
		$assets = AssetNormalizer::normalize_many(
			[
				[ 'id' => 'arrow-left', 'svg_markup' => '<svg viewBox="0 0 24 24"><path d="M16 4l-8 8 8 8"/></svg>' ],
				[ 'id' => 'arrow-right', 'svg_markup' => '<svg viewBox="0 0 24 24"><path d="M8 4l8 8-8 8"/></svg>' ],
			]
		);
		self::assertIsArray( $assets );

		$valid = ArrowAssetContract::validate(
			[
				'previous' => [ 'asset_ref' => 'arrow-left', 'aria_label' => 'Previous slide' ],
				'next'     => [ 'asset_ref' => 'arrow-right', 'aria_label' => 'Next slide' ],
			],
			true,
			$assets
		);
		self::assertIsArray( $valid );
		self::assertSame( 'manifest_asset', $valid['previous']['asset']['kind'] );
		self::assertTrue( $valid['previous']['asset']['hash_verified'] );
		self::assertNull( $valid['previous']['width'] );
		self::assertNull( $valid['previous']['hit_width'] );
	}

	public function test_page_manifest_preserves_explicit_section_order_and_decomposes_real_sections(): void {
		$validated = SectionManifest::validate( $this->page_manifest() );
		self::assertIsArray( $validated );
		self::assertSame( 'page', $validated['manifest']['manifest_type'] );
		self::assertCount( 2, $validated['manifest']['sections'] );

		$sections = SectionManifest::decompose( $validated['manifest'] );
		self::assertSame( [ 'proof', 'hero' ], array_column( $sections, 'section_id' ) );
		self::assertSame( [ 10, 20 ], array_column( $sections, 'order' ) );
		self::assertSame( [ 1, 0 ], array_column( $sections, 'source_index' ) );
		self::assertSame( '2:8', $sections[0]['node_provenance'][0]['node_id'] );
		self::assertSame( 64, strlen( $sections[0]['digest_hash'] ) );
		$revalidated = SectionManifest::validate( $validated['manifest'] );
		self::assertIsArray( $revalidated );
		self::assertSame( $validated['digest_hash'], $revalidated['digest_hash'] );
	}

	public function test_page_manifest_rejects_duplicate_section_identity_and_order(): void {
		$page = $this->page_manifest();
		$page['sections'][1]['section_id'] = 'hero';
		$page['sections'][1]['order']      = 20;
		$invalid = SectionManifest::validate( $page );

		self::assertInstanceOf( \WP_Error::class, $invalid );
		$codes = array_column( $invalid->get_error_data()['diagnostics'], 'code' );
		self::assertContains( 'page_section_order_duplicate', $codes );
	}

	public function test_visual_source_section_requires_node_provenance(): void {
		$manifest = $this->manifest();
		unset( $manifest['node_provenance'] );
		$invalid = SectionManifest::validate( $manifest );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertContains( 'node_provenance_missing', array_column( $invalid->get_error_data()['diagnostics'], 'code' ) );
	}

	public function test_renderer_decision_fails_closed_without_verified_candidate(): void {
		$decision = NativeRendererDecision::choose( $this->manifest(), [ 'controls' => [ 'arrows' => true ] ] );
		self::assertIsArray( $decision );
		self::assertFalse( $decision['ok'] );
		self::assertNull( $decision['native_target'] );
		self::assertSame( 'native_gap_verified_renderer_missing', $decision['native_gap'][0]['code'] );
		self::assertFalse( $decision['custom_code_approved'] );
	}

	public function test_renderer_decision_uses_only_registered_schema_capabilities_and_control_maps(): void {
		$validated = SectionManifest::validate( $this->manifest() );
		self::assertIsArray( $validated );
		$decision = NativeRendererDecision::choose(
			$validated['manifest'],
			[
				'candidates' => [
					[
						'widget_type'   => 'verified-carousel-widget',
						'availability'  => 'registered',
						'schema_hash'   => hash( 'sha256', 'verified-live-schema' ),
						'source_plugin' => 'example/verified-widget.php',
						'controls'      => [
							'slides_per_view' => [ 'type' => 'number' ],
							'slide_gap'       => [ 'type' => 'number' ],
						],
						'capabilities'  => [ 'arrows', 'arrow_asset', 'dots' ],
						'control_map'   => [
							'slides_visible' => 'slides_per_view',
							'gap'            => 'slide_gap',
						],
					],
				],
			]
		);

		self::assertIsArray( $decision );
		self::assertTrue( $decision['ok'] );
		self::assertSame( 'verified-carousel-widget', $decision['native_target'] );
		self::assertSame( hash( 'sha256', 'verified-live-schema' ), $decision['schema_hash'] );
		self::assertSame( 'verified_control_map', $decision['capability_evidence']['slides_visible']['evidence'] );
		self::assertSame( 1.0, $decision['confidence'] );
	}

	public function test_visual_comparator_reports_geometry_typography_spacing_and_missing_evidence(): void {
		$expected = [
			'viewports' => [
				'mobile' => [
					'width'    => 390,
					'height'   => 844,
					'elements' => [
						'hero' => [
							'ref'            => 'hero',
							'x'              => 0,
							'y'              => 0,
							'width'          => 320,
							'height'         => 160,
							'font_size'      => 24,
							'line_height'    => 30,
							'padding'        => [ 'top' => 16, 'right' => 16, 'bottom' => 16, 'left' => 16 ],
							'color'          => '#ffffff',
							'target_setting' => 'hero-container.padding',
						],
					],
				],
			],
		];
		$observed = [
			'viewports' => [
				'mobile' => [
					'width'    => 390,
					'height'   => 844,
					'elements' => [
						'hero' => [
							'ref'         => 'hero',
							'x'           => 0,
							'y'           => 0,
							'width'       => 324,
							'font_size'   => 24,
							'line_height' => 32,
							'padding'     => [ 'top' => 20, 'right' => 16, 'bottom' => 16, 'left' => 16 ],
							'color'       => '#000000',
						],
					],
				],
			],
		];
		$result = VisualComparator::compare( $expected, $observed );
		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertContains( 'box_delta', array_column( $result['findings'], 'code' ) );
		self::assertContains( 'style_delta', array_column( $result['findings'], 'code' ) );
		self::assertContains( 'measurement_missing', array_column( $result['findings'], 'code' ) );
		self::assertContains( 'color_delta', array_column( $result['findings'], 'code' ) );
		self::assertSame( 'hero-container.padding', $result['findings'][0]['evidence']['target_setting'] );
		self::assertSame( 0.0, $result['aggregate_score'] );
		self::assertSame( $result['comparison_hash'], VisualComparator::compare( $expected, $observed )['comparison_hash'] );
	}

	public function test_visual_comparator_rejects_incomplete_expected_geometry_and_bounds_findings(): void {
		$invalid = VisualComparator::compare(
			[ 'viewports' => [ 'desktop' => [ 'elements' => [ 'hero' => [ 'ref' => 'hero', 'x' => 0 ] ] ] ] ],
			[]
		);
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_visual_evidence_invalid', $invalid->get_error_code() );

		$expected_elements = [];
		$observed_elements = [];
		for ( $index = 0; $index < 250; ++$index ) {
			$ref                       = 'node-' . $index;
			$expected_elements[ $ref ] = [ 'ref' => $ref, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'font_size' => 16 ];
			$observed_elements[ $ref ] = [ 'ref' => $ref ];
		}
		$bounded = VisualComparator::compare(
			[ 'viewports' => [ 'desktop' => [ 'elements' => $expected_elements ] ] ],
			[ 'viewports' => [ 'desktop' => [ 'elements' => $observed_elements ] ] ]
		);
		self::assertIsArray( $bounded );
		self::assertCount( 500, $bounded['findings'] );
		self::assertSame( 750, $bounded['findings_truncated'] );
		self::assertSame( 1250, $bounded['error_count'] );
	}

	public function test_third_party_map_preserves_unknown_controls(): void {
		$result = ThirdPartyControlRiskMap::analyze(
			[ 'email_to' => 'team@example.test', 'newsman_list' => 'list-1', 'submit_actions' => [ 'email', 'newsman' ] ],
			[ 'email_to' => 'team@example.test' ],
			[ 'known_controls' => [ 'email_to' ] ]
		);
		self::assertTrue( $result['preserve_unknown'] );
		self::assertContains( 'newsman_list', $result['unknown_controls'] );
		self::assertTrue( $result['destructive_replace_risk'] );
	}

	/** @return array<string,mixed> */
	private function manifest(): array {
		return [
			'source_type'             => 'figma',
			'source_file_fingerprint' => hash( 'sha256', 'synthetic-design-source' ),
			'page_id'                 => 'page-1',
			'frame_id'                => 'frame-hero',
			'section_id'              => 'hero-section',
			'node_provenance'         => [ [ 'node_id' => '1:2', 'source_id' => 'figma-file' ] ],
			'bounding_box'            => [ 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 480 ],
			'layout_mode'             => 'flex',
			'semantic_roles'          => [ 'section', 'carousel' ],
			'target_renderer'         => 'elementor-v3',
			'confidence'              => 0.98,
			'interaction_intents'     => [
				[
					'type'           => 'carousel',
					'content_source' => 'synthetic-items',
					'slides_visible' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ],
					'gap'            => [ 'desktop' => 24, 'tablet' => 16, 'mobile' => 12 ],
					'arrows_enabled' => [ 'desktop' => true, 'tablet' => true, 'mobile' => false ],
					'dots_enabled'   => [ 'desktop' => false, 'tablet' => false, 'mobile' => true ],
					'arrow_contract' => [
						'previous' => [ 'library_icon' => 'chevron-left', 'aria_label' => 'Previous slide' ],
						'next'     => [ 'library_icon' => 'chevron-right', 'aria_label' => 'Next slide' ],
					],
					'confidence' => 1,
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function page_manifest(): array {
		return [
			'source_type'             => 'figma',
			'source_file_fingerprint' => hash( 'sha256', 'synthetic-page-source' ),
			'page_id'                 => 'landing-page',
			'frame_id'                => 'desktop-frame',
			'target_renderer'         => 'elementor-v3',
			'confidence'              => 0.99,
			'sections'                => [
				[
					'section_id'      => 'hero',
					'order'           => 20,
					'node_provenance' => [ [ 'node_id' => '2:2', 'source_id' => 'figma-file' ] ],
					'bounding_box'    => [ 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 720 ],
					'layout_mode'     => 'flex',
					'semantic_roles'  => [ 'hero' ],
				],
				[
					'section_id'      => 'proof',
					'order'           => 10,
					'node_provenance' => [ [ 'node_id' => '2:8', 'source_id' => 'figma-file' ] ],
					'bounding_box'    => [ 'x' => 0, 'y' => 720, 'width' => 1440, 'height' => 220 ],
					'layout_mode'     => 'grid',
					'semantic_roles'  => [ 'proof' ],
				],
			],
		];
	}
}
