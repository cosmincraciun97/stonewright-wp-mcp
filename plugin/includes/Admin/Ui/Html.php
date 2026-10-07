<?php
/**
 * Escaping and attribute rendering shared by the admin UI helpers.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * The one place the helpers turn data into markup.
 *
 * Text is escaped here, attribute names come from an allowlist, and nothing that runs script or carries
 * presentation (on* handlers, inline style) can be passed through. A helper that needs an attribute this class
 * does not know does not get it: add it to ALLOWED with a reason.
 */
final class Html {

	/** Attribute names a helper may set, besides data-* and aria-*. */
	private const ALLOWED = [
		'action',
		'autocomplete',
		'autofocus',
		'checked',
		'class',
		'colspan',
		'datetime',
		'dir',
		'disabled',
		'for',
		'form',
		'hidden',
		'href',
		'id',
		'lang',
		'maxlength',
		'method',
		'multiple',
		'name',
		'open',
		'placeholder',
		'popover',
		'popovertarget',
		'readonly',
		'rel',
		'required',
		'role',
		'rowspan',
		'scope',
		'tabindex',
		'target',
		'title',
		'type',
		'value',
	];

	/** Attributes whose value is a URL. */
	private const URL_ATTRIBUTES = [ 'action', 'href' ];

	private static int $counter = 0;

	/**
	 * A unique id for relating elements within one request (labels, descriptions, targets).
	 */
	public static function unique_id( string $prefix ): string {
		++self::$counter;

		return 'sw-ui-' . ( '' === $prefix ? 'x' : sanitize_html_class( $prefix ) ) . '-' . self::$counter;
	}

	/** Restart the id counter. For tests, so rendered ids are predictable. */
	public static function reset_ids(): void {
		self::$counter = 0;
	}

	/**
	 * Class names as one escaped, de-duplicated string. Accepts strings (space separated) and lists.
	 *
	 * @param string|list<string>|null ...$classes
	 */
	public static function classes( string|array|null ...$classes ): string {
		$names = [];
		foreach ( $classes as $group ) {
			foreach ( is_array( $group ) ? $group : [ (string) $group ] as $chunk ) {
				foreach ( preg_split( '/\s+/', trim( (string) $chunk ) ) ?: [] as $name ) {
					$clean = sanitize_html_class( $name );
					if ( '' !== $clean ) {
						$names[ $clean ] = true;
					}
				}
			}
		}

		return implode( ' ', array_keys( $names ) );
	}

	/**
	 * Attributes as a string with a leading space, or an empty string.
	 *
	 * A true value renders a bare attribute, false and null drop it, anything else is escaped. Names outside the
	 * allowlist, on* handlers and style are dropped rather than rendered.
	 *
	 * @param array<string, scalar|list<string>|null> $attributes
	 */
	public static function attrs( array $attributes ): string {
		$out = '';
		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! self::allowed( $name ) || null === $value || false === $value ) {
				continue;
			}
			if ( true === $value ) {
				$out .= ' ' . $name;
				continue;
			}
			if ( is_array( $value ) ) {
				$value = 'class' === $name ? self::classes( $value ) : implode( ' ', array_map( 'strval', $value ) );
			}
			$value = (string) $value;
			if ( 'class' === $name && '' === $value ) {
				continue;
			}
			if ( in_array( $name, self::URL_ATTRIBUTES, true ) ) {
				$value = esc_url( $value );
				if ( '' === $value ) {
					continue;
				}
				$out .= ' ' . $name . '="' . $value . '"';
				continue;
			}
			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $out;
	}

	/**
	 * One element. $inner_html is markup the caller has already escaped or built with these helpers.
	 *
	 * @param array<string, scalar|list<string>|null> $attributes
	 */
	public static function element( string $tag, array $attributes = [], string $inner_html = '' ): string {
		return '<' . $tag . self::attrs( $attributes ) . '>' . $inner_html . '</' . $tag . '>';
	}

	/**
	 * A void element (input, br): no closing tag.
	 *
	 * @param array<string, scalar|list<string>|null> $attributes
	 */
	public static function void( string $tag, array $attributes = [] ): string {
		return '<' . $tag . self::attrs( $attributes ) . '>';
	}

	/** Escape text for an element body. */
	public static function text( string $text ): string {
		return esc_html( $text );
	}

	/**
	 * Extra attributes a caller passes in, with the ones a helper owns removed so a caller cannot unmake them.
	 *
	 * @param array<string, scalar|list<string>|null> $extra
	 * @param list<string>                            $owned
	 * @return array<string, scalar|list<string>|null>
	 */
	public static function without( array $extra, array $owned ): array {
		foreach ( $owned as $name ) {
			unset( $extra[ $name ] );
		}

		return $extra;
	}

	private static function allowed( string $name ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_:.-]*$/', $name ) ) {
			return false;
		}
		if ( str_starts_with( $name, 'on' ) || 'style' === $name ) {
			return false;
		}

		return str_starts_with( $name, 'data-' ) || str_starts_with( $name, 'aria-' ) || in_array( $name, self::ALLOWED, true );
	}
}
