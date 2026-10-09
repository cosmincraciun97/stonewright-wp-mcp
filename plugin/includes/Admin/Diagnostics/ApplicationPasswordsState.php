<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Diagnostics;

/**
 * Whether Application Passwords work for the current user, and when they do not, the real cause.
 *
 * WordPress turns them off for three different reasons that need three different fixes: the version has no
 * Application Passwords, the site is neither HTTPS nor a local environment, or a filter or setting turns them off
 * even though the site qualifies. A fourth, narrower case is a filter that excludes one user.
 */
final class ApplicationPasswordsState {

	public const OK              = 'ok';
	public const UNSUPPORTED     = 'unsupported_version';
	public const PLAIN_HTTP      = 'plain_http';
	public const SITE_FILTERED   = 'site_filtered';
	public const USER_UNAVAILABLE = 'user_unavailable';

	/**
	 * @return array{available: bool, cause: string, summary: string, remedy: string}
	 */
	public static function inspect(): array {
		if ( ! class_exists( '\\WP_Application_Passwords' ) ) {
			return self::result( self::UNSUPPORTED );
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return self::result( self::site_cause() );
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! (bool) wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
			return self::result( self::USER_UNAVAILABLE );
		}

		return self::result( self::OK );
	}

	/** Site-wide off: the site does not qualify (plain HTTP, not local), or a filter overrode a qualifying site. */
	private static function site_cause(): string {
		$supported = function_exists( 'wp_is_application_passwords_supported' )
			? (bool) wp_is_application_passwords_supported()
			: ( is_ssl() || 'local' === ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ) );

		return $supported ? self::SITE_FILTERED : self::PLAIN_HTTP;
	}

	/**
	 * @return array{available: bool, cause: string, summary: string, remedy: string}
	 */
	private static function result( string $cause ): array {
		switch ( $cause ) {
			case self::OK:
				return [
					'available' => true,
					'cause'     => $cause,
					'summary'   => __( 'Available for the current user.', 'stonewright' ),
					'remedy'    => '',
				];
			case self::UNSUPPORTED:
				return [
					'available' => false,
					'cause'     => $cause,
					'summary'   => __( 'Unavailable: this WordPress version does not include Application Passwords.', 'stonewright' ),
					'remedy'    => __( 'Update WordPress to version 5.6 or newer.', 'stonewright' ),
				];
			case self::PLAIN_HTTP:
				return [
					'available' => false,
					'cause'     => $cause,
					'summary'   => __( 'Unavailable: this site uses plain HTTP and is not marked as a local environment.', 'stonewright' ),
					'remedy'    => __( 'Serve the site over HTTPS. For a local setup add define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php.', 'stonewright' ),
				];
			case self::USER_UNAVAILABLE:
				return [
					'available' => false,
					'cause'     => $cause,
					'summary'   => __( 'Unavailable for the current user, although the site allows Application Passwords.', 'stonewright' ),
					'remedy'    => __( 'Check the wp_is_application_passwords_available_for_user filter (a security plugin or custom code) or sign in as an administrator.', 'stonewright' ),
				];
			default:
				return [
					'available' => false,
					'cause'     => self::SITE_FILTERED,
					'summary'   => __( 'Unavailable: a filter or setting turns Application Passwords off, although this site is HTTPS or a local environment.', 'stonewright' ),
					'remedy'    => __( 'Look for the wp_is_application_passwords_available filter (a security plugin or custom code) and allow Application Passwords for this site.', 'stonewright' ),
				];
		}
	}
}
