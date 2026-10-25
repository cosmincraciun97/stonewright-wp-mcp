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
	 * True when no restriction is set, or when the path equals a restriction entry or lies under one.
	 *
	 * @param string|null $setting The open_basedir value; null reads the running one.
	 */
	public static function allows( string $path, ?string $setting = null ): bool {
		$setting ??= (string) ini_get( 'open_basedir' );
		if ( '' === $setting ) {
			return true;
		}
		$path = str_replace( '\\', '/', $path );
		foreach ( explode( PATH_SEPARATOR, $setting ) as $entry ) {
			if ( '' === $entry ) {
				continue;
			}
			$entry = rtrim( str_replace( '\\', '/', $entry ), '/' );
			if ( $path === $entry || str_starts_with( $path, $entry . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
