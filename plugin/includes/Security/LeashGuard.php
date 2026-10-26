<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\SiteMode;
use Stonewright\WpMcp\Security\Adapters\SiteAdapter;

/**
 * The settings that hold an agent on its leash are loosened only by a person in wp-admin: the site mode
 * (production-safe is the strictest, development the loosest), the list of abilities an administrator
 * switched off, and the feature flags. An agent signs in with an Application Password or an OAuth bearer,
 * or calls an ability; whatever it holds, it does not widen its own limits. Making a limit tighter, and
 * switching an ability off, stay open to anyone with manage_options.
 *
 * Who is a person in wp-admin is decided by FullSurfaceGuard::human_in_wp_admin(), the same question the
 * Full tool surface asks: a cookie sign-in with a valid REST nonce on a REST request, or an admin screen.
 *
 * Two layers use this class: the REST routes that write the settings check before they write anything and
 * refuse with an approval link, and a pre_update_option filter on the options themselves keeps the old
 * value when any other REST request tries to loosen them (an ability that writes options, a restore).
 * WP-CLI, cron, activation and admin screens never go through the second layer.
 *
 * @stonewright-status stable
 */
final class LeashGuard {

	public const MODE_ERROR_CODE      = 'stonewright_mode_requires_admin';
	public const ABILITIES_ERROR_CODE = 'stonewright_abilities_require_admin';
	public const FLAGS_ERROR_CODE     = 'stonewright_feature_flags_require_admin';

	public const MAX_FLAGS        = 32;
	public const MAX_FLAG_KEY_LEN = 64;

	/** Strictness of each mode: the higher, the stricter. */
	private const RANK = [
		SiteMode::DEVELOPMENT     => 0,
		SiteMode::STAGING         => 1,
		SiteMode::PRODUCTION_SAFE => 2,
	];

	/**
	 * Whether going from one mode to another makes the site less strict. A mode that is not one of the three
	 * counts as production-safe, like SiteMode does.
	 */
	public static function loosens_mode( string $from, string $to ): bool {
		return self::rank( $to ) < self::rank( $from );
	}

	private static function rank( string $mode ): int {
		return self::RANK[ $mode ] ?? self::RANK[ SiteMode::PRODUCTION_SAFE ];
	}

	/**
	 * Whether the request may write $to as the mode: it is not a loosening, or a person in wp-admin sends it.
	 */
	public static function mode_change_allowed( \WP_REST_Request $request, string $to ): bool {
		return ! self::loosens_mode( SiteMode::current(), $to ) || FullSurfaceGuard::human_in_wp_admin( $request );
	}

	/**
	 * The address where an administrator chooses the mode.
	 */
	public static function mode_url(): string {
		return admin_url( 'admin.php?page=stonewright&tab=settings' ) . '#stonewright_mode';
	}

	/**
	 * The address of the page where an administrator switches abilities on and off.
	 */
	public static function abilities_url(): string {
		return admin_url( 'admin.php?page=stonewright-abilities' );
	}

	/**
	 * The address of the settings tab.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=stonewright&tab=settings' );
	}

	/**
	 * The refusal of a change to a leash setting that a person in wp-admin has to make.
	 */
	public static function refusal( string $code, string $message, string $url ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status'            => 403,
				'retryable'         => false,
				'execution_status'  => 'blocked',
				'approval_required' => true,
				'agent_must_stop'   => true,
				'approval_url'      => $url,
			]
		);
	}

	public static function mode_refusal(): \WP_Error {
		return self::refusal(
			self::MODE_ERROR_CODE,
			__( 'Making the site mode less strict can only be done by an administrator in wp-admin, under Stonewright > Setup > Settings > Mode. Show the user the settings link and stop. Do not retry, and do not look for another way to change the mode.', 'stonewright' ),
			self::mode_url()
		);
	}

	public static function abilities_refusal(): \WP_Error {
		return self::refusal(
			self::ABILITIES_ERROR_CODE,
			__( 'Switching an ability on again can only be done by an administrator in wp-admin, on the Stonewright AI Abilities page. Show the user the link and stop. Do not retry, and do not look for another way to switch it on.', 'stonewright' ),
			self::abilities_url()
		);
	}

	public static function flags_refusal(): \WP_Error {
		return self::refusal(
			self::FLAGS_ERROR_CODE,
			__( 'Feature flags can only be changed by an administrator in wp-admin. Show the user the settings link and stop. Do not retry, and do not look for another way to change them.', 'stonewright' ),
			self::settings_url()
		);
	}

	/**
	 * The feature flags as they are stored: a flat map of sanitize_key() name to boolean, at most
	 * MAX_FLAGS entries, no name longer than MAX_FLAG_KEY_LEN. An entry that is not a scalar, or whose name is
	 * empty after sanitizing or too long, is dropped.
	 *
	 * @return array<string,bool>
	 */
	public static function sanitize_feature_flags( mixed $flags ): array {
		if ( ! is_array( $flags ) ) {
			return [];
		}
		$clean = [];
		foreach ( $flags as $key => $value ) {
			if ( count( $clean ) >= self::MAX_FLAGS ) {
				break;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$name = sanitize_key( (string) $key );
			if ( '' === $name || strlen( $name ) > self::MAX_FLAG_KEY_LEN ) {
				continue;
			}
			$clean[ $name ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
		return $clean;
	}

	/**
	 * The pre_update_option_stonewright_mode filter: during a REST request that is not a person in wp-admin, a value
	 * that makes the mode less strict is not stored; the old value stays.
	 *
	 * @param mixed  $value     The value about to be stored.
	 * @param mixed  $old_value The stored value (false when there is none).
	 * @return mixed
	 */
	public static function guard_mode_write( mixed $value, mixed $old_value ) {
		if ( ! self::serving_rest_request() ) {
			return $value;
		}
		$from = SiteMode::resolve( false === $old_value ? null : $old_value );
		$to   = SiteMode::resolve( $value );
		if ( ! self::loosens_mode( $from, $to ) || self::rest_request_is_person() ) {
			return $value;
		}
		SiteAdapter::note_admin_write( 'rest', SiteMode::OPTION, 'Refused: a request that is not a person in wp-admin tried to change the mode from ' . $from . ' to ' . $to );
		return $old_value;
	}

	/**
	 * The pre_update_option_stonewright_disabled_abilities filter: during a REST request that is not a person in
	 * wp-admin, a list that no longer holds every ability the stored list switches off is not stored.
	 *
	 * @param mixed $value     The list about to be stored.
	 * @param mixed $old_value The stored list.
	 * @return mixed
	 */
	public static function guard_disabled_abilities_write( mixed $value, mixed $old_value ) {
		if ( ! self::serving_rest_request() ) {
			return $value;
		}
		$old     = is_array( $old_value ) ? array_map( 'strval', array_filter( $old_value, 'is_scalar' ) ) : [];
		$new     = is_array( $value ) ? array_map( 'strval', array_filter( $value, 'is_scalar' ) ) : [];
		$removed = array_diff( $old, $new );
		if ( [] === $removed || self::rest_request_is_person() ) {
			return $value;
		}
		SiteAdapter::note_admin_write( 'rest', 'stonewright_disabled_abilities', 'Refused: a request that is not a person in wp-admin tried to switch on ' . count( $removed ) . ' ability(ies): ' . implode( ', ', array_slice( array_values( $removed ), 0, 5 ) ) );
		return $old_value;
	}

	/**
	 * The pre_update_option_stonewright_php_execute_keep_code filter: during a REST request that is not a person in
	 * wp-admin, turning the copy of executed code off is not stored; the old value stays. The copy is on while the
	 * option is missing, so a stored false is the only way it is off.
	 *
	 * @param mixed $value     The value about to be stored.
	 * @param mixed $old_value The stored value (false when there is none).
	 * @return mixed
	 */
	public static function guard_keep_code_write( mixed $value, mixed $old_value ) {
		if ( ! self::serving_rest_request() ) {
			return $value;
		}
		$was_on = false === $old_value || (bool) $old_value;
		if ( ! $was_on || (bool) $value || self::rest_request_is_person() ) {
			return $value;
		}
		SiteAdapter::note_admin_write( 'rest', RuntimeCodeHistory::OPTION, 'Refused: a request that is not a person in wp-admin tried to stop keeping the code php-execute runs' );
		return $old_value;
	}

	/**
	 * The add_option_stonewright_php_execute_keep_code action: the filter above does not run when the option is
	 * created, so a request that is not a person in wp-admin that creates it as off (it is not stored until a person
	 * saves the settings) has it deleted again, which leaves the copy on.
	 *
	 * @param string $option The option name.
	 * @param mixed  $value  The value that was stored.
	 */
	public static function guard_keep_code_added( string $option, mixed $value ): void {
		if ( ! self::serving_rest_request() || (bool) $value || self::rest_request_is_person() ) {
			return;
		}
		delete_option( $option );
		SiteAdapter::note_admin_write( 'rest', RuntimeCodeHistory::OPTION, 'Refused: a request that is not a person in wp-admin tried to stop keeping the code php-execute runs' );
	}

	private static function serving_rest_request(): bool {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return wp_is_serving_rest_request();
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Whether the REST request being served is a person in wp-admin. There is no request object inside an option
	 * filter, so one is built from what WordPress itself reads: the nonce header or the _wpnonce parameter.
	 */
	private static function rest_request_is_person(): bool {
		$request = new \WP_REST_Request( 'POST' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- the nonce is verified by FullSurfaceGuard::human_in_wp_admin().
		$header = isset( $_SERVER['HTTP_X_WP_NONCE'] ) && is_string( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
		if ( '' !== $header ) {
			$request->set_header( 'X-WP-Nonce', $header );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- the nonce is verified by FullSurfaceGuard::human_in_wp_admin().
		$param = isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : '';
		if ( '' !== $param ) {
			$request->set_param( '_wpnonce', $param );
		}
		return FullSurfaceGuard::human_in_wp_admin( $request );
	}
}
