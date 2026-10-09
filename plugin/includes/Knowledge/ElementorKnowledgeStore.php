<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stonewright\WpMcp\Support\DirectoryGuard;

/**
 * Location and path rules of the Elementor knowledge store.
 *
 * The store is a private folder under uploads:
 * `<uploads>/stonewright-private/knowledge/elementor/<hub>/<slug>.md`.
 * Every folder the store creates carries deny guards (index.php, .htaccess and
 * web.config). Nothing is bundled with the plugin; the refresh ability fills the
 * store and the readers only read it.
 */
final class ElementorKnowledgeStore {

	/** Hub folders; mirrors the `hub` enum of the refresh ability. */
	public const HUBS = [ 'widgets', 'editor', 'theme', 'developer', 'custom-widget', 'help-root' ];

	public const PRIVATE_DIRECTORY = 'stonewright-private';

	private const SEGMENTS = [ 'knowledge', 'elementor' ];

	private const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D';

	/** @phpstan-assert-if-true string $hub */
	public static function is_valid_hub( mixed $hub ): bool {
		return is_string( $hub ) && in_array( $hub, self::HUBS, true );
	}

	/** Uploads base folder, or null when uploads are unavailable. */
	private static function uploads_base(): ?string {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) ? $upload['basedir'] : '';
		if ( '' === $base || ! empty( $upload['error'] ) ) {
			return null;
		}
		return rtrim( str_replace( '\\', '/', $base ), '/' );
	}

	/** Absolute path of the store root. The folder may not exist yet. */
	public static function root(): ?string {
		$base = self::uploads_base();
		if ( null === $base ) {
			return null;
		}
		return $base . '/' . self::PRIVATE_DIRECTORY . '/' . implode( '/', self::SEGMENTS );
	}

	/** Absolute path of the change log. The file may not exist yet. */
	public static function change_log_path(): ?string {
		$root = self::root();
		return null === $root ? null : $root . '/_change_log.md';
	}

	/** Path relative to the uploads folder, for display. */
	public static function relative( string $path ): string {
		$base = self::uploads_base();
		$norm = str_replace( '\\', '/', $path );
		if ( null !== $base && str_starts_with( $norm, $base . '/' ) ) {
			return substr( $norm, strlen( $base ) + 1 );
		}
		return $norm;
	}

	/**
	 * Markdown articles in the store, optionally limited to one hub. Reads only.
	 * Files that start with an underscore, symbolic links and anything outside
	 * the store are left out.
	 *
	 * @return list<string>
	 */
	public static function article_files( ?string $hub = null ): array {
		$root = self::root();
		if ( null === $root || is_link( $root ) || ! is_dir( $root ) ) {
			return [];
		}
		$base = $root;
		if ( null !== $hub ) {
			if ( ! self::is_valid_hub( $hub ) ) {
				return [];
			}
			$base = $root . '/' . $hub;
			if ( is_link( $base ) || ! is_dir( $base ) ) {
				return [];
			}
		}

		$real_root = realpath( $root );
		if ( false === $real_root ) {
			return [];
		}
		$real_root = str_replace( '\\', '/', $real_root );

		$out = [];
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || $file->isLink() || ! $file->isFile() || strtolower( $file->getExtension() ) !== 'md' ) {
					continue;
				}
				if ( str_starts_with( $file->getBasename(), '_' ) ) {
					continue;
				}
				$real = realpath( $file->getPathname() );
				if ( false === $real || ! str_starts_with( str_replace( '\\', '/', $real ), $real_root . '/' ) ) {
					continue;
				}
				$out[] = str_replace( '\\', '/', $file->getPathname() );
			}
		} catch ( \UnexpectedValueException $e ) {
			return [];
		}
		sort( $out );
		return $out;
	}

	/**
	 * Creates the store root and one hub folder, with deny guards on every
	 * folder level. Returns the hub folder, or null when the hub is not known,
	 * a level cannot be created or guarded, or a level is a link or resolves
	 * outside the store.
	 */
	public static function ensure_hub_directory( string $hub ): ?string {
		if ( ! self::is_valid_hub( $hub ) ) {
			return null;
		}
		$base = self::uploads_base();
		if ( null === $base || ! is_dir( $base ) ) {
			return null;
		}
		$real_base = realpath( $base );
		if ( false === $real_base ) {
			return null;
		}

		$path = $base;
		foreach ( array_merge( [ self::PRIVATE_DIRECTORY ], self::SEGMENTS, [ $hub ] ) as $segment ) {
			$path .= '/' . $segment;
			if ( is_link( $path ) ) {
				return null;
			}
			if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
				return null;
			}
			if ( ! DirectoryGuard::apply( $path ) ) {
				return null;
			}
		}

		$expected = str_replace( '\\', '/', $real_base ) . '/' . self::PRIVATE_DIRECTORY . '/' . implode( '/', self::SEGMENTS ) . '/' . $hub;
		$real     = realpath( $path );
		if ( false === $real || str_replace( '\\', '/', $real ) !== $expected ) {
			return null;
		}
		return $path;
	}

	/**
	 * Absolute path of one article file, after the hub folder exists and is
	 * guarded. Returns null when the hub or slug is not valid, the folder cannot
	 * be used, or the target is a link or resolves outside the hub folder.
	 */
	public static function file_path( string $hub, string $slug ): ?string {
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			return null;
		}
		$dir = self::ensure_hub_directory( $hub );
		if ( null === $dir ) {
			return null;
		}
		$file = $dir . '/' . $slug . '.md';
		if ( is_link( $file ) ) {
			return null;
		}
		if ( file_exists( $file ) ) {
			$real = realpath( $file );
			$real_dir = realpath( $dir );
			if ( false === $real || false === $real_dir || ! str_starts_with( str_replace( '\\', '/', $real ), str_replace( '\\', '/', $real_dir ) . '/' ) ) {
				return null;
			}
		}
		return $file;
	}
}
