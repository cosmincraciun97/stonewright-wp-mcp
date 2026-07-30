<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Logger;

/**
 * Runtime sanity check that warns operators if PHP is configured to allow
 * dynamic code execution patterns Stonewright never relies on.
 */
final class StaticAnalysis {

	public static function assert_environment(): void {
		$disabled = (string) ini_get( 'disable_functions' );
		$flagged  = [];

		foreach ( [ 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen' ] as $function ) {
			if ( function_exists( $function ) && false === stripos( $disabled, $function ) ) {
				$flagged[] = $function;
			}
		}

		if ( $flagged && defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
			Logger::warning(
				'dangerous_php_functions_enabled',
				[ 'functions' => $flagged ]
			);
		}
	}
}
