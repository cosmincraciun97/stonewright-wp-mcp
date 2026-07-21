<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

/**
 * Shared path allowlist + resolution for theme file abilities.
 */
final class ThemeFilePaths {

	/**
	 * Resolve a theme-relative path against the active stylesheet or template.
	 *
	 * @return array{relative:string,absolute:string,theme_key:string,stylesheet:string,root:string}|\WP_Error
	 */
	public static function resolve( string $relative, string $theme = 'stylesheet' ): array|\WP_Error {
		$relative = self::normalise_relative( $relative );
		if ( $relative instanceof \WP_Error ) {
			return $relative;
		}

		if ( ! self::is_allowlisted( $relative ) ) {
			return new \WP_Error(
				'stonewright_theme_file_path_denied',
				__( 'Path is outside the theme file allowlist (style.css, functions.php, *.css/*.js under root/assets/css/js/inc).', 'stonewright' ),
				[
					'status'    => 400,
					'path'      => $relative,
					'allowlist' => self::allowlist_description(),
					'error_code'=> 'theme_file_path_denied',
				]
			);
		}

		$theme_key = 'template' === $theme ? 'template' : 'stylesheet';
		$root      = 'template' === $theme_key
			? (string) get_template_directory()
			: (string) get_stylesheet_directory();
		$root      = wp_normalize_path( $root );
		$absolute  = wp_normalize_path( $root . '/' . $relative );

		// Prevent path traversal after realpath-style normalisation.
		if ( ! str_starts_with( $absolute, rtrim( $root, '/' ) . '/' ) && $absolute !== $root . '/' . $relative ) {
			// Fallback strict prefix check without realpath (unit tests / missing files).
			if ( ! str_starts_with( $absolute, rtrim( $root, '/' ) . '/' ) ) {
				return new \WP_Error(
					'stonewright_theme_file_path_traversal',
					__( 'Resolved path escapes the theme root.', 'stonewright' ),
					[ 'status' => 400, 'path' => $relative ]
				);
			}
		}

		if ( str_contains( $relative, '..' ) ) {
			return new \WP_Error(
				'stonewright_theme_file_path_traversal',
				__( 'Path traversal is not allowed.', 'stonewright' ),
				[ 'status' => 400, 'path' => $relative ]
			);
		}

		return [
			'relative'    => $relative,
			'absolute'    => $absolute,
			'theme_key'   => $theme_key,
			'stylesheet'  => 'template' === $theme_key
				? (string) get_template()
				: (string) get_stylesheet(),
			'root'        => $root,
		];
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function normalise_relative( string $path ) {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			return new \WP_Error(
				'stonewright_theme_file_path_empty',
				__( 'A non-empty theme-relative path is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		if ( str_contains( $path, "\0" ) || str_contains( $path, '..' ) ) {
			return new \WP_Error(
				'stonewright_theme_file_path_traversal',
				__( 'Path traversal is not allowed.', 'stonewright' ),
				[ 'status' => 400, 'path' => $path ]
			);
		}
		return $path;
	}

	public static function is_allowlisted( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );
		$lower    = strtolower( $relative );

		// Exact root allowlist.
		$exact = [
			'style.css',
			'functions.php',
			'iviteb_scripts.js',
			'scripts.js',
			'custom.js',
			'theme.js',
		];
		if ( in_array( $lower, $exact, true ) ) {
			return true;
		}

		// Directory allowlists.
		$prefixes = [ 'inc/', 'css/', 'js/', 'assets/', 'src/' ];
		$matched  = false;
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $lower, $prefix ) ) {
				$matched = true;
				break;
			}
		}
		// Also allow root-level .css / .js (not php except functions.php).
		if ( ! $matched && 1 === preg_match( '/^[^\/]+\.(css|js)$/', $lower ) ) {
			$matched = true;
		}
		if ( ! $matched ) {
			return false;
		}

		// Under allowlisted dirs: only css/js/php in inc.
		if ( str_starts_with( $lower, 'inc/' ) ) {
			return (bool) preg_match( '/\.(css|js|php)$/', $lower );
		}

		return (bool) preg_match( '/\.(css|js)$/', $lower );
	}

	/**
	 * @return list<string>
	 */
	public static function allowlist_description(): array {
		return [
			'style.css',
			'functions.php',
			'*.css / *.js at theme root',
			'inc/*.{css,js,php}',
			'css/* , js/* , assets/* , src/* (css/js only)',
		];
	}
}
