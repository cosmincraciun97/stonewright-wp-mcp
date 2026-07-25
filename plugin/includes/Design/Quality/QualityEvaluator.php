<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

use Stonewright\WpMcp\DesignTokens\BrandKit;
use WP_Error;

/**
 * Turns rendered browser evidence into a coverage-and-evidence quality report.
 *
 * There is no invented score here. A report says how many checks ran, how many
 * could not run, and for each finding the numbers that produced it. That shape
 * is chosen so a report cannot flatter a page:
 *
 * - A check with no evidence is `not_checked`, counted separately, and named in
 *   `coverage.not_checked_rules`. A thin browser session therefore reads as
 *   unverified rather than clean.
 * - A report with nothing checked has status `not_checked`, never `pass`.
 * - Measurable defects fail. Direction guidance warns, because a direction is
 *   intent and a designer may deviate deliberately.
 * - A waiver named by exact `rule_id` downgrades its findings to `info` and
 *   carries the stated reason into the report. The finding stays visible: a
 *   waiver records an accepted trade-off, it does not delete evidence.
 *
 * Output order is stable — severity, then viewport from widest to narrowest,
 * then rule, then element — so two runs over the same evidence are byte-for-byte
 * comparable and a stored report can be diffed against a later one.
 */
final class QualityEvaluator {

	/** @var string The report schema version. */
	public const SCHEMA_VERSION = '1.0';

	/** @var string Structured error code for an unusable direction contract. */
	public const ERROR_CODE = 'stonewright_quality_invalid';

	/** @var float WCAG AA minimum contrast for normal-size text. */
	public const MIN_CONTRAST_TEXT = 4.5;

	/** @var float WCAG AA minimum contrast for large text. */
	public const MIN_CONTRAST_LARGE = 3.0;

	/** @var float WCAG AA minimum contrast for non-text indicators. */
	public const MIN_CONTRAST_NON_TEXT = 3.0;

	/** @var int Smallest side of an interactive target, in CSS pixels. */
	public const MIN_TARGET_PX = 24;

	/** @var float Text is "large" from this size, in CSS pixels. */
	public const LARGE_TEXT_PX = 24.0;

	/** @var float Bold text is "large" from this size, in CSS pixels. */
	public const LARGE_BOLD_TEXT_PX = 18.66;

	/** @var float Font weight from which text counts as bold. */
	public const BOLD_WEIGHT = 700.0;

	/** @var float Sub-pixel layout rounding the evaluator ignores. */
	public const LAYOUT_TOLERANCE_PX = 1.0;

	/** @var float Type-size rounding the evaluator ignores. */
	public const TYPE_TOLERANCE_PX = 0.5;

	/** @var int Maximum findings carried in one report. */
	public const MAX_FINDINGS = 200;

	/**
	 * Severity order used for report sorting.
	 *
	 * @var array<string,int>
	 */
	private const SEVERITY_RANK = [
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
	];

	/**
	 * Evaluate rendered evidence against the active direction.
	 *
	 * @param array<string,mixed> $evidence  Rendered browser evidence.
	 * @param array<string,mixed> $direction Direction contract the page should follow.
	 * @return array<string,mixed>|WP_Error Report, or a structured error.
	 */
	public static function evaluate( array $evidence, array $direction ): array|WP_Error {
		$validated = QualityEvidenceValidator::validate( $evidence );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}

		$waivers = self::waivers( $direction );
		if ( $waivers instanceof WP_Error ) {
			return $waivers;
		}

		$context = [
			'spacing_scale' => self::pixel_scale( self::direction_group( $direction, 'spacing' ) ),
			'type_scale'    => self::type_scale( self::direction_group( $direction, 'typography' ) ),
		];

		$findings          = [];
		$checked           = 0;
		$not_checked       = 0;
		$not_checked_rules = [];

		foreach ( QualityRuleRegistry::rules() as $rule ) {
			/** @var list<array<string,mixed>> $viewports */
			$viewports = $validated['viewports'];
			foreach ( $viewports as $viewport ) {
				$units = 'viewport' === $rule->scope()
					? [ [ '', $viewport ] ]
					: self::element_units( $viewport );

				foreach ( $units as $unit ) {
					[ $element_ref, $subject ] = $unit;
					if ( ! $rule->applies_to( $subject, $context ) ) {
						continue;
					}

					$result = $rule->evaluate( $subject, $context );
					if ( null === $result ) {
						++$not_checked;
						$not_checked_rules[ $rule->id() ] = true;
						continue;
					}

					++$checked;
					foreach ( $result as $raw ) {
						$findings[] = self::finding( $rule, (string) $viewport['id'], (string) $element_ref, $raw, $waivers );
					}
				}
			}
		}

		usort( $findings, [ self::class, 'compare_findings' ] );

		$total     = count( $findings );
		$truncated = max( 0, $total - self::MAX_FINDINGS );
		$findings  = array_slice( $findings, 0, self::MAX_FINDINGS );

		$rule_ids = array_keys( $not_checked_rules );
		sort( $rule_ids );

		return [
			'schema_version'     => self::SCHEMA_VERSION,
			'status'             => self::status( $findings, $checked ),
			'coverage'           => [
				'checked'           => $checked,
				'not_checked'       => $not_checked,
				'not_checked_rules' => $rule_ids,
			],
			'findings'           => $findings,
			'truncated_findings' => $truncated,
		];
	}

	/**
	 * WCAG relative-luminance contrast ratio for two hex colors.
	 *
	 * @param string $foreground Normalized hex color.
	 * @param string $background Normalized hex color.
	 */
	public static function contrast_ratio( string $foreground, string $background ): ?float {
		return BrandKit::contrast_ratio( $foreground, $background );
	}

	/**
	 * Whether text of this size and weight uses the large-text contrast threshold.
	 *
	 * @param float $size_px Rendered font size.
	 * @param float $weight  Rendered font weight.
	 */
	public static function is_large_text( float $size_px, float $weight ): bool {
		if ( $size_px >= self::LARGE_TEXT_PX ) {
			return true;
		}

		return $weight >= self::BOLD_WEIGHT && $size_px >= self::LARGE_BOLD_TEXT_PX;
	}

	/**
	 * Nearest value in a scale, or null when the scale is empty.
	 *
	 * @param float      $value Observed value.
	 * @param list<float> $scale Direction scale.
	 */
	public static function nearest( float $value, array $scale ): ?float {
		$nearest  = null;
		$distance = null;
		foreach ( $scale as $candidate ) {
			$delta = abs( $value - $candidate );
			if ( null === $distance || $delta < $distance ) {
				$distance = $delta;
				$nearest  = $candidate;
			}
		}

		return $nearest;
	}

	/**
	 * Flatten one viewport into `[element_ref, element]` units.
	 *
	 * @param array<string,mixed> $viewport Normalized viewport.
	 * @return list<array{0:string,1:array<string,mixed>}>
	 */
	private static function element_units( array $viewport ): array {
		$units = [];
		/** @var list<array<string,mixed>> $elements */
		$elements = is_array( $viewport['elements'] ?? null ) ? $viewport['elements'] : [];
		foreach ( $elements as $element ) {
			$units[] = [ (string) ( $element['ref'] ?? '' ), $element ];
		}

		return $units;
	}

	/**
	 * Assemble one finding, applying any waiver for its rule.
	 *
	 * @param QualityRule          $rule        Rule that produced the finding.
	 * @param string               $viewport_id Viewport identifier.
	 * @param string               $element_ref Element reference, empty for viewport rules.
	 * @param array<string,mixed>  $raw         Rule output.
	 * @param array<string,string> $waivers     Waiver reasons by exact rule id.
	 * @return array<string,mixed>
	 */
	private static function finding( QualityRule $rule, string $viewport_id, string $element_ref, array $raw, array $waivers ): array {
		$waived = array_key_exists( $rule->id(), $waivers );

		return [
			'rule_id'       => $rule->id(),
			'severity'      => $waived ? 'info' : $rule->severity(),
			'viewport'      => $viewport_id,
			'element_ref'   => $element_ref,
			'evidence'      => is_array( $raw['evidence'] ?? null ) ? $raw['evidence'] : [],
			'repair_hint'   => (string) ( $raw['repair_hint'] ?? '' ),
			'waived'        => $waived,
			'waiver_reason' => $waived ? $waivers[ $rule->id() ] : '',
		];
	}

	/**
	 * Overall verdict.
	 *
	 * @param list<array<string,mixed>> $findings Sorted findings.
	 * @param int                       $checked  Number of checks that ran.
	 */
	private static function status( array $findings, int $checked ): string {
		$severities = array_column( $findings, 'severity' );
		if ( in_array( 'error', $severities, true ) ) {
			return 'fail';
		}
		if ( in_array( 'warning', $severities, true ) ) {
			return 'warn';
		}

		return $checked > 0 ? 'pass' : 'not_checked';
	}

	/**
	 * Stable finding order: severity, viewport width, rule, element.
	 *
	 * @param array<string,mixed> $left  First finding.
	 * @param array<string,mixed> $right Second finding.
	 */
	private static function compare_findings( array $left, array $right ): int {
		$by_severity = self::SEVERITY_RANK[ (string) $left['severity'] ] <=> self::SEVERITY_RANK[ (string) $right['severity'] ];
		if ( 0 !== $by_severity ) {
			return $by_severity;
		}

		$viewports = array_flip( QualityEvidenceValidator::VIEWPORT_IDS );
		$by_viewport = ( $viewports[ (string) $left['viewport'] ] ?? PHP_INT_MAX ) <=> ( $viewports[ (string) $right['viewport'] ] ?? PHP_INT_MAX );
		if ( 0 !== $by_viewport ) {
			return $by_viewport;
		}

		$by_rule = strcmp( (string) $left['rule_id'], (string) $right['rule_id'] );
		if ( 0 !== $by_rule ) {
			return $by_rule;
		}

		return strcmp( (string) $left['element_ref'], (string) $right['element_ref'] );
	}

	/**
	 * Waiver reasons by exact rule id.
	 *
	 * @param array<string,mixed> $direction Direction contract.
	 * @return array<string,string>|WP_Error
	 */
	private static function waivers( array $direction ): array|WP_Error {
		$raw = $direction['waivers'] ?? [];
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				self::ERROR_CODE,
				__( 'The direction waivers must be a list.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$waivers = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$rule_id = isset( $entry['rule_id'] ) && is_string( $entry['rule_id'] ) ? $entry['rule_id'] : '';
			if ( '' === $rule_id ) {
				continue;
			}
			$waivers[ $rule_id ] = isset( $entry['reason'] ) && is_string( $entry['reason'] ) ? $entry['reason'] : '';
		}

		return $waivers;
	}

	/**
	 * One direction token group.
	 *
	 * @param array<string,mixed> $direction Direction contract.
	 * @param string              $group     Token group name.
	 * @return array<string,mixed>
	 */
	private static function direction_group( array $direction, string $group ): array {
		$tokens = $direction['tokens'] ?? [];
		if ( ! is_array( $tokens ) || ! is_array( $tokens[ $group ] ?? null ) ) {
			return [];
		}

		/** @var array<string,mixed> $values */
		$values = $tokens[ $group ];

		return $values;
	}

	/**
	 * Pixel values of a direction token group.
	 *
	 * Only pixel values participate. A `rem` or `%` token has no fixed pixel
	 * equivalent without the rendered root size, and comparing it against a
	 * measured pixel value would invent a deviation that does not exist.
	 *
	 * @param array<string,mixed> $tokens Token group.
	 * @return list<float>
	 */
	private static function pixel_scale( array $tokens ): array {
		$scale = [];
		foreach ( $tokens as $value ) {
			$pixels = self::pixels( $value );
			if ( null !== $pixels ) {
				$scale[] = $pixels;
			}
		}

		return array_values( array_unique( $scale ) );
	}

	/**
	 * Pixel type steps of the direction typography group.
	 *
	 * @param array<string,mixed> $tokens Typography token group.
	 * @return list<float>
	 */
	private static function type_scale( array $tokens ): array {
		$scale = [];
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			$pixels = self::pixels( $token['font-size'] ?? null );
			if ( null !== $pixels ) {
				$scale[] = $pixels;
			}
		}

		return array_values( array_unique( $scale ) );
	}

	/**
	 * Pixel value of a CSS length, or null when it is not expressed in pixels.
	 *
	 * @param mixed $value Raw token value.
	 */
	private static function pixels( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)px$/', trim( $value ), $matches ) ) {
			return null;
		}

		return (float) $matches[1];
	}
}
