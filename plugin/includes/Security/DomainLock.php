<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Domain-lock: records the WordPress home URL the first time AI abilities
 * are enabled. On each boot, verifies the current home URL still matches.
 * A mismatch auto-disables abilities and shows an admin notice (copied sites,
 * staging clones, or domain changes are caught before an attacker can exploit
 * a stale configuration).
 *
 * @stonewright-status stable
 */
final class DomainLock {

	private const OPTION = 'stonewright_locked_domain';

	/**
	 * Record the current home URL as the locked domain.
	 * No-op if a domain is already locked.
	 */
	public static function lock(): void {
		if ( '' !== (string) get_option( self::OPTION, '' ) ) {
			return;
		}
		update_option( self::OPTION, home_url( '/' ), false );
	}

	/**
	 * Returns true when the current home URL matches the locked domain,
	 * or when no domain has been locked yet.
	 */
	public static function check(): bool {
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return true; // Not locked yet.
		}
		return home_url( '/' ) === $stored;
	}

	/**
	 * Clear the stored lock (e.g., after an intentional domain migration).
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Returns the locked domain, or an empty string if no lock is set.
	 */
	public static function locked_domain(): string {
		return (string) get_option( self::OPTION, '' );
	}
}
