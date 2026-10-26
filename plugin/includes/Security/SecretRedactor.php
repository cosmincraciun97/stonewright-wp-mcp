<?php
/**
 * Masks secret values in a piece of PHP source.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Keeps the shape of code and hides what a person would not want kept in a log: the quoted value of a variable,
 * array key, JSON key or option name called password, secret, token, api key, authorization or private key, the
 * value of a WordPress key or salt in define(), and the credential of an `Authorization: Bearer`
 * or `Basic` header. A masked value keeps its first two and last two characters with an ellipsis between them; a
 * value too short to show four characters without giving most of it away is replaced by the ellipsis alone.
 *
 * This is a best-effort filter for source text, not a promise: a secret that sits in a variable with another name,
 * is built from pieces or is read from a file is not found. When a regular expression fails, the code is withheld
 * and never returned unmasked.
 */
final class SecretRedactor {

	/** What stands in for the code when it could not be masked. */
	public const WITHHELD = '[code withheld: secret masking failed]';

	/** Shown in place of the middle of a value. */
	private const ELLIPSIS = '…';

	/** A value shorter than this is masked whole. */
	private const MIN_PARTIAL_LENGTH = 9;

	private const NAME_WORDS = 'password|passwd|secret|token|api[_-]?key|authorization|private[_-]?key';

	/**
	 * @param string $code PHP source, or any text that holds it.
	 * @return string The same text with the values masked, or WITHHELD.
	 */
	public static function redact( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		$quoted = '(?<q>[\'"])(?<value>(?:[^\'"\\\\]++|\\\\.|(?!\k<q>)[\'"])*+)\k<q>';

		// A name that holds a secret, then = => : or, for a call that takes the name and the value as two arguments, a comma after a quoted name.
		$by_name = '/(?<head>(?:' . self::NAME_WORDS . ')[A-Za-z0-9_\-]{0,40}(?:[\'"]\s*,|[\'"]?\s*(?:=>|===|==|=|:))\s*)' . $quoted . '/is';
		// define( 'AUTH_KEY', '…' ) and the other WordPress keys and salts. DB_PASSWORD and the like are caught by name above.
		$define = '/(?<head>\bdefine\s*\(\s*[\'"](?:SECURE_AUTH|AUTH|LOGGED_IN|NONCE)_(?:KEY|SALT)[\'"]\s*,\s*)' . $quoted . '/s';
		// Authorization: Bearer <credential> and Authorization: Basic <credential> inside a string.
		$header = '/(?<head>\bAuthorization\s*:\s*(?:Bearer|Basic)\s+)(?<value>[A-Za-z0-9._~+\/=\-]+)/i';

		$out = self::replace( $by_name, $code, true );
		$out = null === $out ? null : self::replace( $define, $out, true );
		$out = null === $out ? null : self::replace( $header, $out, false );

		return $out ?? self::WITHHELD;
	}

	/**
	 * @param bool $quoted Whether the value is inside a pair of quotes that stay.
	 */
	private static function replace( string $pattern, string $subject, bool $quoted ): ?string {
		return preg_replace_callback(
			$pattern,
			static function ( array $match ) use ( $quoted ): string {
				$value = (string) $match['value'];
				if ( '' === $value ) {
					return $match[0];
				}
				$mark = self::mask( $value );
				return $quoted
					? $match['head'] . $match['q'] . $mark . $match['q']
					: $match['head'] . $mark;
			},
			$subject
		);
	}

	private static function mask( string $value ): string {
		$utf8   = mb_check_encoding( $value, 'UTF-8' );
		$length = $utf8 ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length < self::MIN_PARTIAL_LENGTH ) {
			return self::ELLIPSIS;
		}
		return $utf8
			? mb_substr( $value, 0, 2, 'UTF-8' ) . self::ELLIPSIS . mb_substr( $value, -2, null, 'UTF-8' )
			: substr( $value, 0, 2 ) . self::ELLIPSIS . substr( $value, -2 );
	}
}
