<?php
declare( strict_types=1 );

/**
 * wp_set_option_autoload_values() of WordPress 6.4 and later, as BootOptionsTest needs it: it records
 * each map it receives in $GLOBALS['stonewright_test_autoload_value_calls'] and reports success for
 * every option.
 *
 * A function cannot be declared twice and cannot be unloaded: load this file with require_once in a
 * test that runs in a separate process.
 */

if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
	/**
	 * @param array<string, bool|string> $options
	 * @return array<string, bool>
	 */
	function wp_set_option_autoload_values( array $options ): array {
		$GLOBALS['stonewright_test_autoload_value_calls'][] = $options;
		return array_fill_keys( array_keys( $options ), true );
	}
}
