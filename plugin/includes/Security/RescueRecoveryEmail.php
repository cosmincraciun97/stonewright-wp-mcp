<?php
/**
 * The Stonewright rescue link in the WordPress recovery mode email.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Core\RescueRuntime;

/**
 * Adds a Stonewright safe mode link below the core link in the recovery mode email.
 *
 * The link is added only when the fatal error that caused the email belongs to a Stonewright
 * change in the journal (the same check the MU-plugin's shutdown handler makes, which also
 * records the incident), when the email goes to exactly one address, and when that address is an
 * administrator. The key in the link is bound to that administrator. Without a helper that can
 * open the link, or on any failure, the email is left as WordPress wrote it.
 */
final class RescueRecoveryEmail {

	/**
	 * Finds the user of an email address. Replaced in tests.
	 *
	 * @var (callable(string):?object)|null
	 */
	public static $user_lookup = null;

	public static function register(): void {
		add_filter( 'recovery_mode_email', [ self::class, 'filter' ], 20, 2 );
	}

	/**
	 * Filter for recovery_mode_email.
	 *
	 * @param mixed                     $email The email WordPress built: an array with to, subject, message.
	 * @param mixed                     $url   The core recovery link (unused).
	 * @param array<string, mixed>|null $error The fatal error; the last PHP error when omitted.
	 * @return mixed
	 */
	public static function filter( mixed $email, mixed $url = '', ?array $error = null ): mixed {
		unset( $url );
		try {
			return self::with_link( $email, $error );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return $email;
		}
	}

	/**
	 * @param mixed                     $email
	 * @param array<string, mixed>|null $error
	 * @return mixed
	 */
	private static function with_link( mixed $email, ?array $error ): mixed {
		if ( ! is_array( $email ) || ! isset( $email['to'], $email['message'] ) || ! is_string( $email['to'] ) || ! is_string( $email['message'] ) ) {
			return $email;
		}
		if ( ! RescueInstaller::is_ready() || ! RescueRuntime::available( 'record_fatal' ) ) {
			return $email;
		}
		$error ??= error_get_last();
		if ( ! is_array( $error ) || null === RescueRuntime::call( 'record_fatal', $error ) ) {
			return $email;
		}
		$address = self::single_address( $email['to'] );
		$user    = '' === $address ? null : self::user_for( $address );
		if ( null === $user || ! user_can( $user, 'manage_options' ) ) {
			return $email;
		}
		$link = RescueKeys::safe_boot_url( (int) ( $user->ID ?? 0 ) );
		if ( null === $link ) {
			return $email;
		}
		$email['message'] = rtrim( $email['message'] ) . "\n\n" . sprintf(
			/* translators: %s: safe mode link. */
			__( "Stonewright safe mode: if the problem started with a change made through Stonewright, this link starts safe mode in your browser. After you sign in with the administrator account that owns this email address, the site loads with only Stonewright active and the default theme, so you can reach Stonewright > Rescue and roll the change back.\n\n%s\n\nThe link works once and expires in 15 minutes. If the sign-in page does not load, use the WordPress recovery mode link above.", 'stonewright' ),
			$link
		) . "\n";
		return $email;
	}

	/** The one address of a recipient list, or an empty string when there is none or more than one. */
	private static function single_address( string $to ): string {
		$to = trim( $to );
		if ( 1 === preg_match( '/<([^<>]+)>\s*$/', $to, $match ) ) {
			$to = trim( $match[1] );
		}
		return '' === $to || false !== strpbrk( $to, ",; \t\r\n" ) ? '' : $to;
	}

	private static function user_for( string $address ): ?object {
		if ( null !== self::$user_lookup ) {
			return ( self::$user_lookup )( $address );
		}
		$user = get_user_by( 'email', $address );
		return is_object( $user ) ? $user : null;
	}
}
