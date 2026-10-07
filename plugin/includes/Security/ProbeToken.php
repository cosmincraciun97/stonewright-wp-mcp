<?php
/**
 * Internal one-use credential for the health probe.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Lets the health probe load wp-admin (and a draft preview) as the user who made a change.
 *
 * The probe never sends the user's cookie or an Application Password. It sends a random token
 * in a request header instead. The token works for one request: it is bound to the request
 * path and to a per-probe nonce in the query string, only GET is honoured, it lives for
 * minutes, and only a keyed hash of it is stored. The first request that presents it is logged
 * in as the user for that request alone: a session that expires after two minutes is created,
 * its cookies exist only in the request's own $_COOKIE and never leave the process, and the
 * session is destroyed when the request ends.
 */
final class ProbeToken {

	public const HEADER = 'X-Stonewright-Probe';
	public const PARAM  = 'sw_probe';

	/** Seconds a token can be used after it is issued. */
	public const TTL = 180;

	/** Seconds the request-local session lasts, in case the request dies before cleanup. */
	private const SESSION_TTL = 120;

	private const KEY_PREFIX = 'stonewright_probe_';

	/** @var callable(int):void|null */
	private static $login_handler = null;

	/** @var list<array{manager:\WP_Session_Tokens,session:string}> */
	private static array $open_sessions = [];

	private static bool $shutdown_registered = false;

	private static bool $probe_request = false;

	/**
	 * Issue a token for one request.
	 *
	 * @return string|null The token, or null when it cannot be stored.
	 */
	public static function issue( int $user_id, string $path, string $nonce ): ?string {
		if ( $user_id < 1 || '' === $path || 1 !== preg_match( '/^[A-Za-z0-9]{8,32}$/D', $nonce ) ) {
			return null;
		}
		$token = bin2hex( random_bytes( 24 ) );
		$stored = set_transient(
			self::key( $token ),
			[
				'user'    => $user_id,
				'path'    => self::normalize_path( $path ),
				'nonce'   => $nonce,
				'expires' => time() + self::TTL,
			],
			self::TTL
		);
		return $stored ? $token : null;
	}

	/**
	 * Check a token against the request that carries it and use it up.
	 *
	 * @return int The user the token was issued for, or 0.
	 */
	public static function consume( string $token, string $path, string $nonce ): int {
		if ( 1 !== preg_match( '/^[a-f0-9]{48}$/D', $token ) ) {
			return 0;
		}
		$key     = self::key( $token );
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) ) {
			// Anyone can send the header. A token that does not exist (never issued, used up, expired and
			// cleaned away) writes no audit row and touches no coalescing transient, so a stream of
			// guesses costs the site one read each. Only a token that exists is accepted or refused on the record.
			return 0;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method
			|| (int) ( $payload['expires'] ?? 0 ) < time()
			|| ! hash_equals( (string) ( $payload['path'] ?? '' ), self::normalize_path( $path ) )
			|| ! hash_equals( (string) ( $payload['nonce'] ?? '' ), $nonce )
		) {
			self::audit( false );
			return 0;
		}
		// Single use: only the request that deletes the stored token proceeds.
		if ( true !== delete_transient( $key ) ) {
			self::audit( false );
			return 0;
		}
		self::audit( true );
		return max( 0, (int) ( $payload['user'] ?? 0 ) );
	}

	/**
	 * Hooked early on every request. A request without the header returns at once.
	 */
	public static function authenticate_request(): void {
		$token = isset( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'] ) && is_string( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'] ) ? trim( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'] ) : '';
		if ( '' === $token ) {
			return;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$nonce = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? $_GET[ self::PARAM ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A one-use probe nonce, checked against the stored token.
		$user  = self::consume( $token, $path, $nonce );
		if ( $user < 1 ) {
			return;
		}
		self::$probe_request = true;
		$handler = self::$login_handler ?? [ self::class, 'login_for_this_request' ];
		$handler( $user );
	}

	/**
	 * Whether this request was let in by a probe token: the site is checking itself, and no person
	 * is looking at the page.
	 */
	public static function is_probe_request(): bool {
		return self::$probe_request;
	}

	/**
	 * Replace the way a request is logged in. For tests.
	 *
	 * @param callable(int):void|null $handler
	 */
	public static function set_login_handler( ?callable $handler ): void {
		self::$login_handler = $handler;
	}

	/**
	 * Log the request in through a short session whose cookies exist only in this process.
	 */
	public static function login_for_this_request( int $user_id ): void {
		if ( ! class_exists( 'WP_Session_Tokens' ) || ! function_exists( 'wp_generate_auth_cookie' ) || ! defined( 'AUTH_COOKIE' ) ) {
			return;
		}
		$expiration = time() + self::SESSION_TTL;
		$manager    = \WP_Session_Tokens::get_instance( $user_id );
		$session    = $manager->create( $expiration );
		$cookies    = [
			'auth'        => defined( 'AUTH_COOKIE' ) ? (string) constant( 'AUTH_COOKIE' ) : '',
			'secure_auth' => defined( 'SECURE_AUTH_COOKIE' ) ? (string) constant( 'SECURE_AUTH_COOKIE' ) : '',
			'logged_in'   => defined( 'LOGGED_IN_COOKIE' ) ? (string) constant( 'LOGGED_IN_COOKIE' ) : '',
		];
		foreach ( $cookies as $scheme => $name ) {
			if ( '' !== $name ) {
				$_COOKIE[ $name ] = wp_generate_auth_cookie( $user_id, $expiration, $scheme, $session );
			}
		}
		self::$open_sessions[] = [ 'manager' => $manager, 'session' => $session ];
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ self::class, 'end_sessions' ] );
		}
	}

	/**
	 * Destroy the sessions this request opened. Runs when the request ends.
	 */
	public static function end_sessions(): void {
		$open               = self::$open_sessions;
		self::$open_sessions = [];
		foreach ( $open as $entry ) {
			try {
				$entry['manager']->destroy( $entry['session'] );
			} catch ( \Throwable $failure ) {
				unset( $failure );
			}
		}
	}

	/** Forget everything this request did. For tests. */
	public static function reset_for_tests(): void {
		self::$open_sessions  = [];
		self::$probe_request  = false;
		self::$login_handler  = null;
	}

	private static function key( string $token ): string {
		return self::KEY_PREFIX . substr( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), 0, 40 );
	}

	private static function normalize_path( string $path ): string {
		return '/' . ltrim( $path, '/' );
	}

	/** Record one use of a token. Nothing about the token itself is stored. */
	private static function audit( bool $accepted ): void {
		try {
			AuditLog::record(
				'stonewright/security-probe-token',
				[
					'_meta' => [
						'operation_class' => 'probe_token_use',
						'resource_type'   => 'probe_token',
						'resource_ref'    => $accepted ? 'accepted' : 'refused',
					],
				],
				$accepted ? 'ok' : 'blocked'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
