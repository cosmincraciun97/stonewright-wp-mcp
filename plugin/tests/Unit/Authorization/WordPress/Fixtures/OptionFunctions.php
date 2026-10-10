<?php
declare( strict_types=1 );

/**
 * What the autoload tests need and the test bootstrap does not provide:
 * - update_option() as the authorization classes call it: it records the autoload
 *   argument in $GLOBALS['stonewright_test_option_writes'][ option ][], then performs the
 *   bootstrap's update_option();
 * - the autoloaded option set, wp_load_alloptions(), kept in
 *   $GLOBALS['stonewright_test_alloptions'], and wp_set_option_autoload() of WordPress
 *   6.4 and later, which logs its call in $GLOBALS['stonewright_test_autoload_switches']
 *   and moves the option into that set.
 *
 * A function cannot be declared twice, and a call that already ran would keep using the
 * bootstrap's update_option(). Load this file with require_once in a test that runs in a
 * separate process, before anything calls into the authorization classes.
 */

namespace Stonewright\WpMcp\Authorization\WordPress {

	/** @param mixed ...$autoload */
	function update_option( string $option, mixed $value, mixed ...$autoload ): bool {
		$GLOBALS['stonewright_test_option_writes'][ $option ][] = $autoload[0] ?? 'omitted';
		return \update_option( $option, $value, ...$autoload );
	}
}

namespace {

	/** @return array<string, mixed> */
	function wp_load_alloptions( bool $force_cache = false ): array {
		return $GLOBALS['stonewright_test_alloptions'] ?? [];
	}

	function wp_set_option_autoload( string $option, bool|string $autoload ): bool {
		$GLOBALS['stonewright_test_autoload_switches'][] = [ $option, $autoload ];
		$GLOBALS['stonewright_test_alloptions'][ $option ] = $GLOBALS['stonewright_test_options'][ $option ] ?? '';
		return true;
	}
}
