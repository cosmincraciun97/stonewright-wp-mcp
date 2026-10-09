<?php
/**
 * Step 3: connect an AI client.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Notice;

/**
 * Step 3 of Setup: for OAuth, one guide per AI client; for an Application Password, the client picker with its
 * snippets and the paste-to-agent prompt. The panel of the method chosen in step 2 is shown.
 */
final class ConnectStep {

	public static function html( SetupContext $context ): string {
		return Step::html(
			3,
			__( 'Connect your AI client', 'stonewright' ),
			$context->step_states[3],
			self::oauth_panel( $context ) . self::password_panel( $context )
		);
	}

	private static function oauth_panel( SetupContext $context ): string {
		ob_start();
		SignInPanel::render_guides( $context->oauth_status, $context->selected_client );

		return Html::element( 'div', [ 'data-stonewright-auth-panel' => 'oauth', 'hidden' => $context->oauth_selected ? null : true ], (string) ob_get_clean() );
	}

	private static function password_panel( SetupContext $context ): string {
		$intro   = Html::element( 'p', [], Html::text( __( 'Pick your AI client. Its snippet can carry the Application Password, so keep it in private configuration.', 'stonewright' ) ) );
		$picker  = ClientPicker::html( $context->username, $context->prompt_password, $context->selected_client, $context->selected_method );
		$warning = Notice::callout(
			'warn',
			__( 'Credentials stay local.', 'stonewright' ),
			__( 'Client snippets may contain the one-time Application Password so you can save it directly in private config. The paste-to-agent prompt always uses placeholders and must never carry a real credential.', 'stonewright' )
		);
		$library = Html::element(
			'p',
			[ 'class' => 'sw-ui-field__help' ],
			Html::element( 'a', [ 'href' => admin_url( 'admin.php?page=stonewright-prompts' ) ], Html::text( __( 'Open the Prompt library page', 'stonewright' ) ) )
			. Html::text( __( ' for searchable, outcome-grouped starters (20+ prompts).', 'stonewright' ) )
		);

		return Html::element(
			'div',
			[ 'data-stonewright-auth-panel' => 'application-password', 'hidden' => $context->oauth_selected ? true : null, 'class' => 'sw-ui-stack' ],
			$intro . $picker . $warning . self::prompt( $context ) . $library
		);
	}

	/** The paste-to-agent prompt: a short preview, with the full text one click away and copyable. */
	private static function prompt( SetupContext $context ): string {
		$id   = 'stonewright-connect-prompt-full';
		$head = Html::element(
			'div',
			[ 'class' => 'sw-ui-code__head' ],
			Html::element( 'span', [], Html::text( __( 'Prompt for your AI agent', 'stonewright' ) ) )
			. Html::element(
				'span',
				[ 'class' => 'sw-ui-actions' ],
				Button::render( __( 'Show full text', 'stonewright' ), [ 'size' => 'xs', 'attrs' => [ 'data-stonewright-text-toggle' => $id, 'aria-expanded' => 'false' ] ] )
				. Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status', 'id' => $id . '-status' ], '' )
				. Button::render(
					__( 'Copy prompt', 'stonewright' ),
					[
						'size'  => 'xs',
						'attrs' => [
							'data-sw-ui-copy'              => '#' . $id,
							'data-sw-ui-copy-text'         => $context->connect_prompt,
							'data-sw-ui-copy-status'       => '#' . $id . '-status',
							'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
							'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
						],
					]
				)
				. Button::render( __( 'Show less', 'stonewright' ), [ 'size' => 'xs', 'attrs' => [ 'data-stonewright-text-collapse' => $id, 'hidden' => true ] ] )
			)
		);
		$body = Html::element(
			'pre',
			[
				'id'                          => $id,
				'class'                       => 'sw-ui-code__body stonewright-connect-prompt',
				'tabindex'                    => '0',
				'aria-label'                  => __( 'Prompt for your AI agent', 'stonewright' ),
				'data-stonewright-text-preview' => $context->prompt_preview,
				'data-stonewright-text-full'  => $context->connect_prompt,
				'data-stonewright-expanded'   => 'false',
			],
			Html::text( $context->prompt_preview )
		);

		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure sw-setup-details' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'Paste-to-agent prompt', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], Html::element( 'div', [ 'class' => 'sw-ui-code' ], $head . $body ) )
		);
	}
}
