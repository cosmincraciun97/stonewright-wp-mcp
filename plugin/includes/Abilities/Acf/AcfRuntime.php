<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

/**
 * Detect Advanced Custom Fields availability.
 */
final class AcfRuntime {

	public static function is_active(): bool {
		// Unit tests toggle this without unloading function stubs.
		if ( array_key_exists( 'stonewright_test_acf_active', $GLOBALS ) ) {
			return (bool) $GLOBALS['stonewright_test_acf_active'];
		}
		return function_exists( 'get_fields' )
			|| function_exists( 'acf_get_field_groups' )
			|| class_exists( 'ACF', false );
	}
}
