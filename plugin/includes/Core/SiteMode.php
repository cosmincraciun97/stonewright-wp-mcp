<?php
/**
 * The one way to read the mode of a site.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Reads the stonewright_mode option, and fails safe when it is not usable. Every gate and every
 * display of the mode goes through current(), so no caller has its own default:
 * - a stored mode is one of the three exact strings and is returned as is;
 * - a mode that was never stored (the option is absent or empty) follows the environment type, the
 *   same value SiteDefaults::seed() writes on the first request;
 * - anything else (an unknown string, another letter case, an array, a number, a boolean) counts as
 *   production-safe, the strictest mode, so a damaged value can never loosen a gate.
 *
 * Nothing here writes: a read never changes the stored option.
 */
final class SiteMode {

	public const OPTION          = 'stonewright_mode';
	public const DEVELOPMENT     = 'development';
	public const STAGING         = 'staging';
	public const PRODUCTION_SAFE = 'production-safe';

	/**
	 * The three modes a site can be in.
	 *
	 * @return list<string>
	 */
	public static function modes(): array {
		return [ self::DEVELOPMENT, self::STAGING, self::PRODUCTION_SAFE ];
	}

	/**
	 * The value in the option table: null when the mode was never stored (absent or an empty string),
	 * otherwise the raw string. A stored value that is not a string comes back as a placeholder that
	 * is not a mode, never as null, so it is not mistaken for "never stored".
	 *
	 * The explicit null default bypasses a default_option_stonewright_mode filter.
	 */
	public static function stored(): ?string {
		$value = get_option( self::OPTION, null );
		if ( null === $value || '' === $value ) {
			return null;
		}
		// A value that is not a string is invalid, not missing: keep it distinguishable from null.
		return is_string( $value ) ? $value : '(invalid)';
	}

	/**
	 * The mode in force: a stored valid mode, the environment default when none is stored, and
	 * production-safe for anything else.
	 */
	public static function current(): string {
		return self::resolve( self::stored() );
	}

	/**
	 * The mode a value stands for, by the same rules as current(): null and an empty string follow the
	 * environment, an exact mode is itself and anything else is production-safe. For a value that is about
	 * to be stored, to compare it with the mode in force.
	 */
	public static function resolve( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return SiteDefaults::mode_for_environment();
		}
		return is_string( $value ) && in_array( $value, self::modes(), true ) ? $value : self::PRODUCTION_SAFE;
	}

	public static function is_production_safe(): bool {
		return self::PRODUCTION_SAFE === self::current();
	}
}
