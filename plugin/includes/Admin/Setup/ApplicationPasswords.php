<?php
/**
 * Application Password availability and the list of existing passwords.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Table;

/**
 * What Setup needs to know about the current user's Application Passwords, and the list that lets one be revoked.
 */
final class ApplicationPasswords {

	public static function available(): bool {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			$user = wp_get_current_user();
			return (bool) wp_is_application_passwords_available_for_user( $user );
		}

		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_current_user(): array {
		if ( ! self::available() ) {
			return [];
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! method_exists( '\WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			return [];
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		return is_array( $passwords ) ? array_values( $passwords ) : [];
	}

	/**
	 * The disclosure that lists existing Application Passwords with a revoke form each. The page script keeps the
	 * count in the summary and the rows up to date after a password is made or revoked.
	 *
	 * @param array<int, array<string, mixed>> $app_passwords
	 */
	public static function list_html( array $app_passwords ): string {
		$count   = count( $app_passwords );
		$summary = Icon::render( 'chev-r' ) . Html::element(
			'span',
			[ 'data-stonewright-app-password-count' => true ],
			Html::text(
				sprintf(
					/* translators: %d: Application Password count. */
					__( 'Manage existing application passwords (%d)', 'stonewright' ),
					$count
				)
			)
		);

		if ( [] === $app_passwords ) {
			$body = Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'No existing application passwords found for this user.', 'stonewright' ) ) );
		} else {
			$rows = [];
			foreach ( $app_passwords as $item ) {
				$name    = (string) ( $item['name'] ?? __( 'Unnamed', 'stonewright' ) );
				$uuid    = (string) ( $item['uuid'] ?? '' );
				$created = isset( $item['created'] ) ? (int) $item['created'] : 0;
				$date    = $created > 0 ? (string) wp_date( 'M j, Y g:i a', $created ) : __( 'Unknown', 'stonewright' );
				$rows[]  = [
					'name'    => $name,
					'created' => [ 'html' => $created > 0 ? Html::element( 'time', [ 'datetime' => gmdate( 'c', $created ) ], Html::text( $date ) ) : Html::text( $date ) ],
					'action'  => [ 'html' => '' !== $uuid ? self::revoke_form( $name, $uuid ) : Html::element( 'span', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Open profile to manage', 'stonewright' ) ) ) ],
				];
			}
			$body = Table::render(
				[
					[ 'key' => 'name', 'label' => __( 'Name', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'created', 'label' => __( 'Created', 'stonewright' ) ],
					[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => __( 'Existing application passwords', 'stonewright' ), 'class' => 'stonewright-app-password-table' ]
			);
		}

		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure stonewright-app-passwords-list' ],
			Html::element( 'summary', [], $summary ) . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $body )
		);
	}

	private static function revoke_form( string $name, string $uuid ): string {
		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'stonewright-inline-form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_revoke_application_password' ] )
			. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_app_password_uuid', 'value' => $uuid ] )
			. Nonce::field( 'stonewright_revoke_application_password_' . $uuid )
			. Button::render(
				__( 'Revoke', 'stonewright' ),
				[
					'type'    => 'submit',
					'variant' => 'danger',
					'size'    => 'sm',
					'context' => $name,
					'attrs'   => [ 'data-confirm' => __( 'Revoke this Application Password? The connected client will lose access immediately.', 'stonewright' ) ],
				]
			)
		);
	}
}
