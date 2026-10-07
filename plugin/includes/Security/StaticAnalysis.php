<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Logger;

/**
 * Runtime sanity check that warns operators if PHP is configured to allow
 * dynamic code execution patterns Stonewright never relies on.
 *
 * The warning is written when the set of enabled functions changes and at most once a day
 * otherwise. The current list is always available through `enabled_functions()`, which the
 * site environment ability reports.
 */
final class StaticAnalysis {

	/** Option that remembers the last warning: the function set and the time it was written. */
	private const NOTICE_OPTION = 'stonewright_dangerous_functions_notice';

	/** Seconds before an unchanged set is written again. */
	private const REPEAT_AFTER = 86400;

	/**
	 * PHP functions that can run operating-system commands.
	 *
	 * @var list<string>
	 */
	private const FUNCTIONS = [ 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen' ];

	public static function assert_environment(): void {
		if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
			self::notify( self::enabled_functions(), time() );
		}
	}

	/**
	 * Command-running PHP functions that this PHP configuration leaves enabled.
	 *
	 * @return list<string>
	 */
	public static function enabled_functions(): array {
		$disabled = (string) ini_get( 'disable_functions' );
		$enabled  = [];

		foreach ( self::FUNCTIONS as $function ) {
			if ( function_exists( $function ) && false === stripos( $disabled, $function ) ) {
				$enabled[] = $function;
			}
		}

		return $enabled;
	}

	/**
	 * Writes the warning when the enabled set is new or the last warning is a day old.
	 *
	 * @param list<string> $flagged Enabled functions.
	 * @param int          $now     Current Unix time.
	 * @return bool Whether a warning was written.
	 */
	public static function notify( array $flagged, int $now ): bool {
		if ( [] === $flagged ) {
			// A later change from "none" is reported again.
			if ( false !== get_option( self::NOTICE_OPTION, false ) ) {
				delete_option( self::NOTICE_OPTION );
			}
			return false;
		}

		$signature = implode( ',', $flagged );
		$last      = get_option( self::NOTICE_OPTION, false );
		if (
			is_array( $last )
			&& ( $last['signature'] ?? null ) === $signature
			&& $now - (int) ( $last['at'] ?? 0 ) < self::REPEAT_AFTER
		) {
			return false;
		}

		update_option( self::NOTICE_OPTION, [ 'signature' => $signature, 'at' => $now ], false );
		Logger::warning( 'dangerous_php_functions_enabled', [ 'functions' => $flagged ] );

		return true;
	}
}
