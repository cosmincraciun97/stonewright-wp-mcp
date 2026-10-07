<?php
/**
 * Rescue keys: the one-time link that opens safe mode.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Core\RescueRuntime;

/**
 * Hands out the link that opens safe mode, through the rescue MU-plugin.
 *
 * A rescue key is issued for one administrator. It is single use and lives 15 minutes. Only the
 * SHA-256 of its secret is stored, and neither the key nor the secret is ever logged or audited.
 * The key is redeemed by the MU-plugin on a GET request to wp-login.php; it then starts a safe
 * boot session for that administrator and removes the key from the URL. Redeeming signs nobody
 * in and the login page loads with every plugin: safe boot starts on the requests of the browser
 * once that administrator has signed in the normal way. See Stonewright_Rescue for the keys, the
 * session cookie and what safe boot changes.
 *
 * WordPress Recovery Mode keys are not used: they are valid for a day by default and are not tied
 * to a user, while a rescue key is bound to one administrator and lives 15 minutes.
 */
final class RescueKeys {

	/** Slug of the Stonewright Rescue admin page, where the link leads after sign-in. */
	public const PAGE = 'stonewright-rescue';

	/** Seconds a key can be redeemed. */
	public const TTL = 900;

	/**
	 * A login URL that opens the Rescue page in safe mode for an administrator, or null when it
	 * cannot work: the user is not an administrator, or the helper is not installed and current.
	 */
	public static function safe_boot_url( int $user_id ): ?string {
		if ( $user_id < 1 || ! RescueInstaller::is_ready() || ! RescueRuntime::available( 'issue_key' ) ) {
			return null;
		}
		$issued = RescueRuntime::call( 'issue_key', $user_id );
		if ( ! is_array( $issued ) || ! isset( $issued['key'] ) || ! is_string( $issued['key'] ) ) {
			return null;
		}
		self::audit_issue( $user_id );
		return add_query_arg(
			[
				'stonewright_rescue' => $issued['key'],
				'redirect_to'        => admin_url( 'admin.php?page=' . self::PAGE ),
			],
			site_url( 'wp-login.php', 'login' )
		);
	}

	/**
	 * Removes every waiting key and every open safe boot session.
	 *
	 * @return int Number of records removed.
	 */
	public static function revoke_all(): int {
		return (int) RescueRuntime::call( 'purge_all' );
	}

	private static function audit_issue( int $user_id ): void {
		try {
			AuditLog::record(
				'stonewright/rescue-safe-boot-link',
				[
					'_meta'         => [
						'operation_class'  => 'rescue_key_issue',
						'resource_type'    => 'user',
						'resource_ref'     => (string) $user_id,
						'execution_status' => 'executed',
					],
					'valid_seconds' => self::TTL,
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
