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
					'status'     => 400,
					'path'       => $relative,
					'allowlist'  => self::allowlist_description(),
					'error_code' => 'theme_file_path_denied',
				]
			);
		}

		$theme_key = 'template' === $theme ? 'template' : 'stylesheet';
		$root_raw  = 'template' === $theme_key
			? (string) \get_template_directory()
			: (string) \get_stylesheet_directory();
		$root      = \wp_normalize_path( $root_raw );
		$absolute  = \wp_normalize_path( $root . '/' . $relative );
		$root_trim = rtrim( $root, '/' );

		// Lexical containment (unit tests / non-existing files).
		if ( ! str_starts_with( $absolute, $root_trim . '/' ) ) {
			return new \WP_Error(
				'stonewright_theme_file_path_traversal',
				__( 'Resolved path escapes the theme root.', 'stonewright' ),
				[ 'status' => 400, 'path' => $relative ]
			);
		}

		// When paths exist, resolve symlinks and re-check the canonical boundary.
		$canonical_root = \realpath( $root );
		if ( false !== $canonical_root ) {
			$canonical_root = \wp_normalize_path( $canonical_root );
			$probe          = $absolute;
			if ( ! \file_exists( $probe ) ) {
				// Walk up to nearest existing parent so create_if_missing stays safe.
				$probe = \dirname( $absolute );
				while ( ! \file_exists( $probe ) && \strlen( $probe ) > 1 ) {
					$probe = \dirname( $probe );
				}
			}
			$canonical_probe = \realpath( $probe );
			if ( false === $canonical_probe ) {
				return new \WP_Error(
					'stonewright_theme_file_path_traversal',
					__( 'Could not resolve theme path.', 'stonewright' ),
					[ 'status' => 400, 'path' => $relative ]
				);
			}
			$canonical_probe = \wp_normalize_path( $canonical_probe );
			$root_prefix     = rtrim( $canonical_root, '/' );
			if ( $canonical_probe !== $root_prefix && ! str_starts_with( $canonical_probe, $root_prefix . '/' ) ) {
				return new \WP_Error(
					'stonewright_theme_file_path_traversal',
					__( 'Resolved path escapes the theme root after symlink resolution.', 'stonewright' ),
					[ 'status' => 400, 'path' => $relative ]
				);
			}
			// Prefer the realpath of the file when it exists.
			if ( \is_file( $absolute ) ) {
				$real_file = \realpath( $absolute );
				if ( false !== $real_file ) {
					$absolute = \wp_normalize_path( $real_file );
				}
			}
			$root = $canonical_root;
		}

		return [
			'relative'   => $relative,
			'absolute'   => $absolute,
			'theme_key'  => $theme_key,
			'stylesheet' => 'template' === $theme_key
				? (string) \get_template()
				: (string) \get_stylesheet(),
			'root'       => $root,
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

		$prefixes = [ 'inc/', 'css/', 'js/', 'assets/', 'src/' ];
		$matched  = false;
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $lower, $prefix ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched && 1 === preg_match( '/^[^\/]+\.(css|js)$/', $lower ) ) {
			$matched = true;
		}
		if ( ! $matched ) {
			return false;
		}

		if ( str_starts_with( $lower, 'inc/' ) ) {
			return (bool) preg_match( '/\.(css|js|php)$/', $lower );
		}

		return (bool) preg_match( '/\.(css|js)$/', $lower );
	}

	public static function is_php_path( string $relative ): bool {
		return str_ends_with( strtolower( str_replace( '\\', '/', $relative ) ), '.php' );
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
