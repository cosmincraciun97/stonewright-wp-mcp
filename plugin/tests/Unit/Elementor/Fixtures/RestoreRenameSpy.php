<?php
declare( strict_types=1 );

/**
 * Lets a test look at the restore temp file at the moment CssAssetTransaction renames it into
 * place. rename() resolves to this function inside the Stonewright\WpMcp\Elementor namespace;
 * it calls the callable in $GLOBALS['stonewright_test_rename_spy'] (if any) with the source and
 * destination, then performs the real rename().
 *
 * A function cannot be declared twice, and a call site that already ran would keep using the
 * built-in rename(). Load this file with require_once in a test that runs in a separate
 * process, before anything calls into CssAssetTransaction.
 */

namespace Stonewright\WpMcp\Elementor {

	function rename( string $from, string $to ): bool {
		$spy = $GLOBALS['stonewright_test_rename_spy'] ?? null;
		if ( is_callable( $spy ) ) {
			$spy( $from, $to );
		}
		return \rename( $from, $to );
	}
}
