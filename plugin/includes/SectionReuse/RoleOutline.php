<?php
/**
 * Roles named in a short outline of a page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Turns "hero, 3 feature cards, testimonials and a contact form" into the intended sections it names: a role
 * per phrase, in the order the phrases appear, and a count when a number sits next to the role word. A phrase
 * that names no known role is ignored. Deterministic keyword matching, nothing more.
 */
final class RoleOutline {

	/** @var array<string, string> Role to the pattern that names it. */
	private const KEYWORDS = [
		'hero'         => '/\b(?:hero|banner|masthead|header section|above the fold)\b/i',
		'faq'          => '/\b(?:faqs?|questions?|accordion)\b/i',
		'testimonials' => '/\b(?:testimonials?|reviews?|quotes?|social proof)\b/i',
		'pricing'      => '/\b(?:pricing|prices?|plans?|tiers?)\b/i',
		'gallery'      => '/\b(?:gallery|portfolio|photos?)\b/i',
		'contact'      => '/\b(?:contact|get in touch|inquiry|enquiry|form)\b/i',
		'cta'          => '/\b(?:cta|call[- ]to[- ]action|sign[- ]?up|newsletter)\b/i',
		'features'     => '/\b(?:features?|benefits?|services?|why (?:us|choose))\b/i',
	];

	/** Most roles one outline yields. */
	public const MAX_ROLES = 12;

	/**
	 * @return list<array{role:string,layout?:array{columns:int,items:int}}>
	 */
	public static function parse( string $text ): array {
		$out  = [];
		$seen = [];
		foreach ( preg_split( '/[,;.\n+]|\band\b|\bthen\b|->/i', $text ) ?: [] as $segment ) {
			$segment = trim( (string) $segment );
			if ( '' === $segment ) {
				continue;
			}
			foreach ( self::KEYWORDS as $role => $pattern ) {
				if ( 1 !== preg_match( $pattern, $segment ) ) {
					continue;
				}
				if ( isset( $seen[ $role ] ) ) {
					break;
				}
				$seen[ $role ] = true;
				$entry         = [ 'role' => $role ];
				if ( 1 === preg_match( '/\b(\d{1,2})\b/', $segment, $number ) && (int) $number[1] > 0 ) {
					$count          = min( 12, (int) $number[1] );
					$entry['layout'] = [ 'columns' => $count, 'items' => $count ];
				}
				$out[] = $entry;
				break;
			}
			if ( count( $out ) >= self::MAX_ROLES ) {
				break;
			}
		}

		return $out;
	}
}
