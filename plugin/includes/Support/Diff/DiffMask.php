<?php
/**
 * Masking, clipping and display rules shared by every diff result.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

use Stonewright\WpMcp\Security\SensitiveContent;

/**
 * Decides what a diff may show.
 *
 * A value is replaced by `[redacted]` when its key names a secret, when
 * {@see SensitiveContent} matches it, when it looks like a well-known token
 * format, when the ledger masked it before it stored it, or when the matcher
 * could not finish. Text that is too long is
 * clipped with its size, and text that is not valid UTF-8 is replaced by a size
 * marker so every result can be encoded as JSON.
 */
final class DiffMask {

	public const REDACTED = '[redacted]';

	/** Longest key or path segment kept in a result. */
	private const MAX_KEY_CHARS = 120;

	/** Nesting depth read when a value is simplified for display. */
	private const MAX_DISPLAY_DEPTH = 12;

	/** Words that mark a key as holding a secret. */
	private const SECRET_WORD = '/^(?:pass(?:word|wd|phrase|code)?s?|pwd|secrets?|tokens?|salts?|nonces?|credentials?|oauth\d*|licen[sc]es?|auth|authentication|authorization|authorisation|cookies?|bearer|apikeys?|privatekeys?|keys?)$/';

	/** What the ledger leaves in an image in place of a credential it masked before storing it. */
	private const STORED_MASK = '/\[masked (?:line \d+|private key)\]/';

	/** Formats of credentials that carry no label a line scan could find. */
	private const TOKEN_FORMATS = [
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/\bgh[pousr]_[A-Za-z0-9]{30,}/',
		'/\bgithub_pat_[A-Za-z0-9_]{30,}/',
		'/\bsk-[A-Za-z0-9_-]{20,}/',
		'/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
		'/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
	];

	/**
	 * True when a key or option name stands for a secret.
	 *
	 * The name is split into words at every character that is not a letter or
	 * digit and at every lower-to-upper case change, so `secure_auth_salt`,
	 * `googleApiKey` and `application-passwords` match while `post_author` and
	 * `passage_title` do not.
	 */
	public static function is_secret_key( string $key ): bool {
		$spaced = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key );
		$words  = preg_split( '/[^A-Za-z0-9]+/', strtolower( $spaced ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return true;
		}
		foreach ( $words as $word ) {
			if ( 1 === preg_match( self::SECRET_WORD, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when a piece of text holds credential material.
	 *
	 * A matcher failure counts as a match, so a line that cannot be scanned is
	 * never shown.
	 */
	public static function sensitive( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}
		// A value the ledger masked when it stored it is a masked value, whatever it was.
		if ( self::REDACTED === $text || 1 === preg_match( self::STORED_MASK, $text ) ) {
			return true;
		}
		if ( SensitiveContent::contains( $text ) ) {
			return true;
		}
		if ( PREG_NO_ERROR !== preg_last_error() ) {
			return true;
		}
		foreach ( self::TOKEN_FORMATS as $pattern ) {
			$hit = preg_match( $pattern, $text );
			if ( 1 === $hit || false === $hit ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lines that sit inside a PEM private key block, markers included.
	 *
	 * @param array<int, string> $lines Lines of one side, without line endings.
	 * @return array<int, true> Line index => true.
	 */
	public static function pem_lines( array $lines ): array {
		$flags  = [];
		$inside = false;
		foreach ( $lines as $index => $line ) {
			if ( ! $inside && str_contains( $line, 'PRIVATE KEY-----' ) && str_contains( $line, '-----BEGIN' ) ) {
				$inside = true;
			}
			if ( $inside ) {
				$flags[ $index ] = true;
				if ( str_contains( $line, '-----END' ) ) {
					$inside = false;
				}
			}
		}
		return $flags;
	}

	/**
	 * A value as the short string a result shows.
	 *
	 * @param mixed  $value     Any scalar, array or object.
	 * @param string $key       Last key of the value; a secret key redacts it entirely.
	 * @param int    $max_chars Longest string kept, before the size marker.
	 * @return array{0: string, 1: bool} The text and whether anything in it was redacted: the whole value, or a secret
	 *                                   key nested at any depth or inside a list.
	 */
	public static function value( mixed $value, string $key = '', int $max_chars = 500 ): array {
		if ( '' !== $key && self::is_secret_key( $key ) ) {
			return [ self::REDACTED, true ];
		}
		$nested = false;
		$text   = self::display( self::sanitize( $value, 0, $nested ) );
		if ( self::sensitive( $text ) ) {
			return [ self::REDACTED, true ];
		}
		return [ self::clip( $text, $max_chars ), $nested ];
	}

	/**
	 * A string that is safe in JSON: clipped, and replaced when not valid UTF-8.
	 */
	public static function clip( string $text, int $max_chars ): string {
		$bytes = strlen( $text );
		if ( 1 !== preg_match( '//u', $text ) ) {
			return '[binary ' . $bytes . ' bytes]';
		}
		if ( $bytes <= $max_chars ) {
			return $text;
		}
		$cut = substr( $text, 0, max( 0, $max_chars ) );
		while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return $cut . ' ... [truncated, ' . $bytes . ' bytes]';
	}

	/**
	 * A key or path segment kept to a safe length.
	 */
	public static function key( string $key ): string {
		return self::clip( $key, self::MAX_KEY_CHARS );
	}

	/**
	 * A value in the form a reader wants: typed props reduced to their content.
	 *
	 * A typed prop is an array with a `$$type` name and a `value`. A size reads as
	 * `16px`, a list of plain values as `a, b`, any other typed prop as its
	 * content, and nested typed props are reduced the same way.
	 */
	public static function display( mixed $value ): string {
		$simple = self::simplify( $value, 0 );
		if ( is_string( $simple ) ) {
			return $simple;
		}
		if ( is_bool( $simple ) ) {
			return $simple ? 'true' : 'false';
		}
		if ( null === $simple ) {
			return 'null';
		}
		if ( is_int( $simple ) || is_float( $simple ) ) {
			return (string) $simple;
		}
		if ( is_array( $simple ) ) {
			if ( [] === $simple ) {
				return '[]';
			}
			if ( array_is_list( $simple ) && self::all_plain( $simple ) ) {
				return implode( ', ', array_map( static fn( mixed $item ): string => is_bool( $item ) ? ( $item ? 'true' : 'false' ) : ( null === $item ? 'null' : (string) $item ), $simple ) );
			}
			$json = json_encode( $simple, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			return is_string( $json ) ? $json : '[unreadable]';
		}
		return '[' . gettype( $simple ) . ']';
	}

	/**
	 * Name of the `$$type` of a typed prop, or null when the value is not one.
	 */
	public static function typed_name( mixed $value ): ?string {
		if ( is_array( $value ) && isset( $value['$$type'] ) && is_string( $value['$$type'] ) && array_key_exists( 'value', $value ) ) {
			return $value['$$type'];
		}
		return null;
	}

	/**
	 * Replaces the value of every secret key inside an array by the redaction marker.
	 *
	 * @param bool $redacted Set to true when a value was replaced, at any depth.
	 */
	private static function sanitize( mixed $value, int $depth, bool &$redacted ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DISPLAY_DEPTH ) {
			return '[nested]';
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::is_secret_key( $key ) ) {
				$out[ $key ] = self::REDACTED;
				$redacted    = true;
				continue;
			}
			$out[ $key ] = self::sanitize( $item, $depth + 1, $redacted );
		}
		return $out;
	}

	private static function simplify( mixed $value, int $depth ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DISPLAY_DEPTH ) {
			return '[nested]';
		}
		if ( null !== self::typed_name( $value ) ) {
			$inner = $value['value'];
			if ( 'size' === $value['$$type'] && is_array( $inner ) && isset( $inner['size'], $inner['unit'] ) && is_scalar( $inner['size'] ) && is_scalar( $inner['unit'] ) ) {
				$size = (string) $inner['size'];
				$unit = (string) $inner['unit'];
				if ( 'auto' === $unit ) {
					return 'auto';
				}
				return 'custom' === $unit ? $size : $size . $unit;
			}
			return self::simplify( $inner, $depth + 1 );
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::simplify( $item, $depth + 1 );
		}
		return $out;
	}

	/**
	 * @param array<int|string, mixed> $items
	 */
	private static function all_plain( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) && null !== $item ) {
				return false;
			}
		}
		return true;
	}
}
