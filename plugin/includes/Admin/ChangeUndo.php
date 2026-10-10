<?php
/**
 * Undo and Redo on the Changes page: the confirmation dialog, the post action and the result notice.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\DiffView;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * Pressing Undo opens a dialog that shows the dry run of the rollback: the diff from what is live now to the before
 * image, the warnings (drift, newer changes, code) and what the restore does. The dialog is a form that posts to
 * admin-post.php with its own nonce. Only an administrator can post it, and the administrator who presses the button
 * is the approval that undoing code needs, so the handler passes human_approved. Everything else the engine
 * (ChangeRollback) decides: drift, the preview the person saw, the claim, the probe and the rows.
 *
 * Without script the Undo control is a link to the same page with `undo=1`, which prints the dialog open. With script
 * the layer opens the dialog as a modal. In production-safe mode the form carries a token issued for this change
 * and these options, and asks for the phrase ROLL BACK.
 *
 * A redo is the same dialog for the rollback row. The result is shown after the redirect, with a link to the new row.
 */
final class ChangeUndo {

	public const ACTION    = 'stonewright_change_rollback';
	public const DIALOG_ID = 'sw-changes-undo-dialog';
	public const PHRASE    = 'ROLL BACK';

	private const CAPABILITY = 'manage_options';
	private const TOKEN_TTL  = 300;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	// -----------------------------------------------------------------------------------------------
	// The control and the dialog
	// -----------------------------------------------------------------------------------------------

	/**
	 * The control for the footer of the drawer and the dialog it opens.
	 *
	 * @param array<string, mixed>  $row   The change the drawer shows.
	 * @param array<string, string> $carry The filters of the list, kept in the link.
	 * @return array{control:string,dialog:string}
	 */
	public static function parts( array $row, array $carry, bool $open ): array {
		$target = 'rollback' === (string) $row['kind'] ? $row : ( ChangeRollback::redo_target( $row ) ?? $row );
		$plan   = ChangeRollback::plan( (string) $target['change_id'] );
		if ( $plan instanceof \WP_Error ) {
			return [ 'control' => self::hint( $plan ), 'dialog' => '' ];
		}

		$redo  = 'redo' === $plan['kind'];
		$label = $redo ? __( 'Redo this change', 'stonewright' ) : __( 'Undo this change', 'stonewright' );
		$link  = Button::render(
			$label,
			[
				'variant' => 'danger',
				'href'    => ChangesPage::url( $carry + [ 'change' => (string) $row['change_id'], 'undo' => '1' ] ),
				'attrs'   => [ 'aria-haspopup' => 'dialog', 'data-sw-ui-dialog-open' => '#' . self::DIALOG_ID ],
			]
		);

		return [ 'control' => $link, 'dialog' => self::dialog( $row, $target, $plan, $label, $open ) ];
	}

	/** Why there is no Undo, as a sentence next to where it would be. */
	private static function hint( \WP_Error $error ): string {
		if ( 'stonewright_change_already_rolled_back' === $error->get_error_code() ) {
			$text = __( 'This change was rolled back and the rollback cannot be redone from here.', 'stonewright' );
		} else {
			$text = sprintf( /* translators: %s: why, a sentence */ __( 'Undo is not available for this change: %s', 'stonewright' ), $error->get_error_message() );
		}

		return Html::element( 'p', [ 'class' => 'sw-ui-hint' ], Html::text( $text ) );
	}

	/**
	 * @param array<string, mixed> $row    The change in the drawer.
	 * @param array<string, mixed> $target The row the run acts on.
	 * @param array<string, mixed> $plan   From ChangeRollback::plan().
	 */
	private static function dialog( array $row, array $target, array $plan, string $label, bool $open ): string {
		$id      = (string) $target['change_id'];
		$redo    = 'redo' === $plan['kind'];
		$guarded = Permissions::is_production_safe() && ! ProbeToken::is_probe_request();
		$short   = ChangeLabels::short_id( (string) $row['change_id'] );
		$prefix  = substr( (string) $plan['current_sha256'], 0, ChangeRollback::MIN_HASH_PREFIX );

		$title = sprintf( $redo ? /* translators: %s: short change id */ __( 'Redo change %s?', 'stonewright' ) : /* translators: %s: short change id */ __( 'Undo change %s?', 'stonewright' ), $short );
		$lead  = $redo
			? __( 'This applies the change again, then checks that the site loads. Anything saved to the item since is overwritten.', 'stonewright' )
			: __( 'This puts the item back as it was before the change, then checks that the site loads. Anything saved to the item since is overwritten.', 'stonewright' );

		$facts = KvList::render(
			[
				[ 'label' => __( 'What changed', 'stonewright' ), 'value_html' => ChangeLabels::resource_html( $row ) ],
				[ 'label' => __( 'Changed by', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $row['ability'] ) ) ],
				[ 'label' => __( 'This does', 'stonewright' ), 'value' => (string) $plan['would_apply'] ],
			],
			[ 'label' => __( 'What this does', 'stonewright' ) ]
		);

		$warnings = '';
		if ( ! empty( $plan['approval_required'] ) ) {
			$warnings .= Notice::callout( 'info', __( 'This puts code back', 'stonewright' ), __( 'The code is written through the same checks as any code change. Pressing the button is the approval of an administrator that an agent cannot give.', 'stonewright' ) );
		}
		foreach ( $plan['warnings'] as $warning ) {
			$warnings .= Notice::callout( 'warn', (string) $warning );
		}
		$confirm = '';
		if ( ! empty( $plan['requires_force'] ) ) {
			$confirm .= FormField::checkbox( __( 'I understand that this overwrites changes made since.', 'stonewright' ), 'force_drift', [ 'required' => true ] );
		}

		$fields = FormField::hidden( 'action', self::ACTION )
			. FormField::hidden( 'change_id', $id )
			. FormField::hidden( 'expected_current_sha256', $prefix )
			. FormField::hidden( '_stonewright_nonce', wp_create_nonce( self::ACTION ) );
		if ( $guarded ) {
			$fields .= FormField::hidden( 'confirmation_token', ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'expected_current_sha256' => $prefix ] ), self::TOKEN_TTL ) );
			if ( ! empty( $plan['requires_force'] ) ) {
				$fields .= FormField::hidden( 'confirmation_token_force', ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'expected_current_sha256' => $prefix, 'force_drift' => true ] ), self::TOKEN_TTL ) );
			}
			$confirm .= Html::element(
				'div',
				[ 'class' => 'sw-ui-field' ],
				Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'sw-changes-undo-phrase' ], Html::text( sprintf( /* translators: %s: the phrase to type */ __( 'Type %s to confirm', 'stonewright' ), self::PHRASE ) ) )
				. Html::void( 'input', [ 'class' => 'sw-ui-input', 'type' => 'text', 'id' => 'sw-changes-undo-phrase', 'name' => 'confirm_phrase', 'value' => '', 'autocomplete' => 'off', 'data-sw-ui-confirm-phrase' => self::PHRASE ] )
			);
		}

		$body = Html::element( 'p', [], Html::text( $lead ) )
			. $facts
			. $warnings
			. self::diff( $plan )
			. $fields
			. $confirm
			. Html::element( 'p', [ 'class' => 'sw-ui-hint', 'role' => 'status', 'data-sw-changes-status' => true ], '' );

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'data-sw-changes-undo-form' => true ],
			Html::element( 'div', [ 'class' => 'sw-ui-dialog__header' ], Html::element( 'h2', [ 'class' => 'sw-ui-dialog__title', 'id' => 'sw-changes-undo-title' ], Html::text( $title ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-dialog__body' ], $body )
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-dialog__footer' ],
				Button::render( __( 'Cancel', 'stonewright' ), [ 'attrs' => [ 'data-sw-ui-dialog-close' => true, 'autofocus' => true ] ] )
				. Button::render(
					$label,
					[
						'type'    => 'submit',
						'variant' => 'danger-solid',
						'disabled' => $guarded,
						'attrs'   => array_merge( [ 'data-sw-changes-submit' => true, 'data-sw-busy-label' => $redo ? __( 'Redoing and checking the site...', 'stonewright' ) : __( 'Undoing and checking the site...', 'stonewright' ) ], $guarded ? [ 'data-sw-ui-confirm-submit' => true ] : [] ),
					]
				)
			)
		);

		return Html::element(
			'dialog',
			[ 'id' => self::DIALOG_ID, 'class' => 'sw-ui-dialog sw-changes-undo', 'aria-labelledby' => 'sw-changes-undo-title', 'open' => $open ],
			$form
		);
	}

	/**
	 * The dry-run diff, or why there is none.
	 *
	 * @param array<string, mixed> $plan
	 */
	private static function diff( array $plan ): string {
		$diff = $plan['diff'];
		if ( 'ok' !== $diff['status'] ) {
			return Notice::callout( 'info', __( 'There is no diff to show', 'stonewright' ), (string) $diff['message'] );
		}
		$html = '';
		foreach ( $diff['sections'] as $section ) {
			$html .= DiffView::render( $section['result'], [ 'title' => $section['title'] ] );
		}

		return Html::element( 'div', [ 'class' => 'sw-changes-diff' ], $html );
	}

	// -----------------------------------------------------------------------------------------------
	// The request
	// -----------------------------------------------------------------------------------------------

	public static function handle(): void {
		$url = self::redirect_url( self::process_request() );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Run the rollback the dialog asked for. Ends the request without a nonce or without manage_options. Runs with
	 * human_approved: the administrator who posted the form is the approval that code needs.
	 *
	 * @return array{code:string,change_id:string,new_id:string}
	 */
	public static function process_request(): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_stonewright_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_stonewright_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line.
		if ( false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}

		$id = self::posted( 'change_id' );
		if ( ! ChangeLedger::is_valid_id( $id ) ) {
			return [ 'code' => 'not_found', 'change_id' => '', 'new_id' => '' ];
		}
		$force = '1' === self::posted( 'force_drift' );
		$token = $force && '' !== self::posted( 'confirmation_token_force' ) ? self::posted( 'confirmation_token_force' ) : self::posted( 'confirmation_token' );

		$result = ChangeRollback::run(
			$id,
			[
				'by'                      => 'admin-page',
				'actor'                   => (int) get_current_user_id(),
				'human_approved'          => true,
				'force_drift'             => $force,
				'expected_current_sha256' => self::posted( 'expected_current_sha256' ),
				'confirmation_token'      => $token,
			]
		);

		if ( $result instanceof \WP_Error ) {
			$data = is_array( $result->get_error_data() ) ? $result->get_error_data() : [];
			return [ 'code' => self::error_code( (string) $result->get_error_code() ), 'change_id' => $id, 'new_id' => self::valid_id( (string) ( $data['rollback_change_id'] ?? '' ) ) ];
		}

		$code = 'noop' === ( $result['rollback_status'] ?? '' )
			? 'unchanged'
			: ( 'redo' === $result['kind'] ? 'redone' : 'undone' ) . match ( (string) ( $result['site_status'] ?? '' ) ) {
				'healthy'       => '',
				'still_failing' => '_still_failing',
				default         => '_unverified',
			};

		return [ 'code' => $code, 'change_id' => $id, 'new_id' => self::valid_id( (string) ( $result['rollback_change_id'] ?? '' ) ) ];
	}

	/**
	 * Where the request goes next: the new row when there is one, with the outcome in the address.
	 *
	 * @param array{code:string,change_id:string,new_id:string} $outcome
	 */
	public static function redirect_url( array $outcome ): string {
		$shown = '' !== $outcome['new_id'] ? $outcome['new_id'] : $outcome['change_id'];

		return ChangesPage::url( [ 'change' => $shown, 'undone' => $outcome['code'], 'from' => $outcome['change_id'] ] );
	}

	private static function posted( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read after the nonce check in process_request().
	}

	private static function valid_id( string $id ): string {
		return ChangeLedger::is_valid_id( $id ) ? $id : '';
	}

	private static function error_code( string $code ): string {
		return match ( true ) {
			in_array( $code, [ 'stonewright_change_not_found', 'stonewright_change_invalid_id' ], true )            => 'not_found',
			in_array( $code, [ 'stonewright_change_family_unsupported', 'stonewright_change_kind_unsupported' ], true ) => 'unsupported',
			'stonewright_change_not_restorable' === $code                                                          => 'not_restorable',
			'stonewright_change_already_rolled_back' === $code                                                     => 'already_rolled_back',
			'stonewright_change_already_restored' === $code                                                        => 'already_restored',
			'stonewright_change_drift' === $code                                                                   => 'drift',
			'stonewright_change_changed_since_preview' === $code                                                   => 'changed_since_preview',
			'stonewright_change_in_progress' === $code                                                             => 'in_progress',
			'stonewright_confirmation_required' === $code                                                          => 'confirmation_required',
			str_starts_with( $code, 'stonewright_confirmation_' )                                                  => 'confirmation_invalid',
			'stonewright_change_rollback_reverted' === $code                                                       => 'reverted',
			'stonewright_change_revert_failed' === $code                                                           => 'revert_failed',
			in_array( $code, [ 'stonewright_change_live_unreadable', 'stonewright_change_image_unreadable' ], true) => 'unreadable',
			default                                                                                                => 'failed',
		};
	}

	// -----------------------------------------------------------------------------------------------
	// The result
	// -----------------------------------------------------------------------------------------------

	/**
	 * Outcome code => variant, title and text, and whether a row of the history carries the outcome.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	private static function messages(): array {
		return [
			'undone'                => [ 'ok', __( 'The change was undone', 'stonewright' ), __( 'The item is as it was before the change, and the site loads.', 'stonewright' ) ],
			'redone'                => [ 'ok', __( 'The change was redone', 'stonewright' ), __( 'The change is in effect again, and the site loads.', 'stonewright' ) ],
			'undone_unverified'     => [ 'warn', __( 'The change was undone', 'stonewright' ), __( 'The health check afterwards was unavailable, so it is not confirmed that the site loads.', 'stonewright' ) ],
			'redone_unverified'     => [ 'warn', __( 'The change was redone', 'stonewright' ), __( 'The health check afterwards was unavailable, so it is not confirmed that the site loads.', 'stonewright' ) ],
			'undone_still_failing'  => [ 'warn', __( 'The change was undone', 'stonewright' ), __( 'The site was failing before and still fails to load, so the fault may not come from this change.', 'stonewright' ) ],
			'redone_still_failing'  => [ 'warn', __( 'The change was redone', 'stonewright' ), __( 'The site was failing before and still fails to load, so the fault may not come from this change.', 'stonewright' ) ],
			'unchanged'             => [ 'info', __( 'Nothing was changed', 'stonewright' ), __( 'The item already was in that state.', 'stonewright' ) ],
			'reverted'              => [ 'danger', __( 'The site failed its health check, so the earlier state was put back', 'stonewright' ), __( 'The earlier state was put back. The change is still in effect. The attempt is in the history.', 'stonewright' ) ],
			'revert_failed'         => [ 'danger', __( 'The site failed its health check and the earlier state could not be put back', 'stonewright' ), __( 'Check the site now. Safe mode on the Rescue page can help.', 'stonewright' ) ],
			'failed'                => [ 'danger', __( 'The rollback did not complete', 'stonewright' ), __( 'The change is still in effect unless the history says otherwise.', 'stonewright' ) ],
			'drift'                 => [ 'warn', __( 'Nothing was changed', 'stonewright' ), __( 'The item has changed since the recorded change. Open the change again, review the diff, and tick the box to overwrite those edits.', 'stonewright' ) ],
			'changed_since_preview' => [ 'warn', __( 'Nothing was changed', 'stonewright' ), __( 'The item changed while the dialog was open. Open the change again and review the diff.', 'stonewright' ) ],
			'confirmation_required' => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'Production-safe mode needs the confirmation on the form. Open the change again and try again.', 'stonewright' ) ],
			'confirmation_invalid'  => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'The confirmation expired or does not match. Open the change again and try again.', 'stonewright' ) ],
			'already_rolled_back'   => [ 'info', __( 'Nothing was changed', 'stonewright' ), __( 'This change was already rolled back. Redo the rollback instead.', 'stonewright' ) ],
			'already_restored'      => [ 'info', __( 'Nothing was changed', 'stonewright' ), __( 'The item already is as it was before the change.', 'stonewright' ) ],
			'in_progress'           => [ 'info', __( 'Nothing was changed', 'stonewright' ), __( 'A rollback of this item is already running. Reload this page in a minute.', 'stonewright' ) ],
			'not_restorable'        => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'This change cannot be undone from the history.', 'stonewright' ) ],
			'unsupported'           => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'Changes of this kind cannot be undone from the history yet.', 'stonewright' ) ],
			'unreadable'            => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'The current state or the stored content of this change cannot be read.', 'stonewright' ) ],
			'not_found'             => [ 'danger', __( 'Nothing was changed', 'stonewright' ), __( 'No change in the history has that id.', 'stonewright' ) ],
		];
	}

	/**
	 * The notice for the outcome in the address, with a link to the new row and the receipt. Empty for an unknown code.
	 *
	 * @param array<string, mixed> $query The address arguments: undone, from and change.
	 */
	public static function outcome_notice( array $query ): string {
		$code     = isset( $query['undone'] ) && is_scalar( $query['undone'] ) ? sanitize_key( (string) wp_unslash( (string) $query['undone'] ) ) : '';
		$messages = self::messages();
		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return '';
		}
		[ $variant, $title, $text ] = $messages[ $code ];
		$from  = self::valid_id( isset( $query['from'] ) && is_scalar( $query['from'] ) ? trim( (string) wp_unslash( (string) $query['from'] ) ) : '' );
		$shown = self::valid_id( isset( $query['change'] ) && is_scalar( $query['change'] ) ? trim( (string) wp_unslash( (string) $query['change'] ) ) : '' );
		$new   = '' !== $shown && $shown !== $from ? $shown : '';

		$actions = '';
		if ( '' !== $new ) {
			$actions .= Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => ChangesPage::url( [ 'change' => $new ] ) ], Html::text( __( 'View the new change', 'stonewright' ) ) );
		}
		$receipt = '' !== $new ? $new : $from;
		if ( '' !== $receipt ) {
			$audit    = add_query_arg( [ 'page' => AuditLogPage::SLUG, 'change_set_id' => $receipt, 'ability' => ChangeRollback::ABILITY ], admin_url( 'admin.php' ) );
			$actions .= Html::element( 'span', [], Html::text( __( 'Receipt', 'stonewright' ) ) . ' ' . Html::element( 'code', [], Html::text( $receipt ) ) )
				. Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => $audit ], Html::text( __( 'View in Audit log', 'stonewright' ) ) );
		}

		return Notice::render( $variant, $title, $text, [ 'actions_html' => $actions ] );
	}
}
