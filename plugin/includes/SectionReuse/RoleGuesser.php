<?php
/**
 * A guess at what a section is for.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Names a section's role from its builder-native structure: the widget or block types it holds, how it
 * repeats, and where it sits on the page (a hero is the first section, or one of the first three with an h1). The rules run in a fixed order and the first that matches wins,
 * so the guess is deterministic. It is a hint for choosing candidates, not a classification to rely on.
 */
final class RoleGuesser {

	/** A section with an h1 is a hero candidate when it is among this many first sections of its page. */
	public const HERO_WINDOW = 3;

	/** @var list<string> */
	public const ROLES = [ 'hero', 'features', 'testimonials', 'pricing', 'faq', 'cta', 'gallery', 'contact', 'other' ];

	/**
	 * @param string                                                                                  $builder One of the Builder constants.
	 * @param array{columns:int,elements:int,repeated:array{count:int}|null}                          $summary A {@see LayoutSummary} result.
	 * @param array{outline:array{headings:int,images:int,buttons:int,forms:int},signals:array<string,int>} $outline A {@see SectionInspector} result.
	 * @param int                                                                                     $index   Position of the section among the top-level sections of its source.
	 */
	public static function guess( string $builder, array $summary, array $outline, int $index ): string {
		unset( $builder );
		$signals  = $outline['signals'];
		$counts   = $outline['outline'];
		$repeated = (int) ( $summary['repeated']['count'] ?? 0 );

		if ( $counts['forms'] > 0 ) {
			return 'contact';
		}
		if ( $signals['faq'] > 0 ) {
			return 'faq';
		}
		if ( $signals['testimonial'] > 0 ) {
			return 'testimonials';
		}
		if ( $signals['pricing'] > 0 || $signals['currency'] >= 2 ) {
			return 'pricing';
		}
		if ( $signals['gallery'] > 0 || ( $counts['images'] >= 4 && $counts['headings'] <= 1 ) ) {
			return 'gallery';
		}
		if ( $signals['cta'] > 0 ) {
			return 'cta';
		}
		if ( self::is_hero( $counts, $signals, $index ) ) {
			return 'hero';
		}
		if ( $counts['headings'] >= 1 && ( $repeated >= 3 || ( $repeated >= 2 && (int) $summary['columns'] >= 2 ) ) ) {
			return 'features';
		}
		if ( $counts['buttons'] >= 1 && $counts['headings'] <= 1 && 0 === $repeated && (int) $summary['elements'] <= 8 ) {
			return 'cta';
		}

		return 'other';
	}

	/**
	 * A hero has a heading and a button or an image, and either opens the page or carries an h1 within the
	 * first few sections, so an intro or a banner above it does not hide it.
	 *
	 * @param array{headings:int,images:int,buttons:int,forms:int} $counts
	 * @param array<string,int>                                    $signals
	 */
	private static function is_hero( array $counts, array $signals, int $index ): bool {
		if ( $counts['headings'] < 1 || ( $counts['buttons'] < 1 && $counts['images'] < 1 ) ) {
			return false;
		}

		return 0 === $index || ( $index < self::HERO_WINDOW && (int) ( $signals['h1'] ?? 0 ) > 0 );
	}
}
