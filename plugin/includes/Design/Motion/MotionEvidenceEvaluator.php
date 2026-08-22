<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Motion evidence evaluation: accessibility, parity, and performance.
 *
 * Implements the plan's hard-failure/warning matrix over measured evidence.
 * Missing measurements produce not_checked — never an assumed pass and never
 * an invented quality score. Findings follow the existing coverage model:
 * severity-coded findings plus explicit checked/not_checked lists.
 */
final class MotionEvidenceEvaluator {

	/**
	 * @param array<string, mixed> $evidence {
	 *    js_disabled_invisible_targets: list<string> targets hidden or removed from flow,
	 *    reduced_motion_respected: bool|null,
	 *    live_preference_change_handled: bool|null,
	 *    focus_parity_present: bool|null,
	 *    autoplay_duration_ms: int|null, autoplay_control_present: bool|null,
	 *    flashes_per_second: float|null,
	 *    horizontal_overflow_px: float,
	 *    structural_readback_match: bool|null, editor_reopen_ok: bool|null,
	 *    cls: float|null, cls_delta: float|null, lcp_ms: float|null,
	 *    baseline_lcp_ms: float|null, action_latency_ms: float|null,
	 *    action_latency_regression_ms: float|null, frame_drop_ratio: float|null,
	 *    p95_frame_ms: float|null, long_tasks_over_50_ms: int|null,
	 *    entrance_max_ms: int, primary_content_delay_ms: int,
	 *    uses_blur: bool, simultaneous_effects: int, policy_max_concurrent: int,
	 *    hero_animated: bool, mobile_intensity_flag: bool,
	 *    asset_bytes: array{css?:int, js?:int}, budgets: array{css:int, js:int},
	 * }
	 * @return array<string, mixed>
	 */
	public static function evaluate( array $evidence ): array {
		$findings   = [];
		$checked    = [];
		$not_checked = [];

		$hard = self::hard_failures( $evidence, $checked, $not_checked );
		foreach ( $hard as $code ) {
			$findings[] = [ 'severity' => 'fail', 'code' => $code ];
		}

		foreach ( self::warnings( $evidence, $checked, $not_checked ) as $code ) {
			$findings[] = [ 'severity' => 'warning', 'code' => $code ];
		}

		$has_fail    = [] !== array_filter( $findings, static fn( array $f ): bool => 'fail' === $f['severity'] );
		$has_warning = [] !== array_filter( $findings, static fn( array $f ): bool => 'warning' === $f['severity'] );
		$verdict     = $has_fail ? 'fail' : ( $has_warning ? 'warning' : ( [] !== $not_checked ? 'not_checked' : 'pass' ) );

		return [
			'ok'          => 'fail' !== $verdict,
			'verdict'     => $verdict,
			'findings'    => $findings,
			'coverage'    => [
				'checked'     => array_values( $checked ),
				'not_checked' => array_values( $not_checked ),
			],
		];
	}

	/**
	 * @param array<string, mixed> $evidence
	 * @param list<string>         $checked
	 * @param list<string>         $not_checked
	 * @return list<string>
	 */
	private static function hard_failures( array $evidence, array &$checked, array &$not_checked ): array {
		$fails = [];

		$invisible = $evidence['js_disabled_invisible_targets'] ?? null;
		if ( is_array( $invisible ) ) {
			$checked[] = 'no_js_static_state';
			if ( [] !== $invisible ) {
				$fails[] = 'content_invisible_without_js';
			}
		} else {
			$not_checked[] = 'no_js_static_state';
		}

		self::bool_check( $evidence, 'reduced_motion_respected', 'reduced_motion', $checked, $not_checked, static function ( bool $v ) use ( &$fails ): void {
			if ( ! $v ) {
				$fails[] = 'reduced_motion_ignored';
			}
		} );
		self::bool_check( $evidence, 'live_preference_change_handled', 'live_reduced_motion_change', $checked, $not_checked, static function ( bool $v ) use ( &$fails ): void {
			if ( ! $v ) {
				$fails[] = 'live_reduced_motion_change_failed';
			}
		} );

		self::bool_check( $evidence, 'focus_parity_present', 'hover_focus_parity', $checked, $not_checked, static function ( bool $v ) use ( &$fails ): void {
			if ( ! $v ) {
				$fails[] = 'hover_without_focus_parity';
			}
		} );

		$autoplay_ms      = $evidence['autoplay_duration_ms'] ?? null;
		$autoplay_control = $evidence['autoplay_control_present'] ?? null;
		if ( is_int( $autoplay_ms ) && is_bool( $autoplay_control ) ) {
			$checked[] = 'pause_stop_hide';
			if ( $autoplay_ms > 5000 && ! $autoplay_control ) {
				$fails[] = 'autoplay_over_5s_without_persistent_control';
			}
		} else {
			$not_checked[] = 'pause_stop_hide';
		}

		$flashes = $evidence['flashes_per_second'] ?? null;
		if ( is_numeric( $flashes ) ) {
			$checked[] = 'three_flashes_threshold';
			if ( (float) $flashes > 3.0 ) {
				$fails[] = 'flash_threshold_exceeded';
			}
		} else {
			// Unmeasured flash thresholds block release per plan, surfaced via not_checked.
			$not_checked[] = 'three_flashes_threshold';
		}

		$overflow = $evidence['horizontal_overflow_px'] ?? null;
		if ( is_numeric( $overflow ) ) {
			$checked[] = 'no_horizontal_overflow';
			if ( (float) $overflow > 1.0 ) {
				$fails[] = 'horizontal_overflow';
			}
		} else {
			$not_checked[] = 'no_horizontal_overflow';
		}

		self::bool_check( $evidence, 'structural_readback_match', 'structural_readback', $checked, $not_checked, static function ( bool $v ) use ( &$fails ): void {
			if ( ! $v ) {
				$fails[] = 'write_readback_mismatch';
			}
		} );

		self::bool_check( $evidence, 'editor_reopen_ok', 'editor_parity', $checked, $not_checked, static function ( bool $v ) use ( &$fails ): void {
			if ( ! $v ) {
				$fails[] = 'editor_parity_failure';
			}
		} );

		self::metric_max( $evidence, 'cls', 'cumulative_layout_shift', 0.10, 'cls_over_0_10', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'cls_delta', 'motion_cls_delta', 0.02, 'motion_cls_delta_over_0_02', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'action_latency_ms', 'motion_action_latency', 200.0, 'motion_action_latency_over_200ms', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'action_latency_regression_ms', 'motion_action_latency_regression', 20.0, 'motion_action_latency_regression_over_20ms', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'frame_drop_ratio', 'motion_frame_drop_ratio', 0.05, 'motion_frame_drop_ratio_over_5_percent', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'p95_frame_ms', 'motion_p95_frame_time', 33.0, 'motion_p95_frame_over_33ms', $checked, $not_checked, $fails );
		self::metric_max( $evidence, 'long_tasks_over_50_ms', 'motion_long_tasks', 0.0, 'motion_long_task_over_50ms', $checked, $not_checked, $fails );

		$lcp      = $evidence['lcp_ms'] ?? null;
		$baseline = $evidence['baseline_lcp_ms'] ?? null;
		if ( is_numeric( $lcp ) ) {
			$checked[] = 'largest_contentful_paint';
			if ( (float) $lcp > 2500.0 && ( ! is_numeric( $baseline ) || (float) $baseline <= 2500.0 ) ) {
				$fails[] = 'lcp_over_2500ms';
			}
		} else {
			$not_checked[] = 'largest_contentful_paint';
		}
		if ( is_numeric( $lcp ) && is_numeric( $baseline ) ) {
			$checked[] = 'motion_lcp_regression';
			$allowed   = max( 100.0, (float) $baseline * 0.05 );
			if ( (float) $lcp - (float) $baseline > $allowed ) {
				$fails[] = 'motion_lcp_regression_over_budget';
			}
		} else {
			$not_checked[] = 'motion_lcp_regression';
		}

		return $fails;
	}

	/**
	 * @param array<string, mixed> $evidence
	 * @param list<string>         $checked
	 * @param list<string>         $not_checked
	 * @return list<string>
	 */
	private static function warnings( array $evidence, array &$checked, array &$not_checked ): array {
		$out = [];

		$entrance_max = $evidence['entrance_max_ms'] ?? null;
		if ( is_int( $entrance_max ) ) {
			$checked[] = 'entrance_duration_budget';
			if ( $entrance_max > 600 ) {
				$out[] = 'entrance_exceeds_600ms_needs_storytelling_justification';
			}
		} else {
			$not_checked[] = 'entrance_duration_budget';
		}

		$delay = $evidence['primary_content_delay_ms'] ?? null;
		if ( is_int( $delay ) && $delay >= 300 ) {
			$out[] = 'large_delay_on_primary_content';
		}

		if ( true === ( $evidence['uses_blur'] ?? null ) ) {
			$out[] = 'blur_filter_cost';
		}

		$concurrent = $evidence['simultaneous_effects'] ?? null;
		$max        = $evidence['policy_max_concurrent'] ?? 3;
		if ( is_int( $concurrent ) ) {
			$checked[] = 'motion_density';
			if ( $concurrent > (int) $max ) {
				$out[] = 'motion_density_over_policy';
			}
		} else {
			$not_checked[] = 'motion_density';
		}

		if ( true === ( $evidence['hero_animated'] ?? null ) ) {
			$out[] = 'hero_lcp_element_animated';
		}
		if ( true === ( $evidence['mobile_intensity_flag'] ?? null ) ) {
			$out[] = 'mobile_effect_too_intense';
		}

		$lcp      = $evidence['lcp_ms'] ?? null;
		$baseline = $evidence['baseline_lcp_ms'] ?? null;
		if ( is_numeric( $lcp ) && is_numeric( $baseline ) && (float) $baseline > 2500.0 ) {
			$allowed = max( 100.0, (float) $baseline * 0.05 );
			if ( (float) $lcp > 2500.0 && (float) $lcp - (float) $baseline <= $allowed ) {
				$out[] = 'lcp_baseline_already_red_not_worsened';
			}
		}

		$bytes   = is_array( $evidence['asset_bytes'] ?? null ) ? $evidence['asset_bytes'] : [];
		$budgets = is_array( $evidence['budgets'] ?? null ) ? $evidence['budgets'] : [];
		foreach ( [ 'css', 'js' ] as $kind ) {
			if ( isset( $bytes[ $kind ], $budgets[ $kind ] ) ) {
				$checked[] = "asset_budget_{$kind}";
				if ( (int) $bytes[ $kind ] > (int) $budgets[ $kind ] ) {
					$out[] = "asset_budget_exceeded_{$kind}";
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $evidence
	 * @param list<string>         $checked
	 * @param list<string>         $not_checked
	 */
	private static function bool_check( array $evidence, string $key, string $label, array &$checked, array &$not_checked, callable $on_value ): void {
		$value = $evidence[ $key ] ?? null;
		if ( is_bool( $value ) ) {
			$checked[] = $label;
			$on_value( $value );
			return;
		}
		$not_checked[] = $label;
	}

	/**
	 * @param array<string, mixed> $evidence
	 * @param list<string>         $checked
	 * @param list<string>         $not_checked
	 * @param list<string>         $fails
	 */
	private static function metric_max( array $evidence, string $key, string $label, float $max, string $code, array &$checked, array &$not_checked, array &$fails ): void {
		$value = $evidence[ $key ] ?? null;
		if ( ! is_numeric( $value ) ) {
			$not_checked[] = $label;
			return;
		}
		$checked[] = $label;
		if ( (float) $value > $max ) {
			$fails[] = $code;
		}
	}
}
