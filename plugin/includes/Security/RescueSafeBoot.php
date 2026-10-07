<?php
/**
 * What the plugin sees of safe boot.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\RescueRuntime;

/**
 * A view of the safe boot state that the rescue MU-plugin sets up before the plugin loads.
 *
 * In safe boot the stored plugin and theme selection is replaced for the request (only
 * Stonewright is active, the default theme is in use) and writes to that selection are ignored,
 * so the replacement is never saved. Code that has to change the selection on purpose, such as a
 * rollback that activates or deactivates a plugin or restores the theme options, runs inside
 * with_stored_selection(): there its reads see the real selection and its writes are saved.
 */
final class RescueSafeBoot {

	/** Seconds the audit marker of a safe boot session is kept: the length of a session. */
	private const SESSION_SECONDS = 1800;

	public static function register(): void {
		add_action( 'init', [ self::class, 'audit_entry' ], 20 );
	}

	/** Whether this request is loaded in safe boot. */
	public static function active(): bool {
		return true === RescueRuntime::call( 'is_safe_boot' );
	}

	/** The administrator of the safe boot session; 0 outside safe boot and in MCP route mode. */
	public static function user_id(): int {
		return self::active() ? (int) RescueRuntime::call( 'safe_boot_user' ) : 0;
	}

	/** The link that ends the safe boot session, or an empty string outside safe boot. */
	public static function exit_url(): string {
		return self::active() ? (string) RescueRuntime::call( 'exit_url' ) : '';
	}

	/**
	 * Runs $callback with the stored plugin and theme selection in place of the safe boot one.
	 *
	 * Outside safe boot, or without the helper, it simply runs the callback.
	 *
	 * @template T
	 * @param callable():T $callback Code that changes the plugin or theme selection.
	 * @return T
	 */
	public static function with_stored_selection( callable $callback ): mixed {
		if ( RescueRuntime::available( 'with_stored_selection' ) ) {
			return RescueRuntime::call( 'with_stored_selection', $callback );
		}
		return $callback();
	}

	/**
	 * Records that an administrator entered safe boot: once per session, with the administrator
	 * and nothing that could be used to join the session.
	 */
	public static function audit_entry(): void {
		$user_id = self::user_id();
		if ( $user_id < 1 ) {
			return;
		}
		$ref = (string) RescueRuntime::call( 'session_ref' );
		if ( '' === $ref ) {
			return;
		}
		$marker = 'stonewright_rescue_audited_' . $ref;
		if ( false !== get_transient( $marker ) ) {
			return;
		}
		set_transient( $marker, 1, self::SESSION_SECONDS );
		try {
			AuditLog::record(
				'stonewright/rescue-safe-boot',
				[
					'_meta' => [
						'operation_class'  => 'rescue_safe_boot',
						'resource_type'    => 'user',
						'resource_ref'     => (string) $user_id,
						'execution_status' => 'executed',
					],
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
