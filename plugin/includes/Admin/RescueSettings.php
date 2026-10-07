<?php
/**
 * The rescue setting for the MCP route.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * One option, off by default: while a rescue incident is open, requests to the Stonewright MCP
 * routes that carry an Authorization header are loaded in safe boot (only Stonewright active,
 * the default theme), so an agent can still connect and roll the change back while the rest of
 * the site is down. Authentication and permission checks are not touched: Stonewright still
 * authenticates the request and every ability still checks its permissions.
 *
 * The field is on Settings > General, where WordPress saves it with the nonce and the
 * manage_options check of its own settings form. A change is audited.
 */
final class RescueSettings {

	/** Read by the rescue MU-plugin as Stonewright_Rescue::OPTION_MCP. */
	public const OPTION = 'stonewright_rescue_mcp_safe_boot';

	public const SECTION = 'stonewright_rescue';

	/** Values the MU-plugin takes as on. */
	private const ON = [ '1', 1, true, 'yes', 'on' ];

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'audit_change' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION, [ self::class, 'audit_added' ], 10, 2 );
	}

	public static function register_settings(): void {
		register_setting(
			'general',
			self::OPTION,
			[
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
			]
		);
		add_settings_section( self::SECTION, __( 'Stonewright rescue', 'stonewright' ), [ self::class, 'render_section' ], 'general' );
		add_settings_field(
			self::OPTION,
			__( 'Safe mode on the MCP route', 'stonewright' ),
			[ self::class, 'render_field' ],
			'general',
			self::SECTION,
			[ 'label_for' => self::OPTION ]
		);
	}

	/** Whether the setting is on. Off unless it was turned on. */
	public static function mcp_safe_boot_enabled(): bool {
		return in_array( get_option( self::OPTION, '' ), self::ON, true );
	}

	/**
	 * What is saved: "1" for an explicit on value, an empty string for anything else.
	 *
	 * @param mixed $value The submitted value.
	 */
	public static function sanitize( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : $value;
		return in_array( $value, self::ON, true ) ? '1' : '';
	}

	public static function render_section(): void {
		echo '<p>' . esc_html__( 'Settings for the Stonewright rescue helper, which records a fatal error that follows a Stonewright change and opens safe mode.', 'stonewright' ) . '</p>';
	}

	public static function render_field(): void {
		echo '<label for="' . esc_attr( self::OPTION ) . '"><input type="checkbox" id="' . esc_attr( self::OPTION ) . '" name="' . esc_attr( self::OPTION ) . '" value="1"' . checked( self::mcp_safe_boot_enabled(), true, false ) . ' /> '
			. esc_html__( 'Open the Stonewright MCP route in safe mode while a rescue incident is open', 'stonewright' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. When on, a request to the Stonewright MCP route that carries credentials is loaded with only Stonewright active and the default theme, so an agent can still connect and roll the change back while the rest of the site is down. Other plugins, including security plugins, do not run on those requests. Stonewright still runs its authentication and permission checks on the request.', 'stonewright' ) . '</p>';
	}

	/**
	 * Audits a change of the setting (update_option_{option} action).
	 *
	 * @param mixed $old_value The value before.
	 * @param mixed $value     The value after.
	 */
	public static function audit_change( mixed $old_value, mixed $value ): void {
		$was = in_array( $old_value, self::ON, true );
		$now = in_array( $value, self::ON, true );
		if ( $was === $now ) {
			return;
		}
		try {
			AuditLog::record(
				'stonewright/rescue-setting',
				[
					'_meta'   => [
						'operation_class'  => 'rescue_setting',
						'resource_type'    => 'option',
						'resource_ref'     => 'mcp_safe_boot',
						'execution_status' => 'executed',
					],
					'setting' => 'mcp_safe_boot',
					'from'    => $was,
					'to'      => $now,
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	/**
	 * Audits the first time the setting is saved (add_option_{option} action).
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The value saved.
	 */
	public static function audit_added( mixed $option, mixed $value ): void {
		unset( $option );
		self::audit_change( '', $value );
	}
}
