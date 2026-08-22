<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Versioned UI-excellence policy manifest (plan §11.3).
 *
 * Product defaults — not universal aesthetic truth. Overrides must carry
 * provenance from the active design direction, stay inside accessibility
 * limits, and appear in the receipt. Evaluators report measurable deviations;
 * aesthetic judgement that cannot be measured is not_checked and goes to
 * human UAT. No invented AI quality score.
 */
final class UiExcellencePolicyManifest {

	public const VERSION = '1.0.0';

	private const HARD_LIMITS = [
		'min_interactive_font_px' => 16,
		'min_touch_target_px'     => 24,
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'version'                     => self::VERSION,
			'body_copy_line_length'       => [ 'min' => 45, 'target' => 75, 'warn_above' => 90 ],
			'content_measure_ch'          => [ 'body_max' => 72, 'headings_max' => 28 ],
			'min_font_px'                 => [ 'interactive' => 16, 'caption_with_contrast' => 14 ],
			'touch_target_px'             => [ 'wcag_min' => 24, 'stonewright_target' => 44 ],
			'spacing_adherence_min_ratio' => 0.8,
			'max_consecutive_same_structure' => 2,
			'max_concurrent_entrances'    => 3,
			'dominant_effects_per_viewport' => 1,
			'hero_warning_vh_at_900px'    => [ 'threshold' => 85, 'requires_intent' => 'immersive', 'requires_next_affordance' => true ],
			'required_component_states'   => [
				'selected',
				'expanded',
				'loading',
				'error',
				'success',
				'disabled',
				'validation',
			],
			'motion_density_strict_mode'  => false,
		];
	}

	/**
	 * Applies direction overrides after validating hard limits.
	 *
	 * @param array<string, mixed> $overrides {path => ['value'=>..., 'provenance'=>...]}
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function with_overrides( array $overrides ) {
		$manifest = self::defaults();
		foreach ( $overrides as $path => $entry ) {
			if ( ! is_array( $entry ) || '' === trim( (string) ( $entry['provenance'] ?? '' ) ) ) {
				return new \WP_Error(
					'stonewright_policy_override_invalid',
					sprintf( 'Override "%s" requires provenance from the design direction.', (string) $path ),
					[ 'status' => 422 ]
				);
			}
			$value = $entry['value'] ?? null;
			if ( ! self::respects_hard_limits( (string) $path, $value ) ) {
				return new \WP_Error(
					'stonewright_policy_override_below_accessibility_floor',
					sprintf( 'Override "%s" violates an accessibility hard limit.', (string) $path ),
					[ 'status' => 422 ]
				);
			}
			self::assign( $manifest, (string) $path, $value );
		}
		return $manifest;
	}

	/**
	 * Evaluates measured values against the manifest.
	 *
	 * @param array<string, mixed> $measurements {path => value} using the same paths.
	 * @param array<string, mixed>|null $manifest Pre-built manifest.
	 * @return array<string, mixed>
	 */
	public static function evaluate( array $measurements, ?array $manifest = null ): array {
		$manifest   = $manifest ?? self::defaults();
		$findings   = [];
		$checked    = [];
		$not_checked = [];

		$line_length = $measurements['body_copy_line_length.max_observed'] ?? null;
		if ( is_numeric( $line_length ) ) {
			$checked[] = 'line_length';
			if ( (float) $line_length > (float) $manifest['body_copy_line_length']['warn_above'] ) {
				$findings[] = [ 'severity' => 'warning', 'code' => 'line_length_over_90' ];
			}
		} else {
			$not_checked[] = 'line_length';
		}

		$touch = $measurements['touch_target.min_px'] ?? null;
		if ( is_numeric( $touch ) ) {
			$checked[] = 'touch_target';
			if ( (float) $touch < (float) self::HARD_LIMITS['min_touch_target_px'] ) {
				$findings[] = [ 'severity' => 'fail', 'code' => 'touch_target_below_wcag_minimum' ];
			} elseif ( (float) $touch < (float) $manifest['touch_target_px']['stonewright_target'] ) {
				$findings[] = [ 'severity' => 'warning', 'code' => 'touch_target_below_stonewright_target' ];
			}
		} else {
			$not_checked[] = 'touch_target';
		}

		$font = $measurements['font_size.min_interactive_px'] ?? null;
		if ( is_numeric( $font ) ) {
			$checked[] = 'interactive_font_size';
			if ( (float) $font < (float) self::HARD_LIMITS['min_interactive_font_px'] ) {
				$findings[] = [ 'severity' => 'fail', 'code' => 'interactive_text_below_16px' ];
			}
		} else {
			$not_checked[] = 'interactive_font_size';
		}

		$spacing = $measurements['spacing.adherence_ratio'] ?? null;
		if ( is_numeric( $spacing ) ) {
			$checked[] = 'spacing_scale_adherence';
			if ( (float) $spacing < (float) $manifest['spacing_adherence_min_ratio'] ) {
				$findings[] = [ 'severity' => 'warning', 'code' => 'inconsistent_spacing_scale' ];
			}
		} else {
			$not_checked[] = 'spacing_scale_adherence';
		}

		$consecutive = $measurements['structure.max_consecutive_identical_sections'] ?? null;
		if ( is_int( $consecutive ) ) {
			$checked[] = 'card_monotony';
			if ( $consecutive > (int) $manifest['max_consecutive_same_structure'] ) {
				$findings[] = [ 'severity' => 'warning', 'code' => 'repetitive_section_structure' ];
			}
		} else {
			$not_checked[] = 'card_monotony';
		}

		$concurrent = $measurements['motion.concurrent_entrances'] ?? null;
		if ( is_int( $concurrent ) ) {
			$checked[] = 'motion_density_manifest';
			if ( $concurrent > (int) $manifest['max_concurrent_entrances'] ) {
				$severity = true === $manifest['motion_density_strict_mode'] ? 'fail' : 'warning';
				$findings[] = [ 'severity' => $severity, 'code' => 'motion_density_over_policy' ];
			}
		} else {
			$not_checked[] = 'motion_density_manifest';
		}

		$hero_vh = $measurements['hero.height_vh_at_900px'] ?? null;
		if ( is_numeric( $hero_vh ) ) {
			$checked[] = 'hero_height';
			$intent    = (string) ( $measurements['hero.intent'] ?? '' );
			$affordance = filter_var( $measurements['hero.next_affordance_visible'] ?? false, FILTER_VALIDATE_BOOLEAN );
			if (
				(float) $hero_vh > (float) $manifest['hero_warning_vh_at_900px']['threshold']
				&& $intent !== $manifest['hero_warning_vh_at_900px']['requires_intent']
				&& ! $affordance
			) {
				$findings[] = [ 'severity' => 'warning', 'code' => 'oversized_hero_without_immersive_intent' ];
			}
		} else {
			$not_checked[] = 'hero_height';
		}

		$has_fail    = [] !== array_filter( $findings, static fn( array $f ): bool => 'fail' === $f['severity'] );
		$has_warning = [] !== array_filter( $findings, static fn( array $f ): bool => 'warning' === $f['severity'] );
		$verdict     = $has_fail ? 'fail' : ( $has_warning ? 'warning' : ( [] !== $not_checked ? 'not_checked' : 'pass' ) );

		return [
			'ok'          => 'fail' !== $verdict,
			'verdict'     => $verdict,
			'policy_version' => $manifest['version'],
			'findings'    => $findings,
			'coverage'    => [
				'checked'     => array_values( $checked ),
				'not_checked' => array_values( $not_checked ),
			],
		];
	}

	private static function respects_hard_limits( string $path, mixed $value ): bool {
		if ( 'min_font_px.interactive' === $path && is_numeric( $value ) ) {
			return (float) $value >= (float) self::HARD_LIMITS['min_interactive_font_px'];
		}
		if ( 'touch_target_px.stonewright_target' === $path && is_numeric( $value ) ) {
			return (float) $value >= (float) self::HARD_LIMITS['min_touch_target_px'];
		}
		if ( 'touch_target_px.wcag_min' === $path && is_numeric( $value ) ) {
			return (float) $value >= (float) self::HARD_LIMITS['min_touch_target_px'];
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	private static function assign( array &$manifest, string $path, mixed $value ): void {
		$parts = explode( '.', $path );
		$node  =& $manifest;
		foreach ( $parts as $i => $part ) {
			if ( count( $parts ) - 1 === $i ) {
				$node[ $part ] = $value;
				break;
			}
			if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				$node[ $part ] = [];
			}
			$node =& $node[ $part ];
		}
	}
}
