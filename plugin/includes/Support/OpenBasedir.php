<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Answers whether the PHP open_basedir restriction allows a path, without touching the path.
 * Probing a forbidden path with is_readable() or file_exists() makes PHP raise a warning for
 * every call, so a caller asks here first and skips what is refused.
 */
final class OpenBasedir {

	/**
	 * True when no restriction is set, or when the path equals a restriction entry (or its real
	 * location) or lies under one. The candidate path is normalised lexically and never resolved
	 * on disk; only the entries, which the restriction allows by definition, are resolved.
	 *
	 * Limits: a symlink inside an allowed root that points outside it is not detected; a relative
	 * entry such as "." is compared as written; the comparison is case-sensitive on Windows too.
	 *
	 * @param string|null $setting   The open_basedir value; null reads the running one.
	 * @param string|null $separator Between entries of the setting; null is PATH_SEPARATOR (";" on a Windows PHP, ":" elsewhere).
	 */
	public static function allows( string $path, ?string $setting = null, ?string $separator = null ): bool {
		$setting ??= (string) ini_get( 'open_basedir' );
		if ( '' === $setting ) {
			return true;
		}
		$path = self::normalise( $path );
		foreach ( explode( $separator ?? PATH_SEPARATOR, $setting ) as $entry ) {
			if ( '' === $entry ) {
				continue;
			}
			$roots = [ rtrim( str_replace( '\\', '/', $entry ), '/' ) ];
			$real = realpath( $entry );
			if ( is_string( $real ) ) {
				$roots[] = rtrim( str_replace( '\\', '/', $real ), '/' );
			}
			foreach ( $roots as $root ) {
				if ( $path === $root || str_starts_with( $path, $root . '/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Forward slashes with "." and ".." segments resolved by text alone; a ".." never climbs above the root. */
	private static function normalise( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$root = preg_match( '#^(?:[A-Za-z]:)?/#', $path, $match ) ? $match[0] : '';
		$kept = [];
		foreach ( explode( '/', substr( $path, strlen( $root ) ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' !== $segment ) {
				$kept[] = $segment;
			} elseif ( [] !== $kept && '..' !== end( $kept ) ) {
				array_pop( $kept );
			} elseif ( '' === $root ) {
				$kept[] = $segment;
			}
		}
		return $root . implode( '/', $kept );
	}
}
