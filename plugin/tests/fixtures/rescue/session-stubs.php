<?php
/**
 * Stand-ins for the WordPress session API, so the probe token's request-local login can be tested
 * without WordPress. Loaded only by the tests that need them.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_Session_Tokens', false ) ) {
	class WP_Session_Tokens {

		/** @var array<int, WP_Session_Tokens> */
		public static array $instances = [];

		/** @var list<array{user:int,expiration:int,token:string}> */
		public static array $created = [];

		/** @var list<string> */
		public static array $destroyed = [];

		public int $user_id;

		public function __construct( int $user_id ) {
			$this->user_id = $user_id;
		}

		public static function get_instance( int $user_id ): self {
			self::$instances[ $user_id ] ??= new self( $user_id );
			return self::$instances[ $user_id ];
		}

		public static function reset(): void {
			self::$instances = [];
			self::$created   = [];
			self::$destroyed = [];
		}

		public function create( int $expiration ): string {
			$token           = 'session-' . count( self::$created ) . '-' . $this->user_id;
			self::$created[] = [ 'user' => $this->user_id, 'expiration' => $expiration, 'token' => $token ];
			return $token;
		}

		public function destroy( string $token ): void {
			self::$destroyed[] = $token;
		}
	}
}

if ( ! function_exists( 'wp_generate_auth_cookie' ) ) {
	function wp_generate_auth_cookie( int $user_id, int $expiration, string $scheme, string $token ): string {
		return implode( '|', [ $user_id, $expiration, $scheme, $token ] );
	}
}

foreach ( [ 'AUTH_COOKIE' => 'wordpress_test', 'SECURE_AUTH_COOKIE' => 'wordpress_sec_test', 'LOGGED_IN_COOKIE' => 'wordpress_logged_in_test' ] as $stonewright_cookie_const => $stonewright_cookie_name ) {
	if ( ! defined( $stonewright_cookie_const ) ) {
		define( $stonewright_cookie_const, $stonewright_cookie_name );
	}
}
unset( $stonewright_cookie_const, $stonewright_cookie_name );
