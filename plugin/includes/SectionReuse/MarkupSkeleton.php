<?php
/**
 * The tag structure of a block's saved markup, without its text.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * A static Gutenberg block saves markup that its editor script writes. Changing the words inside that markup,
 * or swapping an image for another, keeps it valid; changing its tags or their attributes may not. The skeleton
 * of a markup string is its sequence of tags with their attribute names and, for the attributes that define
 * structure, their values. Two strings with the same skeleton differ only in text and in the values that are
 * meant to change: a source, alternative text, link, size, title, an id, or the id number of an image class.
 */
final class MarkupSkeleton {

	/** Attributes whose value may change without changing the structure. */
	private const FREE_VALUE = [ 'src', 'srcset', 'sizes', 'alt', 'href', 'title', 'width', 'height', 'id', 'loading', 'decoding', 'poster', 'aria-label', 'data-id', 'data-link', 'data-full-url', 'data-type', 'datetime', 'cite', 'target', 'rel' ];

	/** Whether two markup strings have the same skeleton. */
	public static function same( string $before, string $after ): bool {
		return self::of( $before ) === self::of( $after );
	}

	/**
	 * @return list<array{0:bool,1:string,2:array<string,string>}> Closing flag, tag name and attributes by name.
	 */
	public static function of( string $markup ): array {
		$tags = [];
		if ( false === preg_match_all( '/<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/', $markup, $found, PREG_SET_ORDER ) ) {
			return [ [ false, '#unscannable', [] ] ];
		}
		foreach ( $found as $tag ) {
			$attributes = [];
			if ( '' === $tag[1] && preg_match_all( '/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>=`]+)))?/', $tag[3], $pairs, PREG_SET_ORDER ) ) {
				foreach ( $pairs as $pair ) {
					$name  = strtolower( $pair[1] );
					$value = '' !== ( $pair[2] ?? '' ) ? $pair[2] : ( '' !== ( $pair[3] ?? '' ) ? $pair[3] : ( $pair[4] ?? '' ) );
					$attributes[ $name ] = self::attribute_value( $name, $value );
				}
				ksort( $attributes );
			}
			$tags[] = [ '/' === $tag[1], strtolower( $tag[2] ), $attributes ];
		}

		return $tags;
	}

	private static function attribute_value( string $name, string $value ): string {
		if ( in_array( $name, self::FREE_VALUE, true ) ) {
			return '';
		}
		if ( 'class' === $name ) {
			$tokens = preg_split( '/\s+/', trim( $value ) ) ?: [];
			$tokens = array_map( static fn( string $token ): string => 1 === preg_match( '/^wp-image-\d+$/', $token ) ? 'wp-image-N' : $token, $tokens );
			sort( $tokens );

			return implode( ' ', $tokens );
		}

		return $value;
	}
}
