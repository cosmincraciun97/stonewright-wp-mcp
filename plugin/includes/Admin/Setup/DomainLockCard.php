<?php
/**
 * The domain lock status and its recovery actions.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * The locked site address, a mismatch warning, and the rebind, restore and clear actions, with the result of
 * the last action.
 *
 * Stonewright records the site address again on every request while AI abilities are on, so a lock cleared while
 * they are on is set again before the page reloads. The clear action is therefore disabled with its reason while
 * they are on, and a result that shows a set lock says so instead of reporting a clear.
 */
final class DomainLockCard {

	public const ID = 'stonewright-domain-lock';

	public static function render(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	public static function html(): string {
		$status   = DomainLock::status();
		$locked   = (string) $status['locked'];
		$mismatch = ! $status['matches'];

		if ( $mismatch ) {
			$state = Badge::render( __( 'Mismatch, abilities blocked', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'alert' ] );
		} elseif ( '' !== $locked ) {
			$state = Badge::render( __( 'Locked', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'lock' ] );
		} else {
			$state = Badge::render( __( 'Not set', 'stonewright' ) );
		}

		$facts = [ [ 'label' => __( 'State', 'stonewright' ), 'value_html' => $state ] ];
		if ( '' !== $locked ) {
			$facts[] = [ 'label' => __( 'Locked address', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( $locked ) ) ];
		}
		if ( $mismatch ) {
			$facts[] = [ 'label' => __( 'Current address', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $status['current'] ) ) ];
		}

		$body = self::result() . KvList::render( $facts, [ 'label' => __( 'Domain lock', 'stonewright' ) ] );
		if ( '' === $locked && ! $mismatch ) {
			$body .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'No domain lock is set. Stonewright records this site\'s address when AI abilities are turned on.', 'stonewright' ) ) );
		}
		if ( $mismatch ) {
			$body .= Notice::callout(
				'warn',
				__( 'The site address changed', 'stonewright' ),
				__( 'AI abilities are blocked until you rebind this site or restore the previous address. Your choice to turn them on was not changed.', 'stonewright' )
			);
		}

		if ( $mismatch ) {
			$body .= self::rebind_form();
		}
		$body   = Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $body );
		$footer = self::rollback_form() . self::clear_form( $mismatch );

		return Card::render(
			__( 'Domain lock', 'stonewright' ),
			$body,
			[
				'id'          => self::ID,
				'desc'        => __( 'Stonewright records the address of this site when AI abilities are first turned on and blocks them if the address later changes, for example on a cloned copy.', 'stonewright' ),
				'footer_html' => '' !== $footer ? Html::element( 'div', [ 'class' => 'sw-ui-actions' ], $footer ) : '',
			]
		);
	}

	/** What the last lock action did, read from the query arguments of the redirect that brought the user here. */
	private static function result(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result selector after admin-post.php.
		if ( isset( $_GET['lock_reset'] ) ) {
			$locked = DomainLock::locked_domain();
			if ( '' === $locked ) {
				return Notice::render( 'ok', __( 'Domain lock cleared', 'stonewright' ), __( 'It stays unset until AI abilities are turned on again.', 'stonewright' ) );
			}

			return Notice::render(
				'info',
				__( 'Domain lock set again', 'stonewright' ),
				sprintf(
					/* translators: %s: the address of the site. */
					__( 'The lock was cleared and Stonewright recorded %s again, because AI abilities are on. Turn them off first to leave the lock unset.', 'stonewright' ),
					DomainLock::redact_origin( $locked )
				)
			);
		}
		if ( isset( $_GET['lock_rebind'] ) ) {
			return Notice::render( 'ok', __( 'Site address rebound', 'stonewright' ), __( 'The lock now holds the current address of this site. The previous binding can be restored for seven days.', 'stonewright' ) );
		}
		if ( isset( $_GET['lock_rollback'] ) ) {
			return Notice::render( 'ok', __( 'Previous site address restored', 'stonewright' ), __( 'The lock holds the address it had before the last rebind.', 'stonewright' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	private static function rebind_form(): string {
		$confirm_id = 'stonewright_rebind_confirm';

		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-ui-stack' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_rebind_domain_lock' ] )
			. Nonce::field( 'stonewright_rebind_domain_lock' )
			. Html::element(
				'label',
				[ 'class' => 'sw-ui-checkbox', 'for' => $confirm_id ],
				Html::void( 'input', [ 'type' => 'checkbox', 'name' => 'stonewright_rebind_confirm', 'id' => $confirm_id, 'value' => '1', 'required' => true ] )
				. Html::text( __( 'I confirm this is an intentional domain change for this WordPress site.', 'stonewright' ) )
			)
			. Button::group( [ Button::render( __( 'Review and rebind this site', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] )
		);
	}

	private static function rollback_form(): string {
		if ( ! DomainLock::can_rollback() ) {
			return '';
		}

		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ) ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_rollback_domain_lock' ] )
			. Nonce::field( 'stonewright_rollback_domain_lock' )
			. Button::render( __( 'Restore prior domain binding', 'stonewright' ), [ 'type' => 'submit' ] )
		);
	}

	private static function clear_form( bool $mismatch ): string {
		if ( $mismatch ) {
			return Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Clear domain lock is disabled during a mismatch. Rebind this site or restore the prior binding instead.', 'stonewright' ) ) );
		}
		if ( ! DomainLock::can_clear() ) {
			return '';
		}

		// While abilities are on the lock is recorded again on the next request, so clearing it cannot hold.
		$held    = PluginEffectiveState::enabled_requested();
		$hint_id = Html::unique_id( 'lock-hint' );
		$button  = Button::render(
			__( 'Clear domain lock', 'stonewright' ),
			[
				'type'     => 'submit',
				'disabled' => $held,
				'attrs'    => $held ? [ 'aria-describedby' => $hint_id ] : [],
			]
		);
		$hint    = $held
			? Html::element( 'p', [ 'class' => 'sw-ui-hint', 'id' => $hint_id ], Html::text( __( 'Stonewright sets the lock again as soon as it loads while AI abilities are on. Turn them off first to leave it unset.', 'stonewright' ) ) )
			: '';

		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-ui-actions' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_reset_domain_lock' ] )
			. Nonce::field( 'stonewright_reset_domain_lock' )
			. $button
			. $hint
		);
	}
}
