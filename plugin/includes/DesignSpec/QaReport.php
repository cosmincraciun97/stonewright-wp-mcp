<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

/**
 * Pure DesignSpec quality report (no WordPress runtime required).
 */
final class QaReport {

	/**
	 * @param array<string, mixed> $spec
	 * @return array{score: int, issues: list<array{code: string, severity: string, message: string}>}
	 */
	public static function for_spec( array $spec ): array {
		$issues = [];
		$texts  = [];
		$images = 0;
		$h1     = 0;
		$levels = [];
		$walk   = function ( array $blocks, string $path ) use ( &$walk, &$issues, &$texts, &$images, &$h1, &$levels ): void {
			foreach ( $blocks as $i => $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$p    = $path . '/' . $i;
				$type = (string) ( $b['type'] ?? '' );
				if ( 'heading' === $type ) {
					$level    = (int) ( $b['level'] ?? 2 );
					$levels[] = $level;
					if ( 1 === $level ) {
						++$h1;
					}
				}
				if ( 'image' === $type ) {
					++$images;
					$alt = trim( (string) ( $b['alt'] ?? '' ) );
					if ( '' === $alt ) {
						$issues[] = [
							'code'     => 'image_alt_missing',
							'severity' => 'major',
							'message'  => 'Image missing alt text at ' . $p,
						];
					}
				}
				if ( isset( $b['text'] ) && is_string( $b['text'] ) && strlen( $b['text'] ) > 0 ) {
					$texts[] = $b['text'];
				}
				if ( ! empty( $b['blocks'] ) && is_array( $b['blocks'] ) ) {
					$walk( $b['blocks'], $p );
				}
			}
		};

		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$hero_btns = 0;
		foreach ( $sections as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$blocks = (array) ( $section['blocks'] ?? [] );
			if ( count( $blocks ) < 2 ) {
				$issues[] = [
					'code'     => 'section_too_thin',
					'severity' => 'warning',
					'message'  => 'Section ' . (string) ( $section['id'] ?? $si ) . ' has fewer than 2 blocks',
				];
			}
			// Contrast: if section has background + text color tokens, check AA.
			$bg = self::section_bg( $section );
			$fg = self::section_fg( $section, $spec );
			if ( '' !== $bg && '' !== $fg && self::contrast_ratio( $fg, $bg ) < 4.5 ) {
				$issues[] = [
					'code'     => 'contrast_below_aa',
					'severity' => 'major',
					'message'  => 'Section ' . (string) ( $section['id'] ?? $si ) . ' text/background contrast below 4.5:1',
				];
			}
			if ( 0 === $si || 'hero' === (string) ( $section['id'] ?? '' ) ) {
				$hero_btns += self::count_type( $blocks, 'button' );
			}
			$walk( $blocks, 'sections/' . $si );
		}

		if ( $h1 > 1 ) {
			$issues[] = [
				'code'     => 'heading_hierarchy',
				'severity' => 'major',
				'message'  => 'Multiple h1 headings found (' . $h1 . ')',
			];
		}
		if ( $h1 === 0 && [] !== $levels ) {
			$issues[] = [
				'code'     => 'heading_hierarchy',
				'severity' => 'warning',
				'message'  => 'No h1 heading found',
			];
		}
		// Detect h1 → h3 skip (no h2 ever).
		if ( in_array( 1, $levels, true ) && in_array( 3, $levels, true ) && ! in_array( 2, $levels, true ) ) {
			$issues[] = [
				'code'     => 'heading_hierarchy',
				'severity' => 'warning',
				'message'  => 'Heading levels skip from h1 to h3',
			];
		}
		if ( $hero_btns > 1 ) {
			$issues[] = [
				'code'     => 'multiple_primary_ctas',
				'severity' => 'warning',
				'message'  => 'Hero has more than one button CTA',
			];
		}
		if ( 0 === $images ) {
			$issues[] = [
				'code'     => 'no_media',
				'severity' => 'major',
				'message'  => 'Spec contains zero images',
			];
		}

		$score = 100;
		foreach ( $issues as $issue ) {
			$score -= ( 'major' === $issue['severity'] ) ? 10 : 5;
		}
		$score = max( 0, min( 100, $score ) );

		return [
			'score'  => $score,
			'issues' => array_values( $issues ),
		];
	}

	/**
	 * @param array<string, mixed> $section
	 */
	private static function section_bg( array $section ): string {
		if ( isset( $section['background']['color'] ) && is_string( $section['background']['color'] ) ) {
			return $section['background']['color'];
		}
		if ( isset( $section['style']['background']['color'] ) && is_string( $section['style']['background']['color'] ) ) {
			return $section['style']['background']['color'];
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $section
	 * @param array<string, mixed> $spec
	 */
	private static function section_fg( array $section, array $spec ): string {
		if ( isset( $section['style']['color'] ) && is_string( $section['style']['color'] ) ) {
			return $section['style']['color'];
		}
		$tokens = isset( $spec['tokens']['colors'] ) && is_array( $spec['tokens']['colors'] ) ? $spec['tokens']['colors'] : [];
		return is_string( $tokens['text'] ?? null ) ? (string) $tokens['text'] : '';
	}

	/**
	 * @param array<int, mixed> $blocks
	 */
	private static function count_type( array $blocks, string $type ): int {
		$n = 0;
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( (string) ( $b['type'] ?? '' ) === $type ) {
				++$n;
			}
			if ( ! empty( $b['blocks'] ) && is_array( $b['blocks'] ) ) {
				$n += self::count_type( $b['blocks'], $type );
			}
		}
		return $n;
	}

	private static function contrast_ratio( string $fg, string $bg ): float {
		$l1 = self::rel_luminance( $fg );
		$l2 = self::rel_luminance( $bg );
		if ( $l1 < 0 || $l2 < 0 ) {
			return 21.0;
		}
		$hi = max( $l1, $l2 );
		$lo = min( $l1, $l2 );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	private static function rel_luminance( string $hex ): float {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return -1.0;
		}
		$rgb = [
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255,
		];
		$lin = array_map(
			static function ( float $c ): float {
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);
		return ( 0.2126 * $lin[0] ) + ( 0.7152 * $lin[1] ) + ( 0.0722 * $lin[2] );
	}
}
