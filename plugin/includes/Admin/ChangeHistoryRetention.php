<?php
/**
 * What the admin says about how long the change history is kept, and the settings that change it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\UtcTime;
use Stonewright\WpMcp\Security\AccessDenied;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;

/**
 * Three pieces over ChangeLedgerRetention, which decides and does everything:
 * - the always-visible line on the History tab of Changes (the limits, what is kept, the last cleanup) and the
 *   advance warning above it, both read-only;
 * - the "Change history" section of Setup > Settings, three number fields (days, changes, MB) that write the three
 *   existing options through the Settings API form of the page;
 * - the server-side check of those fields.
 *
 * The check is the bounds of ChangeLedgerRetention: a whole number from 1 up to 3650 days, 100,000 changes or
 * 10,240 MB. A value outside them is refused with a message and the stored value stays. There is no "keep forever":
 * the size limit protects the disk of the host, and the ceilings already allow very long retention.
 *
 * options.php verifies the settings nonce and manage_options before it calls a sanitizer. The sanitizers of the
 * three options verify both again for a post of the settings form and answer 403 when either is missing, so the
 * rule holds when they are reached any other way. Code that sets an option directly (a migration, WP-CLI) is not a
 * form post and is only checked against the bounds.
 */
final class ChangeHistoryRetention {

	/** The settings-errors slug of the refusals. */
	public const ERRORS = 'stonewright_change_history';

	/** The settings form posts the size in megabytes under this name; it is not an option. */
	public const FIELD_MB = 'stonewright_change_ledger_max_mb';

	public const SECTION_ID = 'stonewright-change-history';

	private const BYTES_PER_MB = 1048576;

	private const MAX_MB = 10240;

	// -----------------------------------------------------------------------------------------------
	// Registration and the server-side check
	// -----------------------------------------------------------------------------------------------

	/** The three options join the Settings API group of Setup, so one form and one nonce save them. */
	public static function register_settings(): void {
		foreach (
			[
				ChangeLedgerRetention::DAYS_OPTION        => 'sanitize_days',
				ChangeLedgerRetention::MAX_CHANGES_OPTION => 'sanitize_max_changes',
				ChangeLedgerRetention::MAX_BYTES_OPTION   => 'sanitize_max_bytes',
			] as $option => $method
		) {
			register_setting(
				ConfigurationPage::OPTION_GROUP,
				$option,
				[
					'type'              => 'integer',
					'sanitize_callback' => [ self::class, $method ],
					'show_in_rest'      => false,
				]
			);
		}
	}

	/** @return int|false The days to store, or false to leave an unset option unset. */
	public static function sanitize_days( mixed $value ): int|false {
		self::guard();

		return self::accept(
			ChangeLedgerRetention::DAYS_OPTION,
			ChangeLedgerRetention::DEFAULT_DAYS,
			self::whole( $value ),
			ChangeLedgerRetention::MAX_DAYS,
			__( 'Keep finished changes for (days)', 'stonewright' )
		);
	}

	/** @return int|false The count to store, or false to leave an unset option unset. */
	public static function sanitize_max_changes( mixed $value ): int|false {
		self::guard();

		return self::accept(
			ChangeLedgerRetention::MAX_CHANGES_OPTION,
			ChangeLedgerRetention::DEFAULT_MAX_CHANGES,
			self::whole( $value ),
			ChangeLedgerRetention::MAX_MAX_CHANGES,
			__( 'Keep at most (changes)', 'stonewright' )
		);
	}

	/**
	 * The size is posted in megabytes by the settings form and arrives in bytes from code. A form that does not
	 * carry the field, or shows the stored size unchanged, leaves the option as it is.
	 *
	 * @return int|false The bytes to store, or false to leave an unset option unset.
	 */
	public static function sanitize_max_bytes( mixed $value ): int|false {
		self::guard();
		$label = __( 'Keep at most (MB of stored copies)', 'stonewright' );
		if ( ! self::in_settings_post() ) {
			return self::accept( ChangeLedgerRetention::MAX_BYTES_OPTION, ChangeLedgerRetention::DEFAULT_MAX_BYTES, self::whole( $value ), ChangeLedgerRetention::MAX_MAX_BYTES, $label, self::MAX_MB );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the settings nonce.
		$posted = $_POST[ self::FIELD_MB ] ?? null;
		if ( null === $posted ) {
			return self::keep( ChangeLedgerRetention::MAX_BYTES_OPTION );
		}
		$megabytes = self::whole( is_string( $posted ) ? wp_unslash( $posted ) : $posted );
		if ( null === $megabytes || $megabytes < 1 || $megabytes > self::MAX_MB ) {
			return self::refuse( ChangeLedgerRetention::MAX_BYTES_OPTION, $label, self::MAX_MB );
		}
		if ( self::megabytes( ChangeLedgerRetention::stored_max_bytes() ) === $megabytes ) {
			return self::keep( ChangeLedgerRetention::MAX_BYTES_OPTION );
		}

		return $megabytes * self::BYTES_PER_MB;
	}

	/**
	 * Stops a post of the settings form that has no manage_options or no valid nonce with a 403. Other callers
	 * (code, WP-CLI, cron) are not form posts and pass.
	 */
	private static function guard(): void {
		if ( ! self::in_settings_post() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce check.
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, ConfigurationPage::OPTION_GROUP . '-options' ) ) {
			AccessDenied::deny( esc_html__( 'Sorry, you are not allowed to change the change history limits.', 'stonewright' ) );
		}
	}

	private static function in_settings_post(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only names the form; guard() verifies the nonce.
		return isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] ) && ConfigurationPage::OPTION_GROUP === sanitize_key( wp_unslash( $_POST['option_page'] ) );
	}

	/** A whole, non-negative number, or null for anything else (a sign, a fraction, text, an array). */
	private static function whole( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*(\d{1,12})\s*$/D', $value, $found ) ) {
			return (int) $found[1];
		}

		return null;
	}

	/**
	 * The value to store for a number in 1..$max, a refusal with the stored value kept, or false when the option
	 * was never set and the number is the default, so a save of the form does not pin a default.
	 *
	 * @param int $max   The largest value that can be stored.
	 * @param int $shown The largest value the message names, in the unit of the field.
	 */
	private static function accept( string $option, int $default, ?int $number, int $max, string $label, ?int $shown = null ): int|false {
		if ( null === $number || $number < 1 || $number > $max ) {
			return self::refuse( $option, $label, $shown ?? $max );
		}
		$stored = $number;
		if ( $stored === $default && false === get_option( $option, false ) ) {
			return false;
		}

		return $stored;
	}

	/** @return int|false The option as it is stored, false when it is not set. */
	private static function keep( string $option ): int|false {
		$stored = get_option( $option, false );

		return is_int( $stored ) ? $stored : ( is_numeric( $stored ) ? (int) $stored : false );
	}

	/** @return int|false What keep() returns. */
	private static function refuse( string $option, string $label, int $ceiling ): int|false {
		add_settings_error(
			self::ERRORS,
			$option,
			sprintf(
				/* translators: 1: field label, 2: the largest value allowed */
				__( '%1$s: enter a whole number from 1 to %2$s. The previous value was kept.', 'stonewright' ),
				$label,
				number_format_i18n( $ceiling )
			),
			'error'
		);

		return self::keep( $option );
	}

	private static function megabytes( int $bytes ): int {
		return max( 1, (int) round( $bytes / self::BYTES_PER_MB ) );
	}

	// -----------------------------------------------------------------------------------------------
	// Changes > History: the line and the advance warning
	// -----------------------------------------------------------------------------------------------

	/** The address of the section in Setup > Settings. */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=' . ConfigurationPage::SLUG . '&tab=settings' ) . '#' . self::SECTION_ID;
	}

	/**
	 * The line that is always on the History tab: the limits in force, what goes and what stays, the last cleanup,
	 * and a link to the setting for those who can change it.
	 */
	public static function info_html(): string {
		$text = sprintf(
			/* translators: 1: days, 2: number of changes with the word, 3: size */
			__( 'Finished changes are kept for up to %1$s, %2$s and %3$s of stored copies, whichever limit is reached first. Older finished changes are removed automatically each day. Open incidents and linked Undo and Redo are kept.', 'stonewright' ),
			sprintf( /* translators: %s: number of days */ _n( '%s day', '%s days', ChangeLedgerRetention::days(), 'stonewright' ), number_format_i18n( ChangeLedgerRetention::days() ) ),
			sprintf( /* translators: %s: number of changes */ _n( '%s change', '%s changes', ChangeLedgerRetention::max_changes(), 'stonewright' ), number_format_i18n( ChangeLedgerRetention::max_changes() ) ),
			self::size( ChangeLedgerRetention::max_bytes() )
		);
		$link = current_user_can( ChangesPage::CAPABILITY )
			? ' ' . Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => self::settings_url() ], Html::text( __( 'Change limits', 'stonewright' ) ) )
			: '';

		return Html::element(
			'p',
			[ 'class' => 'sw-ui-field__help sw-changes-retention' ],
			Html::text( $text ) . ' ' . self::last_cleanup_html() . $link
		);
	}

	/** "Last cleanup: none yet", or its date and the number of changes it removed. */
	private static function last_cleanup_html(): string {
		$receipt = ChangeLedgerRetention::last_receipt();
		$label   = Html::text( __( 'Last cleanup:', 'stonewright' ) ) . ' ';
		if ( null === $receipt || 'unavailable' === ( $receipt['status'] ?? '' ) ) {
			return $label . Html::text( __( 'none yet', 'stonewright' ) ) . '.';
		}
		$when    = UtcTime::render( gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $receipt['run_at'] ) ), 'M j, Y' );
		$removed = max( 0, (int) ( $receipt['deleted_rows'] ?? 0 ) );
		$count   = Html::text( sprintf( /* translators: %s: number of changes */ _n( '%s change removed', '%s changes removed', $removed, 'stonewright' ), number_format_i18n( $removed ) ) );
		$stopped = 'failed' === ( $receipt['status'] ?? '' ) ? ', ' . Html::text( __( 'stopped by an error', 'stonewright' ) ) : '';

		return $label . $when . $stopped . ', ' . $count . '.';
	}

	/**
	 * A warning, not an error, when the next cleanups will remove changes: how many and by which date, or that a
	 * limit is close. Empty when nothing is about to go. It reads through the same plan the cleanup runs.
	 */
	public static function warning_html(): string {
		$forecast = ChangeLedgerRetention::forecast();
		if ( ! $forecast['show'] ) {
			return '';
		}

		if ( $forecast['would_remove'] > 0 && null !== $forecast['by'] ) {
			$when = UtcTime::render( gmdate( 'Y-m-d H:i:s', $forecast['by'] ), 'M j, Y' );
			$text = sprintf(
				esc_html(
					/* translators: 1: number of changes, 2: a date */
					_n( '%1$d finished change will be removed by %2$s to keep the history within its limits.', '%1$d finished changes will be removed by %2$s to keep the history within its limits.', $forecast['would_remove'], 'stonewright' )
				),
				$forecast['would_remove'],
				$when
			);
			$title =__( 'Older changes are about to be removed', 'stonewright' );
		} else {
			$parts = [];
			if ( in_array( 'changes', $forecast['reasons'], true ) ) {
				$parts[] = sprintf(
					/* translators: 1: percent, 2: limit with the word */
					__( 'finished changes are at %1$d%% of the limit of %2$s', 'stonewright' ),
					$forecast['changes_percent'],
					sprintf( /* translators: %s: number of changes */ _n( '%s change', '%s changes', $forecast['max_changes'], 'stonewright' ), number_format_i18n( $forecast['max_changes'] ) )
				);
			}
			if ( in_array( 'bytes', $forecast['reasons'], true ) ) {
				$parts[] = sprintf(
					/* translators: 1: percent, 2: size limit */
					__( 'stored copies are at %1$d%% of the limit of %2$s', 'stonewright' ),
					$forecast['bytes_percent'],
					self::size( $forecast['max_bytes'] )
				);
			}
			$text  = Html::text( ucfirst( implode( '; ', $parts ) ) . '. ' . __( 'When a limit is passed, the oldest finished changes are removed automatically.', 'stonewright' ) );
			$title = __( 'The change history is nearly full', 'stonewright' );
		}

		return Notice::render(
			'warn',
			$title,
			'',
			[
				'text_html'    => $text,
				'actions_html' => current_user_can( ChangesPage::CAPABILITY ) ? Button::render( __( 'Change limits', 'stonewright' ), [ 'size' => 'sm', 'href' => self::settings_url() ] ) : '',
				'class'        => 'sw-changes-retention-warning',
			]
		);
	}

	// -----------------------------------------------------------------------------------------------
	// Setup > Settings: the section
	// -----------------------------------------------------------------------------------------------

	/** The "Change history" card of the settings form: three number fields and what a save does. */
	public static function settings_html(): string {
		$rows = '<table class="sw-ui-form-table" role="presentation"><tbody>'
			. self::number_row(
				ChangeLedgerRetention::DAYS_OPTION,
				__( 'Keep finished changes for (days)', 'stonewright' ),
				ChangeLedgerRetention::stored_days(),
				ChangeLedgerRetention::MAX_DAYS,
				/* translators: 1: the largest value, 2: the default */
				sprintf( __( 'From 1 to %1$s. Default %2$s.', 'stonewright' ), number_format_i18n( ChangeLedgerRetention::MAX_DAYS ), number_format_i18n( ChangeLedgerRetention::DEFAULT_DAYS ) )
			)
			. self::number_row(
				ChangeLedgerRetention::MAX_CHANGES_OPTION,
				__( 'Keep at most (changes)', 'stonewright' ),
				ChangeLedgerRetention::stored_max_changes(),
				ChangeLedgerRetention::MAX_MAX_CHANGES,
				/* translators: 1: the largest value, 2: the default */
				sprintf( __( 'From 1 to %1$s. Default %2$s. Open changes are not counted.', 'stonewright' ), number_format_i18n( ChangeLedgerRetention::MAX_MAX_CHANGES ), number_format_i18n( ChangeLedgerRetention::DEFAULT_MAX_CHANGES ) )
			)
			. self::number_row(
				self::FIELD_MB,
				__( 'Keep at most (MB of stored copies)', 'stonewright' ),
				self::megabytes( ChangeLedgerRetention::stored_max_bytes() ),
				self::MAX_MB,
				/* translators: 1: the largest value, 2: the default */
				sprintf( __( 'From 1 to %1$s. Default %2$s.', 'stonewright' ), number_format_i18n( self::MAX_MB ), number_format_i18n( self::megabytes( ChangeLedgerRetention::DEFAULT_MAX_BYTES ) ) )
			)
			. '</tbody></table>';

		$notes = [
			__( 'The first limit reached decides. Open incidents and linked Undo and Redo are always kept.', 'stonewright' ),
			__( 'A lower limit takes effect at the next daily cleanup.', 'stonewright' ),
			__( 'There is no unlimited setting: the size limit protects the disk of this host, and the upper limits already allow very long retention.', 'stonewright' ),
		];
		$plan = ChangeLedgerRetention::dry_run();
		if ( $plan['available'] ) {
			$notes[] = 0 === $plan['would_remove']
				? __( 'With the limits in force now, the next cleanup would remove nothing.', 'stonewright' )
				: sprintf(
					/* translators: %d: number of changes */
					_n( 'With the limits in force now, the next cleanup would remove %d finished change.', 'With the limits in force now, the next cleanup would remove %d finished changes.', $plan['would_remove'], 'stonewright' ),
					$plan['would_remove']
				);
		}
		$overrides = self::filter_note();
		if ( '' !== $overrides ) {
			$notes[] = $overrides;
		}
		$paragraphs = '';
		foreach ( $notes as $note ) {
			$paragraphs .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( $note ) );
		}

		return Card::render(
			__( 'Change history', 'stonewright' ),
			$rows . $paragraphs,
			[
				'id'   => self::SECTION_ID,
				'desc' => __( 'How long the Changes page keeps finished changes. A cleanup runs once a day.', 'stonewright' ),
			]
		);
	}

	/** Names the limits a filter on this site sets, since the fields show only what the options hold. */
	private static function filter_note(): string {
		$differs = [];
		if ( ChangeLedgerRetention::days() !== ChangeLedgerRetention::stored_days() ) {
			$differs[] = sprintf( /* translators: %s: number of days */ _n( '%s day', '%s days', ChangeLedgerRetention::days(), 'stonewright' ), number_format_i18n( ChangeLedgerRetention::days() ) );
		}
		if ( ChangeLedgerRetention::max_changes() !== ChangeLedgerRetention::stored_max_changes() ) {
			$differs[] = sprintf( /* translators: %s: number of changes */ _n( '%s change', '%s changes', ChangeLedgerRetention::max_changes(), 'stonewright' ), number_format_i18n( ChangeLedgerRetention::max_changes() ) );
		}
		if ( ChangeLedgerRetention::max_bytes() !== ChangeLedgerRetention::stored_max_bytes() ) {
			$differs[] = self::size( ChangeLedgerRetention::max_bytes() );
		}

		return [] === $differs
			? ''
			: sprintf(
				/* translators: %s: the limits a filter sets, for example "14 days" */
				__( 'A filter on this site sets %s; the fields show the saved values.', 'stonewright' ),
				implode( ', ', $differs )
			);
	}

	private static function number_row( string $name, string $label, int $value, int $max, string $help ): string {
		$help_id = $name . '-help';
		$input   = Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--sm' ],
			Html::void(
				'input',
				[
					'class'            => 'sw-ui-input',
					'type'             => 'number',
					'name'             => $name,
					'id'               => $name,
					'value'            => (string) $value,
					'min'              => '1',
					'max'              => (string) $max,
					'step'             => '1',
					'inputmode'        => 'numeric',
					'aria-describedby' => $help_id,
				]
			)
		);

		return '<tr>'
			. Html::element( 'th', [ 'scope' => 'row' ], Html::element( 'label', [ 'for' => $name ], Html::text( $label ) ) )
			. Html::element( 'td', [], $input . Html::element( 'p', [ 'class' => 'sw-ui-field__help', 'id' => $help_id ], Html::text( $help ) ) )
			. '</tr>';
	}

	/** The notice that tells which limit a save refused, printed above the form after options.php returns. */
	public static function refusal_html(): string {
		$messages = '';
		foreach ( get_settings_errors( self::ERRORS ) as $error ) {
			$messages .= Html::element( 'p', [], Html::text( (string) $error['message'] ) );
		}
		if ( '' === $messages ) {
			return '';
		}

		return Notice::render(
			'danger',
			__( 'Change history limits were not saved', 'stonewright' ),
			'',
			[ 'text_html' => $messages ]
		);
	}

	private static function size( int $bytes ): string {
		return sprintf( /* translators: %s: number of megabytes */ __( '%s MB', 'stonewright' ), number_format_i18n( self::megabytes( $bytes ) ) );
	}
}
