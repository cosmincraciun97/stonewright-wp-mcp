<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Motion\MotionApplyStateMachine;
use Stonewright\WpMcp\Design\Motion\MotionEvidenceEvaluator;
use Stonewright\WpMcp\Design\Motion\UiExcellencePolicyManifest;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\MotionApplyStateMachine
 * @covers \Stonewright\WpMcp\Design\Motion\MotionEvidenceEvaluator
 * @covers \Stonewright\WpMcp\Design\Motion\UiExcellencePolicyManifest
 */
final class MotionRecoveryAndEvidenceTest extends TestCase {

	/* ------------------------- State machine ------------------------- */

	public function test_happy_path_advances_in_order(): void {
		$sm = new MotionApplyStateMachine();
		foreach ( [ 'approved', 'snapshotted', 'applied', 'readback_verified', 'editor_verified', 'frontend_verified', 'quality_verified', 'complete' ] as $step ) {
			self::assertTrue( $sm->advance( $step ), $step );
		}
		self::assertSame( 'complete', $sm->state() );
		self::assertSame( 0, $sm->repair_count() );
	}

	public function test_illegal_skips_are_refused(): void {
		$sm = new MotionApplyStateMachine();
		self::assertFalse( $sm->advance( 'applied' ) );
		self::assertSame( 'planned', $sm->state() );
	}

	public function test_safe_once_allows_exactly_one_allowlisted_repair(): void {
		$sm     = new MotionApplyStateMachine();
		$sm->advance( 'approved' );
		$sm->advance( 'snapshotted' );
		$sm->advance( 'applied' );

		$first = $sm->on_failure( 'motion_class_missing_on_element', MotionApplyStateMachine::POLICY_SAFE_ONCE );
		self::assertSame( 'repair', $first['action'] );
		$sm->reset_to_applied();

		$second = $sm->on_failure( 'motion_class_missing_on_element', MotionApplyStateMachine::POLICY_SAFE_ONCE );
		self::assertSame( 'rollback', $second['action'] );
		self::assertSame( 'repair_budget_spent', $second['reason'] );
	}

	public function test_subjective_and_drift_failures_never_self_repair(): void {
		foreach ( [ 'stonewright_spec_invalid', 'schema_drift', 'missing_provider', 'custom_code_required', 'security_failure', 'editor_corruption' ] as $code ) {
			$sm  = new MotionApplyStateMachine();
			$out = $sm->on_failure( $code, MotionApplyStateMachine::POLICY_SAFE_ONCE );
			self::assertSame( [ 'action' => 'rollback', 'reason' => 'not_repairable', 'code' => $code ], $out, $code );
		}
	}

	public function test_never_policy_and_outside_allowlist_rollback(): void {
		$sm = new MotionApplyStateMachine();
		self::assertSame( 'rollback', $sm->on_failure( 'motion_class_missing_on_element', MotionApplyStateMachine::POLICY_NEVER )['action'] );

		$sm2 = new MotionApplyStateMachine();
		self::assertSame(
			'outside_allowlist',
			$sm2->on_failure( 'looks_not_fabulous', MotionApplyStateMachine::POLICY_SAFE_ONCE )['reason']
		);
	}

	/* ----------------------- Evidence evaluator ----------------------- */

	public function test_full_green_evidence_passes(): void {
		$out = MotionEvidenceEvaluator::evaluate( self::good() );

		self::assertTrue( $out['ok'] );
		self::assertSame( 'pass', $out['verdict'] );
		self::assertNotContains( 'fail', array_column( $out['findings'], 'severity' ) );
		self::assertSame( [], $out['coverage']['not_checked'] );
	}

	public function test_missing_measurements_are_not_checked_never_pass_by_assumption(): void {
		$out = MotionEvidenceEvaluator::evaluate( [] );

		self::assertSame( 'not_checked', $out['verdict'] );
		self::assertContains( 'no_js_static_state', $out['coverage']['not_checked'] );
		self::assertContains( 'reduced_motion', $out['coverage']['not_checked'] );
		self::assertContains( 'three_flashes_threshold', $out['coverage']['not_checked'] );
	}

	public function test_hard_failures_fire_on_violations(): void {
		$bad               = self::good();
		$bad['js_disabled_invisible_targets'] = [ 'hero-copy' ];
		$bad['reduced_motion_respected'] = false;
		$bad['horizontal_overflow_px']   = 24;

		$out    = MotionEvidenceEvaluator::evaluate( $bad );
		$codes  = array_column( $out['findings'], 'code' );

		self::assertFalse( $out['ok'] );
		self::assertContains( 'content_invisible_without_js', $codes );
		self::assertContains( 'reduced_motion_ignored', $codes );
		self::assertContains( 'horizontal_overflow', $codes );
	}

	public function test_warnings_surface_for_intensity_budgets(): void {
		$evidence                     = self::good();
		$evidence['entrance_max_ms']  = 900;
		$evidence['uses_blur']        = true;
		$evidence['hero_animated']    = true;

		$out   = MotionEvidenceEvaluator::evaluate( $evidence );
		$codes = array_column( $out['findings'], 'code' );

		self::assertTrue( $out['ok'], 'warnings never fail the verdict alone' );
		self::assertContains( 'entrance_exceeds_600ms_needs_storytelling_justification', $codes );
		self::assertContains( 'blur_filter_cost', $codes );
		self::assertContains( 'hero_lcp_element_animated', $codes );
	}

	public function test_runtime_and_performance_regressions_fail_closed(): void {
		$bad                                  = self::good();
		$bad['live_preference_change_handled'] = false;
		$bad['cls_delta']                      = 0.03;
		$bad['lcp_ms']                         = 2000;
		$bad['baseline_lcp_ms']                = 1800;
		$bad['p95_frame_ms']                   = 40;

		$out   = MotionEvidenceEvaluator::evaluate( $bad );
		$codes = array_column( $out['findings'], 'code' );

		self::assertSame( 'fail', $out['verdict'] );
		self::assertContains( 'live_reduced_motion_change_failed', $codes );
		self::assertContains( 'motion_cls_delta_over_0_02', $codes );
		self::assertContains( 'motion_lcp_regression_over_budget', $codes );
		self::assertContains( 'motion_p95_frame_over_33ms', $codes );
	}

	private static function good(): array {
		return [
			'js_disabled_invisible_targets'  => [],
			'reduced_motion_respected'       => true,
			'live_preference_change_handled' => true,
			'focus_parity_present'           => true,
			'autoplay_duration_ms'           => 0,
			'autoplay_control_present'       => true,
			'flashes_per_second'             => 0,
			'horizontal_overflow_px'         => 0,
			'structural_readback_match'      => true,
			'editor_reopen_ok'               => true,
			'cls'                            => 0.01,
			'cls_delta'                      => 0.001,
			'lcp_ms'                         => 1800,
			'baseline_lcp_ms'                => 1750,
			'action_latency_ms'              => 80,
			'action_latency_regression_ms'   => 5,
			'frame_drop_ratio'               => 0.01,
			'p95_frame_ms'                   => 16,
			'long_tasks_over_50_ms'          => 0,
			'entrance_max_ms'                => 280,
			'primary_content_delay_ms'       => 0,
			'simultaneous_effects'           => 1,
			'asset_bytes'                    => [ 'css' => 3000, 'js' => 2000 ],
			'budgets'                        => [ 'css' => 8192, 'js' => 6144 ],
		];
	}

	/* ------------------------ Policy manifest ------------------------- */

	public function test_overrides_require_provenance_and_respect_accessibility_floor(): void {
		$result = UiExcellencePolicyManifest::with_overrides(
			[ 'min_font_px.interactive' => [ 'value' => 18, 'provenance' => 'direction:editorial-large-type' ] ]
		);
		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( 18, $result['min_font_px']['interactive'] );

		self::assertInstanceOf(
			\WP_Error::class,
			UiExcellencePolicyManifest::with_overrides( [ 'min_font_px.interactive' => [ 'value' => 12 ] ] )
		);
		self::assertInstanceOf(
			\WP_Error::class,
			UiExcellencePolicyManifest::with_overrides( [ 'touch_target_px.stonewright_target' => [ 'value' => 20, 'provenance' => 'direction:x' ] ] )
		);
	}

	public function test_evaluation_uses_checked_not_checked_model_without_scores(): void {
		$out = UiExcellencePolicyManifest::evaluate(
			[
				'touch_target.min_px'                 => 20,
				'body_copy_line_length.max_observed'  => 95,
				'spacing.adherence_ratio'             => 0.6,
				'motion.concurrent_entrances'         => 5,
				'structure.max_consecutive_identical_sections' => 3,
			]
		);

		self::assertFalse( $out['ok'] );
		self::assertSame( 'fail', $out['verdict'] );
		self::assertContains( 'touch_target_below_wcag_minimum', array_column( $out['findings'], 'code' ) );
		self::assertContains( 'line_length_over_90', array_column( $out['findings'], 'code' ) );
		self::assertContains( 'interactive_font_size', $out['coverage']['not_checked'] );
	}

	public function test_hero_warning_requires_immersive_intent_or_affordance(): void {
		$out = UiExcellencePolicyManifest::evaluate(
			[
				'hero.height_vh_at_900px'      => 95,
				'hero.intent'                  => 'immersive',
				'hero.next_affordance_visible' => true,
			]
		);

		self::assertNotContains( 'oversized_hero_without_immersive_intent', array_column( $out['findings'], 'code' ) );
	}
}
