<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\NativePlan;
use Stonewright\WpMcp\Design\Evidence\Validator;
use Stonewright\WpMcp\Design\Planning\NativePlanner;
use Stonewright\WpMcp\Design\Semantics\ActionValidator;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\NativePlan
 * @covers \Stonewright\WpMcp\Design\Evidence\Validator
 * @covers \Stonewright\WpMcp\Design\Planning\NativePlanner
 * @covers \Stonewright\WpMcp\Design\Semantics\ActionValidator
 */
final class NativePlanTest extends TestCase {

	public function test_validation_normalizes_vendor_payload_and_keeps_a_stable_hash(): void {
		$evidence               = self::evidence();
		$evidence['raw_document'] = [ 'huge' => 'figma tree must not survive' ];
		$ability                = new NativePlan();

		$first  = $ability->execute( [ 'action' => 'validate', 'evidence' => $evidence ] );
		$second = $ability->execute( [ 'action' => 'validate', 'evidence' => $evidence ] );

		self::assertIsArray( $first );
		self::assertSame( $first['evidence_hash'], $second['evidence_hash'] );
		self::assertArrayNotHasKey( 'raw_document', $first['normalized_evidence'] );
		self::assertSame( 2, $first['node_count'] );
	}

	public function test_missing_style_provenance_and_unresolved_cta_are_hard_failures(): void {
		$evidence = self::evidence();
		unset( $evidence['nodes'][0]['provenance']['gap'] );
		unset( $evidence['nodes'][0]['children'][0]['action'] );

		$result = Validator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $result );
		$codes = array_column( $result->get_error_data()['diagnostics'], 'code' );
		self::assertContains( 'style_provenance_missing', $codes );
		self::assertContains( 'unresolved_action', $codes );
	}

	public function test_elementor_plan_uses_live_schema_refs_and_never_emits_raw_settings(): void {
		$first  = NativePlanner::plan( self::evidence(), 'elementor-v3' );
		$second = NativePlanner::plan( self::evidence(), 'elementor-v3' );

		self::assertIsArray( $first );
		self::assertTrue( $first['ok'] );
		self::assertSame( $first, $second );
		self::assertSame( 100.0, $first['native_coverage_percent'] );
		self::assertSame( 'container', $first['native_phase']['operations'][0]['native_target']['name'] );
		self::assertSame( 'button', $first['native_phase']['operations'][1]['native_target']['name'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first['native_phase']['operations'][1]['native_target']['schema_hash'] );
		self::assertArrayNotHasKey( 'settings', $first['native_phase']['operations'][1] );
		self::assertFalse( $first['customization_proposal'][0]['applied'] );
		self::assertTrue( $first['customization_proposal'][0]['requires_approval'] );
		self::assertSame( [ 'css', 'css_js', 'custom_php' ], array_column( $first['customization_proposal'][0]['options'], 'type' ) );
		self::assertContains( 'exact_files', $first['customization_proposal'][0]['required_detail'] );
		self::assertContains( 'confirmation_request', $first['customization_proposal'][0]['required_detail'] );
	}

	public function test_v4_target_is_blocked_without_silent_v3_fallback(): void {
		$result = NativePlanner::plan( self::evidence(), 'elementor-v4' );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( [], $result['native_phase']['operations'] );
		self::assertSame( [ 'elementor_v4_native_planner_not_promoted' ], array_column( $result['blockers'], 'code' ) );
	}

	public function test_arbitrary_text_is_not_accepted_as_a_button_destination(): void {
		$evidence = self::evidence();
		$evidence['nodes'][0]['children'][0]['action'] = [ 'url' => 'coming soon' ];

		$result = Validator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertContains( 'unresolved_action', array_column( $result->get_error_data()['diagnostics'], 'code' ) );
	}

	public function test_confirmable_inference_is_valid_evidence_but_blocks_the_plan(): void {
		$evidence = self::evidence();
		$evidence['nodes'][0]['provenance']['gap']['source'] = 'inference';
		$evidence['nodes'][0]['provenance']['gap']['requires_confirmation'] = true;

		$result = NativePlanner::plan( $evidence, 'gutenberg' );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertContains( 'evidence_confirmation_required', array_column( $result['blockers'], 'code' ) );
	}

	public function test_structured_node_ambiguity_blocks_planning_and_invalid_target_never_falls_back(): void {
		$evidence = self::evidence();
		$evidence['nodes'][0]['unresolved'] = [
			[ 'code' => 'mobile_direction_unknown', 'repair' => 'Measure the mobile reference.' ],
		];

		$blocked = NativePlanner::plan( $evidence, 'elementor-v3' );
		$invalid = NativePlanner::plan( self::evidence(), 'elementor-v5' );

		self::assertIsArray( $blocked );
		self::assertFalse( $blocked['ok'] );
		self::assertContains( 'mobile_direction_unknown', array_column( $blocked['blockers'], 'code' ) );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_native_target_invalid', $invalid->get_error_code() );
	}

	public function test_malformed_unresolved_items_are_rejected_instead_of_dropped(): void {
		$evidence = self::evidence();
		$evidence['unresolved'] = [ 'maybe something is missing' ];

		$result = Validator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertContains( 'invalid_unresolved_item', array_column( $result->get_error_data()['diagnostics'], 'code' ) );
	}

	public function test_visual_evidence_requires_verifiable_sources_responsive_viewports_and_bounds(): void {
		$evidence = self::evidence();
		unset( $evidence['sources'][0]['hash'] );
		$evidence['viewports'] = [ $evidence['viewports'][0] ];
		unset( $evidence['nodes'][0]['bounds'] );

		$result = Validator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $result );
		$codes = array_column( $result->get_error_data()['diagnostics'], 'code' );
		self::assertContains( 'visual_source_unverifiable', $codes );
		self::assertContains( 'responsive_evidence_missing', $codes );
		self::assertContains( 'measured_bounds_missing', $codes );
	}

	public function test_global_inference_blocks_plan_and_malformed_customization_is_rejected(): void {
		$evidence = self::evidence();
		$evidence['global'] = [
			'colors'     => [ 'primary' => '#112233' ],
			'provenance' => [
				'colors.primary' => [
					'source'                => 'inference',
					'source_id'             => 'figma:hero',
					'confidence'            => 0.6,
					'requires_confirmation' => true,
				],
			],
		];

		$blocked = NativePlanner::plan( $evidence, 'elementor-v3' );
		self::assertIsArray( $blocked );
		self::assertFalse( $blocked['ok'] );
		self::assertContains( 'evidence_confirmation_required', array_column( $blocked['blockers'], 'code' ) );

		$evidence['nodes'][0]['children'][0]['customization_needs'] = [ 'looks special' ];
		$invalid = Validator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertContains( 'invalid_customization_need', array_column( $invalid->get_error_data()['diagnostics'], 'code' ) );
	}

	public function test_pixel_evidence_fixture_validates_and_plans_all_engines(): void {
		$path = dirname( __DIR__, 2 ) . '/fixtures/design-evidence/pixel-hero.json';
		self::assertFileExists( $path );
		$evidence = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $evidence );

		$validated = Validator::validate( $evidence );
		self::assertIsArray( $validated, $validated instanceof \WP_Error ? $validated->get_error_message() . ' ' . wp_json_encode( $validated->get_error_data() ) : '' );
		self::assertArrayHasKey( 'measured_targets', $validated['evidence'] );
		self::assertNotEmpty( $validated['evidence']['measured_targets'] );
		self::assertArrayHasKey( 'spacing_scale', $validated['evidence']['global'] );
		self::assertArrayHasKey( 'typography_ramp', $validated['evidence']['global'] );
		self::assertSame( '#E8A838', $validated['evidence']['global']['colors']['accent'] ?? null );

		foreach ( [ 'elementor', 'gutenberg', 'fse' ] as $engine ) {
			$plan = NativePlanner::plan( $evidence, $engine );
			self::assertIsArray( $plan, $engine );
			self::assertTrue( $plan['ok'], $engine . ' blockers: ' . wp_json_encode( $plan['blockers'] ?? [] ) );
			self::assertNotEmpty( $plan['native_phase']['operations'] );
			foreach ( $plan['native_phase']['operations'] as $op ) {
				self::assertArrayHasKey( 'native_mapping', $op, $engine );
				self::assertNotNull( $op['native_mapping'], $engine . ' missing mapping for ' . ( $op['node_id'] ?? '' ) );
			}
			if ( 'fse' === $engine ) {
				self::assertSame( 'fse', $plan['engine'] );
				self::assertSame( 'fse_block', $plan['native_phase']['operations'][0]['native_mapping']['kind'] );
			}
			if ( 'elementor' === $engine ) {
				self::assertSame( 'elementor-v3', $plan['target'] );
				self::assertSame( 'elementor', $plan['engine'] );
			}
		}
	}

	public function test_implementation_contract_rejects_css_without_native_gap(): void {
		$spec = [
			'version'       => '2.0.0',
			'page'          => [ 'title' => 'X' ],
			'native_policy' => [ 'strict' => true, 'require_native_gap_for_custom_css' => true ],
			'sections'      => [
				[
					'id'     => 's1',
					'blocks' => [
						[
							'id'         => 'b1',
							'type'       => 'paragraph',
							'text'       => 'Hello',
							'custom_css' => '.x{letter-spacing:0.1em}',
						],
					],
				],
			],
		];

		$result = \Stonewright\WpMcp\Abilities\Design\ImplementationContract::validate_build_spec( $spec );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
		$codes = array_column( (array) ( $result->get_error_data()['errors'] ?? [] ), 'keyword' );
		self::assertContains( 'custom_css_without_native_gap', $codes );
	}

	public function test_implementation_contract_allows_css_with_native_gap(): void {
		$spec = [
			'version'       => '2.0.0',
			'page'          => [ 'title' => 'X' ],
			'native_policy' => [ 'strict' => true ],
			'sections'      => [
				[
					'id'     => 's1',
					'blocks' => [
						[
							'id'         => 'b1',
							'type'       => 'paragraph',
							'text'       => 'Hello',
							'custom_css' => '.k{letter-spacing:0.08em}',
							'native_gap' => [
								'reason' => 'No letter-spacing control on this widget version.',
							],
						],
					],
				],
			],
		];

		$result = \Stonewright\WpMcp\Abilities\Design\ImplementationContract::validate_build_spec( $spec );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	public function test_invalid_measured_target_is_rejected(): void {
		$evidence = self::evidence();
		$evidence['measured_targets'] = [
			[ 'viewport_id' => 'not-a-viewport', 'property' => 'gap', 'value_px' => 24 ],
		];
		$result = Validator::validate( $evidence );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertContains( 'measured_target_viewport_unknown', array_column( $result->get_error_data()['diagnostics'], 'code' ) );
	}

	public function test_dynamic_content_templates_and_forms_need_real_behavior_contracts(): void {
		$evidence = self::evidence();
		$evidence['nodes'] = [
			[ 'id' => 'cards', 'role' => 'repeated-cards' ],
			[ 'id' => 'header', 'role' => 'header' ],
			[
				'id'     => 'lead-form',
				'role'   => 'form',
				'fields' => [ [ 'id' => 'email', 'type' => 'email' ] ],
				'action' => [ 'form_action' => 'email_notification' ],
			],
		];

		$result = Validator::validate( $evidence );

		self::assertInstanceOf( \WP_Error::class, $result );
		$codes = array_column( $result->get_error_data()['diagnostics'], 'code' );
		self::assertContains( 'content_model_decision_missing', $codes );
		self::assertContains( 'theme_builder_conditions_missing', $codes );
		self::assertContains( 'form_success_missing', $codes );
	}

	/** @return array<string, mixed> */
	private static function evidence(): array {
		$provenance = static fn(): array => [
			'source'                => 'design',
			'source_id'             => 'figma:hero',
			'confidence'            => 0.99,
			'requires_confirmation' => false,
		];
		return [
			'sources'   => [ [ 'id' => 'figma:hero', 'type' => 'figma', 'ref' => 'node:1:2', 'hash' => str_repeat( 'a', 64 ), 'captured_at' => '2026-07-14T12:00:00Z' ] ],
			'viewports' => [ [ 'id' => 'desktop', 'width' => 1440, 'height' => 900 ], [ 'id' => 'mobile', 'width' => 390, 'height' => 844 ] ],
			'nodes'     => [
				[
					'id'         => 'hero',
					'role'       => 'container',
					'bounds'     => [ 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 600 ],
					'layout'     => [ 'direction' => 'column' ],
					'style'      => [ 'gap' => 24 ],
					'provenance' => [ 'gap' => $provenance() ],
					'children'   => [
						[
							'id'                  => 'hero-cta',
							'role'                => 'button',
							'bounds'              => [ 'x' => 80, 'y' => 420, 'width' => 180, 'height' => 48 ],
							'content'             => [ 'label' => 'Start now' ],
							'action'              => [ 'url' => 'https://example.test/start' ],
							'style'               => [ 'background_color' => '#112233' ],
							'provenance'          => [ 'background_color' => $provenance() ],
							'customization_needs' => [ [ 'delta' => 'decorative clipped corner', 'reason' => 'No verified native control in the selected widget.' ] ],
						],
					],
				],
			],
		];
	}
}
