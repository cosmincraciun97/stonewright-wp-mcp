<?php
/**
 * The loaded copy of the rescue MU-plugin.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * The one place that talks to Stonewright_Rescue, the class the rescue MU-plugin declares.
 *
 * WordPress loads that file from the must-use plugins directory before this plugin, and it may be
 * missing (the directory was not writable) or older than the plugin expects. Every call goes
 * through available() and returns null when the loaded copy cannot answer, so a caller never
 * depends on the file being there.
 */
final class RescueRuntime {

	public const CLASS_NAME = 'Stonewright_Rescue';

	/**
	 * Whether a copy is loaded, and when a method is named, whether it has that method.
	 */
	public static function available( string $method = '' ): bool {
		$class = self::CLASS_NAME;
		return class_exists( $class, false ) && ( '' === $method || is_callable( [ $class, $method ] ) );
	}

	/** Version of the loaded copy, or 0 when none is loaded. */
	public static function version(): int {
		return self::available() ? (int) constant( self::CLASS_NAME . '::VERSION' ) : 0;
	}

	/**
	 * Calls a public method of the loaded copy.
	 *
	 * @param string $method    Method name.
	 * @param mixed  ...$arguments Arguments.
	 * @return mixed What the method returned, or null when the loaded copy has no such method.
	 */
	public static function call( string $method, mixed ...$arguments ): mixed {
		if ( ! self::available( $method ) ) {
			return null;
		}
		$class = self::CLASS_NAME;
		return $class::$method( ...$arguments );
	}
}
