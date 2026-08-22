<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\MotionPlan;
use Stonewright\WpMcp\Abilities\Design\MotionSuggest;
use Stonewright\WpMcp\Design\Motion\MotionPlanCompiler;
use Stonewright\WpMcp\Design\Motion\MotionPlanVerifier;
use Stonewright\WpMcp\Design\Motion\MotionSuggestEngine;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\MotionPlanCompiler
 * @covers \Stonewright\WpMcp\Design\Motion\MotionSuggestEngine
 * @covers \Stonewright\WpMcp\Abilities\Design\MotionPlan
 * @covers \Stonewright\WpMcp\Abilities\Design\MotionSuggest
 */
final class MotionPlanCompilerTest extends TestCase {

	public function test_invalid_spec_blocks_lowering_with_structured_error(): void {
		$spec             = self::spec();
		$spec['sections'][0]['motion'][0]['effect'] = 'not-in-registry-effect';

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'gutenberg-fse' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_gutenberg_plan_is_deterministic_and_binds_fingerprints(): void {
		$first  = MotionPlanCompiler::compile( self::spec(), [ 'renderer' => 'gutenberg-fse' ] );
		$second = MotionPlanCompiler::compile( self::spec(), [ 'gutenberg-fse' === 'x' ? [] : [ 'renderer' => 'gutenberg-fse' ] ] );

		self::assertNotInstanceOf( \WP_Error::class, $first, self::err( $first ) );
		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first['plan_hash'] );
		self::assertArrayHasKey( 'registry_fingerprint', $first['bindings'] );
		self::assertNotEmpty( $first['bindings']['asset_checksums']['css'] );

		$op = $first['operations'][0];
		self::assertSame( 'add-classes', $op['op'] );
		self::assertSame( 'stw-motion-fade-up', $op['classes'][0] );
		self::assertContains( 'stw-motion-duration--280', $op['classes'] );
		self::assertContains( 'stw-motion-trigger--viewport-enter', $op['classes'] );
		self::assertContains( 'stw-motion-target--hero-copy', $op['classes'] );
		self::assertTrue( $op['runtime'] );

		$markers = array_column( $first['bindings']['target_map'], 'marker', 'id' );
		self::assertSame( 'stw-motion-target--hero-copy', $markers['hero-copy'] );
	}

	public function test_spec_change_changes_plan_hash(): void {
		$a = MotionPlanCompiler::compile( self::spec(), [ 'renderer' => 'gutenberg-fse' ] );
		$b = MotionPlanCompiler::compile( self::spec( '2nd copy text' ), [ 'renderer' => 'gutenberg-fse' ] );

		self::assertNotSame( $a['plan_hash'], $b['plan_hash'] );
	}

	public function test_blocked_direction_produces_no_operations(): void {
		$result = MotionPlanCompiler::compile(
			self::spec(),
			[ 'renderer' => 'gutenberg-fse', 'direction' => [ 'entrance_animation' => 'blocked' ] ]
		);

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertSame( [], $result['operations'] );
		self::assertSame( 1, $result['summary']['unsupported'] );
		self::assertSame( 'motion_blocked_by_direction', $result['unsupported'][0]['reason'] );
	}

	public function test_hero_only_direction_keeps_hero_section_items_only(): void {
		$spec                    = self::spec();
		$spec['sections'][]      = [
			'id'     => 'pricing',
			'role'   => 'pricing',
			'blocks' => [
				[ 'id' => 'price-card', 'type' => 'card', 'text' => 'Price card copy.' ],
			],
		];
		$spec['sections'][1]['blocks'][0]['motion'] = [
			[
				'id'             => 'price-enter',
				'purpose'        => 'reveal',
				'target_id'      => 'price-card',
				'trigger'        => 'viewport-enter',
				'effect'         => 'fade-up',
				'playback'       => 'once',
				'engine'         => 'auto',
				'reduced_motion' => 'replace-with-fade',
			],
		];

		$result = MotionPlanCompiler::compile(
			$spec,
			[ 'renderer' => 'gutenberg-fse', 'direction' => [ 'entrance_animation' => 'hero_only' ] ]
		);

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertCount( 1, $result['operations'] );
		self::assertSame( 'hero-copy', $result['operations'][0]['target_id'] );
		self::assertSame( 'motion_direction_hero_only', $result['unsupported'][0]['reason'] );
	}

	public function test_explicit_unavailable_engines_never_silently_downgrade(): void {
		foreach ( [ 'gsap', 'waapi', 'provider' ] as $engine ) {
			$spec                              = self::spec();
			$spec['sections'][0]['motion'][0]['engine'] = $engine;
			if ( 'provider' === $engine ) {
				// Validator demands identity; the compiler still refuses because
				// no provider adapter is approved in core.
				$spec['sections'][0]['motion'][0]['provider_id'] = 'kadence-motion';
			}

			$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v3' ] );

			self::assertNotInstanceOf( \WP_Error::class, $result );
			self::assertSame( [], $result['operations'], "engine {$engine} must not produce an operation" );
			self::assertCount( 1, $result['unsupported'], "engine {$engine}" );
		}
	}

	public function test_bundled_preset_refuses_an_incompatible_trigger(): void {
		$spec                                        = self::spec();
		$spec['sections'][0]['motion'][0]['effect']  = 'card-lift';
		$spec['sections'][0]['motion'][0]['engine']  = 'css';

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'gutenberg-fse' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $result['operations'] );
		self::assertSame( 'preset_trigger_incompatible', $result['unsupported'][0]['reason'] );
	}

	public function test_explicit_native_without_controls_blocks_with_css_proposal(): void {
		$spec                                       = self::spec();
		$spec['sections'][0]['motion'][0]['trigger'] = 'hover';
		$spec['sections'][0]['motion'][0]['engine']  = 'native';
		$spec['sections'][0]['motion'][]            = [
			'id'             => 'hero-focus',
			'purpose'        => 'feedback',
			'target_id'      => 'hero-copy',
			'trigger'        => 'focus-visible',
			'effect'         => 'fade-up',
			'playback'       => 'once',
			'engine'         => 'native',
			'reduced_motion' => 'replace-with-fade',
		];

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v3' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertSame( [], $result['operations'] );
		self::assertCount( 2, $result['unsupported'] );
		self::assertContains(
			'native_lowering_unsupported',
			array_column( $result['unsupported'], 'reason' )
		);
		self::assertContains(
			'bundled-css-explicit-approval',
			array_column( $result['warnings'], 'proposed_alternative' )
		);
	}

	public function test_explicit_native_with_v3_entrance_resolves_to_evidence_patch(): void {
		$spec                                        = self::spec();
		$spec['sections'][0]['motion'][0]['engine']  = 'native';

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v3' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertCount( 1, $result['operations'] );
		self::assertSame( 'settings-evidence-patch', $result['operations'][0]['op'] );
	}

	public function test_v4_nonessential_motion_refuses_native_and_proposes_css_for_approval(): void {
		// Fixture item uses replace-with-fade (nonessential) on V4.
		$result = MotionPlanCompiler::compile( self::spec(), [ 'renderer' => 'elementor-v4' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $result['operations'], 'auto must not silently fall back to CSS on V4 reduced-motion refusal' );
		self::assertSame( 'v4_write_adapter_unavailable', $result['unsupported'][0]['reason'] );
		self::assertNull( $result['warnings'][0]['proposed_alternative'] );
	}

	public function test_v4_essential_motion_compiles_to_native_interaction(): void {
		$spec                                        = self::spec();
		$spec['sections'][0]['motion'][0]['reduced_motion'] = 'preserve-essential';

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v4', 'capability_digest' => self::v4_digest() ] );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertCount( 1, $result['operations'] );
		self::assertSame( 'interactions-replace', $result['operations'][0]['op'] );
		self::assertSame( 'scrollIn', $result['operations'][0]['interaction']['trigger'] );
		self::assertTrue( $result['operations'][0]['all_devices_only'] );
	}

	public function test_signed_plan_verifier_refuses_tampering_and_missing_bound_context(): void {
		$plan = MotionPlanCompiler::compile(
			self::spec(),
			[
				'renderer' => 'gutenberg-fse',
				'direction' => [ 'id' => 'editorial', 'version' => '2', 'hash' => str_repeat( 'b', 64 ) ],
			]
		);
		self::assertNotInstanceOf( \WP_Error::class, $plan );

		$missing = MotionPlanVerifier::verify( $plan );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_motion_plan_direction_stale', $missing->get_error_code() );

		$plan['operations'][0]['classes'][] = 'tampered';
		$tampered = MotionPlanVerifier::verify(
			$plan,
			null,
			[ 'id' => 'editorial', 'version' => '2', 'hash' => str_repeat( 'b', 64 ) ]
		);
		self::assertInstanceOf( \WP_Error::class, $tampered );
		self::assertSame( 'stonewright_motion_plan_signature_mismatch', $tampered->get_error_code() );
	}

	public function test_v4_device_subset_is_refused_not_widened(): void {
		$spec                                       = self::spec();
		$spec['sections'][0]['motion'][0]['devices'] = [ 'mobile' ];

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v4' ] );

		self::assertSame( [], $result['operations'] );
		self::assertSame( 'device_variation_not_representable', $result['unsupported'][0]['reason'] );

		// Even explicit css engine is refused on V4 for subsets.
		$spec['sections'][0]['motion'][0]['engine'] = 'css';
		$result                                     = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v4' ] );
		self::assertSame( [], $result['operations'] );
	}

	public function test_v3_auto_lowers_to_native_evidence_patch(): void {
		$result = MotionPlanCompiler::compile( self::spec(), [ 'renderer' => 'elementor-v3' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertCount( 1, $result['operations'] );
		$op = $result['operations'][0];
		self::assertSame( 'settings-evidence-patch', $op['op'] );
		self::assertTrue( $op['requires_schema_evidence'] );
		self::assertFalse( $op['pro_required'] );
	}

	public function test_v3_device_subset_without_native_resolves_to_blocked(): void {
		$spec                                       = self::spec();
		$spec['sections'][0]['motion'][0]['trigger'] = 'hover';
		$spec['sections'][0]['motion'][0]['devices'] = [ 'desktop' ];
		$spec['sections'][0]['motion'][]            = [
			'id'             => 'hero-focus',
			'purpose'        => 'feedback',
			'target_id'      => 'hero-copy',
			'trigger'        => 'focus-visible',
			'effect'         => 'fade-up',
			'playback'       => 'once',
			'engine'         => 'auto',
			'devices'        => [ 'desktop' ],
			'reduced_motion' => 'replace-with-fade',
		];

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v3' ] );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::err( $result ) );
		self::assertSame( [], $result['operations'] );
		self::assertSame(
			[ 'device_variation_requires_native_controls', 'device_variation_requires_native_controls' ],
			array_column( $result['unsupported'], 'reason' )
		);
	}

	public function test_viewport_progress_lowings_are_honest(): void {
		$spec                                       = self::spec();
		$spec['sections'][0]['motion'][0]['trigger'] = 'viewport-progress';

		$gut = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'gutenberg-fse' ] );
		self::assertSame( 'viewport_progress_enhancement_only', $gut['unsupported'][0]['reason'] );

		$v3 = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v3' ] );
		self::assertSame( 'settings-evidence-patch', $v3['operations'][0]['op'] );
		self::assertTrue( $v3['operations'][0]['pro_required'] );

		$v4 = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'elementor-v4' ] );
		self::assertContains(
			'viewport_progress_unsupported_v4',
			array_column( $v4['unsupported'], 'reason' )
		);
	}

	public function test_loop_playback_is_refused_everywhere(): void {
		$spec                                        = self::spec();
		$spec['sections'][0]['motion'][0]['playback'] = 'loop';
		$spec['sections'][0]['motion'][0]['control_target_id'] = 'hero-copy';
		$spec['sections'][0]['motion'][0]['control_label']     = 'Pause';

		foreach ( [ 'gutenberg-fse', 'elementor-v3', 'elementor-v4' ] as $renderer ) {
			$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => $renderer ] );
			self::assertContains(
				'loop_control_binding_unavailable',
				array_column( $result['unsupported'], 'reason' ),
				$renderer
			);
		}
	}

	public function test_marker_collision_is_refused_before_render(): void {
		$spec               = self::spec();
		$spec['sections'][] = [
			'id'     => 'Hero Copy',
			'blocks' => [
				[ 'id' => 'other-copy', 'type' => 'paragraph', 'text' => 'Second.' ],
			],
		];

		$result = MotionPlanCompiler::compile( $spec, [ 'renderer' => 'gutenberg-fse' ] );

		// 'hero-copy' and 'Hero Copy' sanitize identically.
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_marker_collision', $result->get_error_code() );
	}

	public function test_unknown_renderer_is_rejected(): void {
		$result = MotionPlanCompiler::compile( self::spec(), [ 'renderer' => 'framer' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_renderer_unknown', $result->get_error_code() );
	}

	/* --------------------------- Suggest engine ------------------------ */

	public function test_suggest_is_deterministic_with_max_three_proposals(): void {
		$input  = self::suggest_input();
		$first  = MotionSuggestEngine::suggest( $input );
		$second = MotionSuggestEngine::suggest( $input );

		self::assertSame( $first, $second );
		self::assertLessThanOrEqual( 3, $first['proposal_count'] );
		self::assertTrue( $first['no_motion_valid'] );
		self::assertCount( 1, array_filter( $first['proposals'], fn( $p ) => $p['recommended'] ) );
		self::assertSame( $first['recommended_id'], $first['proposals'][ array_search( true, array_column( $first['proposals'], 'recommended' ), true ) ]['id'] ?? '' );
	}

	public function test_suggest_always_offers_no_motion_and_recommends_orientation_by_default(): void {
		$out = MotionSuggestEngine::suggest( self::suggest_input() );

		self::assertSame( 'subtle-orientation', $out['recommended_id'] );
		$ids = array_column( $out['proposals'], 'id' );
		self::assertContains( 'no-motion', $ids );
	}

	public function test_suggest_blocked_direction_leaves_only_no_motion(): void {
		$input                   = self::suggest_input();
		$input['direction']      = [ 'entrance_animation' => 'blocked' ];

		$out = MotionSuggestEngine::suggest( $input );

		self::assertSame( [ 'no-motion' ], array_column( $out['proposals'], 'id' ) );
		self::assertSame( 'no-motion', $out['recommended_id'] );
	}

	public function test_suggest_level_none_leaves_only_no_motion(): void {
		$input                      = self::suggest_input();
		$input['preferences']['level'] = 'none';

		$out = MotionSuggestEngine::suggest( $input );

		self::assertSame( [ 'no-motion' ], array_column( $out['proposals'], 'id' ) );
		self::assertSame( 'no-motion', $out['recommended_id'] );
	}

	public function test_suggest_hero_only_filters_rhythm_template(): void {
		$input              = self::suggest_input();
		$input['direction'] = [ 'entrance_animation' => 'hero_only' ];
		// Force a repeated group so the rhythm template would exist.
		$input['sections'][] = [
			'id'     => 'features',
			'role'   => 'features',
			'blocks' => [
				[ 'id' => 'c1', 'type' => 'card' ],
				[ 'id' => 'c2', 'type' => 'card' ],
				[ 'id' => 'c3', 'type' => 'card' ],
			],
		];

		$out    = MotionSuggestEngine::suggest( $input );
		$templates = array_column( $out['proposals'], 'template' );

		self::assertNotContains( 'stagger-rhythm', $templates );
	}

	public function test_suggest_repeated_cards_propose_stagger(): void {
		$input = self::suggest_input();
		$input['sections'] = [
			[
				'id'   => 'features',
				'role' => 'features',
				'blocks' => [
					[ 'id' => 'c1', 'type' => 'card' ],
					[ 'id' => 'c2', 'type' => 'card' ],
					[ 'id' => 'c3', 'type' => 'card' ],
				],
			],
		];

		$out = MotionSuggestEngine::suggest( $input );

		self::assertContains( 'stagger-rhythm', array_column( $out['proposals'], 'template' ) );
	}

	public function test_suggest_static_page_recommends_no_motion(): void {
		$out = MotionSuggestEngine::suggest( [ 'renderer' => 'gutenberg-fse', 'sections' => [] ] );

		self::assertSame( 'no-motion', $out['recommended_id'] );
	}

	/* ----------------------------- Abilities ---------------------------- */

	public function test_abilities_execute_read_only(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_posts'] = true;

		$suggest = new MotionSuggest();
		self::assertTrue( $suggest->permission_callback( [] ) );
		$out = $suggest->execute( [ 'sections' => [] ] );
		self::assertTrue( $out['ok'] );
		self::assertTrue( $out['read_only'] );

		$plan = new MotionPlan();
		self::assertTrue( $plan->permission_callback( [] ) );
		$out = $plan->execute( [ 'spec' => self::spec(), 'renderer' => 'gutenberg-fse' ] );
		self::assertNotInstanceOf( \WP_Error::class, $out, self::err( $out ) );
		self::assertSame( 'plan', $out['mode'] );
	}

	/* ------------------------------ Helpers ------------------------------ */

	private static function spec( string $copy = 'Fixture copy text.' ): array {
		return [
			'version'  => '2.0.0',
			'page'     => [ 'title' => 'Motion page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'role'   => 'hero',
					'blocks' => [
						[ 'id' => 'hero-copy', 'type' => 'paragraph', 'text' => $copy ],
					],
					'motion' => [
						[
							'id'             => 'hero-enter',
							'purpose'        => 'orient',
							'target_id'      => 'hero-copy',
							'trigger'        => 'viewport-enter',
							'effect'         => 'fade-up',
							'playback'       => 'once',
							'engine'         => 'auto',
							'reduced_motion' => 'replace-with-fade',
						],
					],
				],
			],
		];
	}

	private static function suggest_input(): array {
		return [
			'renderer' => 'gutenberg-fse',
			'sections' => [
				[
					'id'   => 'hero',
					'role' => 'hero',
					'blocks' => [
						[ 'id' => 'h-copy', 'type' => 'paragraph' ],
						[ 'id' => 'h-img', 'type' => 'image' ],
					],
				],
			],
		];
	}

	private static function v4_digest(): array {
		return [
			'schema_fingerprint' => str_repeat( 'c', 64 ),
			'renderers' => [
				'elementor-v4' => [
					'write_adapter_ready' => true,
					'interaction_triggers' => [ 'load', 'scrollIn', 'scrollOut', 'scrollOn', 'hover', 'click' ],
				],
			],
		];
	}

	private static function err( mixed $result ): string {
		if ( ! $result instanceof \WP_Error ) {
			return '';
		}
		$errors = $result->get_error_data()['errors'] ?? [];
		return implode(
			'; ',
			array_map(
				static fn( array $e ): string => ( $e['keyword'] ?? '?' ) . '@' . ( $e['path_string'] ?? '' ),
				(array) $errors
			)
		);
	}
}
