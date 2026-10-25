<?php
/**
 * The write freeze and the Elementor write pacing, as settings an administrator controls.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\WriteFreeze;

/**
 * The "Busy sites" card of Setup > Settings. It joins the Settings API form of the page, so one nonce and one
 * manage_options check (options.php) save it; the options are also guarded against an agent by LeashGuard.
 *
 * The freeze is two fields posted as one option: a date and time in the site's timezone ("Freeze writes until") and a
 * reason. Leaving the time empty lifts the freeze. A time that is not in the future, or that is not a date, is refused
 * with a message and the stored freeze stays. A reason is plain text of at most WriteFreeze::MAX_REASON characters.
 * Every change is audited.
 */
final class WriteFreezeSettings {

	/** The settings-errors slug of the refusals. */
	public const ERRORS = 'stonewright_busy_sites';

	public const SECTION_ID = 'stonewright-busy-sites';

	/** The format the datetime-local control reads and posts. */
	private const FIELD_FORMAT = 'Y-m-d\TH:i';

	public static function register(): void {
		add_action( 'update_option_' . WriteFreeze::OPTION, [ self::class, 'audit_change' ], 10, 2 );
		add_action( 'add_option_' . WriteFreeze::OPTION, [ self::class, 'audit_added' ], 10, 2 );
	}

	/** The freeze joins the Settings API group of Setup, so one form and one nonce save it. */
	public static function register_settings(): void {
		register_setting(
			ConfigurationPage::OPTION_GROUP,
			WriteFreeze::OPTION,
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => [ self::class, 'sanitize_freeze' ],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * What is stored for the posted freeze: `[ 'until' => unix time, 'reason' => text ]`, or an empty array for no
	 * freeze. A refusal keeps the stored value.
	 *
	 * @param mixed $value The posted value: an array with `until` and `reason`.
	 * @return array{until?: int, reason?: string}
	 */
	public static function sanitize_freeze( mixed $value, ?int $now = null ): array {
		$stored = WriteFreeze::stored();
		$kept   = null === $stored ? [] : $stored;
		if ( ! is_array( $value ) ) {
			return $kept;
		}

		$field = isset( $value['until'] ) && is_string( $value['until'] ) ? trim( $value['until'] ) : '';
		if ( '' === $field ) {
			return [];
		}

		$until = self::parse_local_time( $field );
		if ( null === $until ) {
			self::refuse( __( 'Freeze writes until: enter a date and time. The previous freeze was kept.', 'stonewright' ) );
			return $kept;
		}
		// The form shows minutes: a freeze that is posted back as it was shown is not a change.
		if ( null !== $stored && intdiv( $stored['until'], 60 ) === intdiv( $until, 60 ) ) {
			$until = $stored['until'];
		} elseif ( $until <= ( $now ?? time() ) ) {
			self::refuse( __( 'Freeze writes until: enter a time in the future, or leave it empty to lift the freeze. The previous freeze was kept.', 'stonewright' ) );
			return $kept;
		}

		$reason = isset( $value['reason'] ) && is_string( $value['reason'] ) ? sanitize_text_field( wp_unslash( $value['reason'] ) ) : '';
		if ( mb_strlen( $reason ) > WriteFreeze::MAX_REASON ) {
			$reason = mb_substr( $reason, 0, WriteFreeze::MAX_REASON );
		}

		return [ 'until' => $until, 'reason' => $reason ];
	}

	/** The "Busy sites" card of the settings form. */
	public static function settings_html( ?int $now = null ): string {
		$stored = WriteFreeze::stored();
		$active = WriteFreeze::active( $now );

		$until = FormField::input(
			__( 'Freeze writes until', 'stonewright' ),
			WriteFreeze::OPTION . '[until]',
			[
				'id'    => 'stonewright_write_freeze_until',
				'type'  => 'datetime-local',
				'size'  => 'md',
				'value' => $active && null !== $stored ? wp_date( self::FIELD_FORMAT, $stored['until'] ) : '',
				'help'  => sprintf(
					/* translators: %s: the site's timezone */
					__( 'In the site timezone (%s). While the freeze is on, agents can read but not change the site. Rollbacks keep working. Leave empty to lift it.', 'stonewright' ),
					wp_timezone_string()
				),
			]
		);
		$reason = FormField::input(
			__( 'Reason', 'stonewright' ),
			WriteFreeze::OPTION . '[reason]',
			[
				'id'        => 'stonewright_write_freeze_reason',
				'size'      => 'md',
				'maxlength' => WriteFreeze::MAX_REASON,
				'value'     => $active && null !== $stored ? $stored['reason'] : '',
				'help'      => __( 'Shown to the agent with the refusal.', 'stonewright' ),
			]
		);

		$status = $active && null !== $stored
			? Notice::callout(
				'warn',
				'',
				sprintf(
					/* translators: %s: date and time in the site's timezone */
					__( 'Writes are frozen until %s.', 'stonewright' ),
					(string) wp_date( 'Y-m-d H:i', $stored['until'] )
				)
			)
			: '';

		return Card::render(
			__( 'Busy sites', 'stonewright' ),
			$status . Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $until . $reason ),
			[
				'id'   => self::SECTION_ID,
				'desc' => __( 'Guard rails for a site under load. Only an administrator in wp-admin can lift them.', 'stonewright' ),
			]
		);
	}

	/** The notice that tells which field a save refused, printed above the form after options.php returns. */
	public static function refusal_html(): string {
		$messages = '';
		foreach ( get_settings_errors( self::ERRORS ) as $error ) {
			$messages .= Html::element( 'p', [], Html::text( (string) $error['message'] ) );
		}
		if ( '' === $messages ) {
			return '';
		}

		return Notice::render( 'danger', __( 'Busy site settings were not saved', 'stonewright' ), '', [ 'text_html' => $messages ] );
	}

	/**
	 * Audits a change of the freeze (update_option_{option} action).
	 *
	 * @param mixed $old_value The value before.
	 * @param mixed $value     The value after.
	 */
	public static function audit_change( mixed $old_value, mixed $value ): void {
		$was = is_array( $old_value ) ? (int) ( $old_value['until'] ?? 0 ) : 0;
		$now = is_array( $value ) ? (int) ( $value['until'] ?? 0 ) : 0;
		if ( $was === $now ) {
			return;
		}
		try {
			AuditLog::record(
				'stonewright/write-freeze-setting',
				[
					'_meta' => [
						'operation_class'  => 'write_freeze_setting',
						'resource_type'    => 'option',
						'resource_ref'     => 'write_freeze',
						'execution_status' => 'executed',
					],
					'setting' => 'write_freeze',
					'from'    => $was > 0 ? gmdate( 'c', $was ) : '',
					'to'      => $now > 0 ? gmdate( 'c', $now ) : '',
				],
				'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	/**
	 * Audits the first time the freeze is saved (add_option_{option} action).
	 *
	 * @param mixed $option The option name.
	 * @param mixed $value  The value saved.
	 */
	public static function audit_added( mixed $option, mixed $value ): void {
		unset( $option );
		self::audit_change( [], $value );
	}

	/** A date and time as the control posts it, read in the site's timezone; null when it is not one. */
	private static function parse_local_time( string $field ): ?int {
		$date = \DateTimeImmutable::createFromFormat( '!' . self::FIELD_FORMAT, $field, wp_timezone() );
		if ( false === $date || $date->format( self::FIELD_FORMAT ) !== $field ) {
			return null;
		}

		return $date->getTimestamp();
	}

	private static function refuse( string $message ): void {
		add_settings_error( self::ERRORS, WriteFreeze::OPTION, $message, 'error' );
	}
}
