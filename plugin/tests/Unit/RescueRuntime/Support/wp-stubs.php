<?php
declare( strict_types=1 );

/**
 * WordPress functions and classes the rescue runtime tests need on top of tests/bootstrap.php.
 * Every definition is guarded, so another test file that defines the same name first wins.
 */

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['stonewright_test_site_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'wp_validate_redirect' ) ) {
	/** Same host only, like core's default for a site without allowed redirect hosts. */
	function wp_validate_redirect( string $location, string $fallback_url = '' ): string {
		return str_starts_with( $location, 'https://example.test/' ) ? $location : $fallback_url;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {
		$GLOBALS['stonewright_test_lifecycle_hooks']['activate'][] = [ $file, $callback ];
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {
		$GLOBALS['stonewright_test_lifecycle_hooks']['deactivate'][] = [ $file, $callback ];
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( string $id, string $title, callable $callback, string $page ): void {
		$GLOBALS['stonewright_test_settings_sections'][] = [ 'id' => $id, 'title' => $title, 'callback' => $callback, 'page' => $page ];
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( string $id, string $title, callable $callback, string $page, string $section = 'default', array $args = [] ): void {
		$GLOBALS['stonewright_test_settings_fields'][] = [ 'id' => $id, 'title' => $title, 'callback' => $callback, 'page' => $page, 'section' => $section, 'args' => $args ];
	}
}

if ( ! class_exists( 'WP_User', false ) ) {
	class WP_User {

		public int $ID = 0;
		public string $user_login = '';
		public string $user_email = '';

		public function __construct( int $id = 0 ) {
			$this->ID         = $id;
			$this->user_login = 'user-' . $id;
			$this->user_email = 'user-' . $id . '@example.test';
		}

		public function exists(): bool {
			return $this->ID > 0 && ! in_array( $this->ID, (array) ( $GLOBALS['stonewright_test_missing_user_ids'] ?? [] ), true );
		}
	}
}
