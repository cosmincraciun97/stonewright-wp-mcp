<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

/**
 * Converts the three human-facing direction dials into compact Elementor rules.
 *
 * The result is guidance, never raw Elementor data. Callers still compile
 * settings against the live widget/container schema before writing.
 */
final class DialTranslator {

	/**
	 * @param array<string,mixed> $contract Validated direction contract.
	 * @return array<string,mixed>
	 */
	public static function translate( array $contract ): array {
		$dials   = is_array( $contract['dials'] ?? null ) ? $contract['dials'] : [];
		$tokens  = is_array( $contract['tokens'] ?? null ) ? $contract['tokens'] : [];
		$spacing = is_array( $tokens['spacing'] ?? null ) ? $tokens['spacing'] : [];
		$motion  = self::motion( (int) ( $dials['motion'] ?? 0 ) );
		$density = self::density( (int) ( $dials['density'] ?? 0 ) );

		if ( [] !== $spacing ) {
			$density['declared_spacing_tokens'] = $spacing;
			$density['token_precedence']         = 'Declared spacing tokens override dial defaults.';
		}

		return [
			'variance' => self::variance( (int) ( $dials['variance'] ?? 0 ) ),
			'density'  => $density,
			'motion'   => $motion,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function motion( int $value ): array {
		if ( $value < 20 ) {
			return [
				'value'              => $value,
				'entrance_animation' => 'blocked',
				'motion_fx'          => 'blocked',
			];
		}

		if ( $value < 60 ) {
			return [
				'value'              => $value,
				'entrance_animation' => 'hero_only',
				'motion_fx'          => 'blocked',
			];
		}

		return [
			'value'                => $value,
			'entrance_animation'   => 'allowed',
			'motion_fx'            => 'allowed',
			'reduced_motion_rule'  => 'Provide an equivalent static state and respect prefers-reduced-motion.',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function density( int $value ): array {
		if ( $value < 34 ) {
			$padding = [ 'desktop' => 96, 'tablet' => 64, 'mobile' => 48 ];
			$gap     = 32;
		} elseif ( $value < 67 ) {
			$padding = [ 'desktop' => 72, 'tablet' => 48, 'mobile' => 32 ];
			$gap     = 24;
		} else {
			$padding = [ 'desktop' => 48, 'tablet' => 32, 'mobile' => 24 ];
			$gap     = 16;
		}

		return [
			'value'                 => $value,
			'section_padding_px'    => $padding,
			'default_container_gap' => $gap,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function variance( int $value ): array {
		$layout = $value < 34 ? 'symmetric' : ( $value < 67 ? 'balanced' : 'asymmetric_preferred' );

		return [
			'value'         => $value,
			'layout_rhythm' => $layout,
		];
	}
}
