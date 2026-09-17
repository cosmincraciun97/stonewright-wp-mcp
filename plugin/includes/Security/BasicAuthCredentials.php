<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Copy HTTP Basic credentials into PHP_AUTH_* before WordPress Application Passwords run.
 *
 * Core's wp_validate_application_password() only reads PHP_AUTH_USER / PHP_AUTH_PW.
 * Apache/php-fpm often leaves those empty while HTTP_AUTHORIZATION (or
 * REDIRECT_HTTP_AUTHORIZATION) still carries Basic credentials. Without this
 * hydrate, /mcp/stonewright Application Password clients get rest_forbidden 401.
 */
final class BasicAuthCredentials {

	public static function register(): void {
		add_filter( 'determine_current_user', [ self::class, 'hydrate_then_passthrough' ], 19 );
	}

	public static function hydrate_then_passthrough( mixed $user ): mixed {
		self::hydrate();
		return $user;
	}

	public static function hydrate(): void {
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return;
		}

		$header = trim( (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) );
		if ( '' === $header ) {
			$header = trim( (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		}
		$matches = [];
		if ( 1 !== preg_match( '/^Basic\s+(\S+)/i', $header, $matches ) ) {
			return;
		}

		$decoded = base64_decode( $matches[1], true );
		if ( ! is_string( $decoded ) || ! str_contains( $decoded, ':' ) ) {
			return;
		}

		[ $user, $password ] = explode( ':', $decoded, 2 );
		$_SERVER['PHP_AUTH_USER'] = $user;
		$_SERVER['PHP_AUTH_PW']   = $password;
	}
}
