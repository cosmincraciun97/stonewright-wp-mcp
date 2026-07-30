<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * High-confidence credential-material detector for persistent memory and skills.
 */
final class SensitiveContent {

	public static function contains( string $value ): bool {
		if ( 1 === preg_match( '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $value ) ) {
			return true;
		}
		if ( 1 === preg_match( '/\b(?:Basic|Bearer)\s+[A-Za-z0-9+\/=_-]{12,}/i', $value ) ) {
			return true;
		}
		if ( 1 === preg_match( '#https?://[^/\s:@]+:[^/\s@]+@#i', $value ) ) {
			return true;
		}
		if ( 1 === preg_match( '/\b(?:[A-Za-z0-9]{4}\s+){5}[A-Za-z0-9]{4}\b/', $value, $app_password ) ) {
			if ( 1 !== preg_match( '/^x{4}(?:\s+x{4}){5}$/i', (string) $app_password[0] ) ) {
				return true;
			}
		}

		$matched = preg_match_all(
			'/["\']?\b(?:password|user_pass|pass|app_?password|application_password|wp_app_password|api[_ -]?key|client_secret|access_token|refresh_token|authorization|token|secret|cookie)\b["\']?\s*(?::|=|\bis\b|\bwas\b)\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s,;&}]+))/i',
			$value,
			$matches,
			PREG_SET_ORDER
		);
		if ( ! is_int( $matched ) || $matched < 1 ) {
			return false;
		}

		foreach ( $matches as $match ) {
			$credential = '';
			foreach ( [ 1, 2, 3 ] as $capture ) {
				if ( isset( $match[ $capture ] ) && '' !== $match[ $capture ] ) {
					$credential = trim( (string) $match[ $capture ] );
					break;
				}
			}
			if ( strlen( $credential ) < 4 || self::is_safe_placeholder( $credential ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	private static function is_safe_placeholder( string $value ): bool {
		return 1 === preg_match(
			'/^(?:\[?redacted\]?|<[^>]+>|\$\{[A-Z_][A-Z0-9_]*\}?|\$[A-Z_][A-Z0-9_]*|x{4}(?:\s+x{4})*|your[-_ ]|placeholder\b|(?:required|enabled|disabled|never|none|null|true|false)\b)/i',
			$value
		);
	}
}
