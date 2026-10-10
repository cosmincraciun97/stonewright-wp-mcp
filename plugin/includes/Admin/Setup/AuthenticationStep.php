<?php
/**
 * Step 2: choose OAuth or an Application Password.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;

/**
 * Step 2 of Setup: the method chooser, and for the chosen method its explanation. OAuth needs no password; the
 * Application Password route has the form that makes one (it works without script) and the list that revokes one.
 * The panels of both methods are in the page; the chosen one is shown and the other hidden, and the page script
 * switches them without a request.
 */
final class AuthenticationStep {

	public const ID = 'stonewright-application-password';

	public static function html( SetupContext $context ): string {
		$issues  = SignInPanel::issues_html( $context->oauth_status );
		$body    = $issues . self::choices( $context ) . self::oauth_panel( $context ) . self::password_panel( $context );

		return Step::html(
			2,
			__( 'Choose how clients sign in', 'stonewright' ),
			$context->step_states[2],
			$body,
			self::ID
		);
	}

	private static function choices( SetupContext $context ): string {
		$oauth_badge = $context->oauth_available
			? Badge::render( __( 'Recommended for your setup', 'stonewright' ), [ 'variant' => 'accent' ] )
			: Badge::render( __( 'Not available on this site', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] );

		$oauth = Html::element(
			'button',
			[
				'type'                        => 'button',
				'role'                        => 'radio',
				'class'                       => Html::classes( 'sw-ui-choice', $context->oauth_selected ? 'is-active' : '' ),
				'data-stonewright-auth-method' => 'oauth',
				'aria-checked'                => $context->oauth_selected ? 'true' : 'false',
				'disabled'                    => $context->oauth_available ? null : true,
			],
			Html::element( 'span', [ 'class' => 'sw-ui-choice__title' ], Html::text( __( 'OAuth', 'stonewright' ) ) . $oauth_badge )
			. Html::element( 'span', [ 'class' => 'sw-ui-choice__text' ], Html::text( __( 'Sign in through the browser; no password to copy.', 'stonewright' ) ) )
		);
		$password = Html::element(
			'button',
			[
				'type'                        => 'button',
				'role'                        => 'radio',
				'class'                       => Html::classes( 'sw-ui-choice', $context->oauth_selected ? '' : 'is-active' ),
				'data-stonewright-auth-method' => 'application-password',
				'aria-checked'                => $context->oauth_selected ? 'false' : 'true',
			],
			Html::element( 'span', [ 'class' => 'sw-ui-choice__title' ], Html::text( __( 'Application Password', 'stonewright' ) ) )
			. Html::element( 'span', [ 'class' => 'sw-ui-choice__text' ], Html::text( __( 'Generate a password and paste it into the client config.', 'stonewright' ) ) )
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-choices', 'role' => 'radiogroup', 'aria-label' => __( 'Authentication method', 'stonewright' ) ],
			$oauth . $password
		);
	}

	private static function oauth_panel( SetupContext $context ): string {
		$connections = Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => '#' . SignInPanel::CONNECTIONS_ID ], Html::text( __( 'Review connected OAuth clients', 'stonewright' ) ) );
		$address     = Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => SetupTabs::url( 'connections', [], 'sw-connect-status' ) ], Html::text( __( 'Copy the MCP server URL', 'stonewright' ) ) );

		return Html::element(
			'div',
			[ 'data-stonewright-auth-panel' => 'oauth', 'hidden' => $context->oauth_selected ? null : true, 'class' => 'sw-ui-stack' ],
			Html::element( 'p', [], Html::text( __( 'OAuth needs no password. In step 3, open your client, add the MCP server URL and approve the request when the client opens your browser.', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-actions' ], $address . $connections )
		);
	}

	private static function password_panel( SetupContext $context ): string {
		$html = Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Generate one WordPress Application Password for your AI client. It can appear in the private client snippet in step 3 and is shown only once. The paste-to-agent prompt stays credential-free.', 'stonewright' ) ) );

		if ( 'app_password_revoked' === $context->app_password_status ) {
			$html .= Notice::render( 'ok', __( 'Application Password revoked.', 'stonewright' ) );
		} elseif ( 'app_password_error' === $context->app_password_status ) {
			$html .= Notice::render( 'danger', __( 'The Application Password request could not be completed. Check the form and try again.', 'stonewright' ) );
		}

		if ( ApplicationPasswords::available() ) {
			$html .= self::form()
				. Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => admin_url( 'profile.php#application-passwords-section' ) ], Html::text( __( 'I already have an application password', 'stonewright' ) ) )
				. ApplicationPasswords::list_html( $context->app_passwords );
		} else {
			$html .= Notice::callout( 'warn', '', __( 'Application Passwords are not available for this user or site. Enable HTTPS or create one from your WordPress profile.', 'stonewright' ) )
				. Button::group(
					[ Button::render( __( 'Open WordPress profile', 'stonewright' ), [ 'href' => admin_url( 'profile.php#application-passwords-section' ), 'new_tab' => true ] ) ]
				);
		}

		return Html::element(
			'div',
			[ 'data-stonewright-auth-panel' => 'application-password', 'hidden' => $context->oauth_selected ? true : null, 'class' => 'sw-ui-stack' ],
			$html
		);
	}

	private static function form(): string {
		$name = Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--md' ],
			Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => 'stonewright_app_password_name' ], Html::text( __( 'Name', 'stonewright' ) ) )
			. Html::void(
				'input',
				[
					'type'             => 'text',
					'class'            => 'sw-ui-input',
					'name'             => 'stonewright_app_password_name',
					'id'               => 'stonewright_app_password_name',
					'placeholder'      => __( 'e.g. Cursor on laptop, Claude Desktop', 'stonewright' ),
					'autocomplete'     => 'off',
					'required'         => true,
					'aria-required'    => 'true',
					'aria-describedby' => 'stonewright_app_password_name_help',
				]
			)
			. Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => 'stonewright_app_password_name_help' ], Html::text( __( 'Required. Use a clear client label so you can revoke it later.', 'stonewright' ) ) )
		);

		return Html::element(
			'form',
			[
				'method'                           => 'post',
				'action'                           => admin_url( 'admin-post.php' ),
				'class'                            => 'stonewright-app-password-form sw-ui-stack',
				'data-stonewright-app-password-form' => true,
				'data-rest-url'                    => rest_url( 'stonewright/v1/app-password' ),
				'data-rest-nonce'                  => wp_create_nonce( 'wp_rest' ),
			],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_generate_application_password' ] )
			. Nonce::field( 'stonewright_generate_application_password' )
			. $name
			. Html::element( 'div', [ 'data-stonewright-app-password-live' => true, 'class' => 'sw-ui-stack', 'role' => 'status', 'aria-live' => 'polite', 'hidden' => true ], '' )
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-actions' ],
				Button::render( __( 'Generate application password', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary', 'attrs' => [ 'data-stonewright-app-password-submit' => '1' ] ] )
				. Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'data-stonewright-app-password-nojs-hint' => true ], Html::text( __( 'Without JavaScript this navigates and shows the password once — copy it immediately.', 'stonewright' ) ) )
			)
		);
	}
}
