<?php
/**
 * Step 4: verify the connection.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * The last step of Setup: the preflight (local readiness) and the live authenticated MCP loopback, with the lists
 * the page script fills in. Preflight alone does not prove a client is connected, and the text says so.
 */
final class VerifyStep {

	public const ID = 'stonewright-verify';

	public static function html( SetupContext $context ): string {
		unset( $context );
		$rest = static fn ( string $route ): array => [
			'data-rest-url'   => rest_url( 'stonewright/v1/admin/' . $route ),
			'data-rest-nonce' => wp_create_nonce( 'wp_rest' ),
		];

		$actions = Button::group(
			[
				Button::render( __( 'Run preflight', 'stonewright' ), [ 'attrs' => array_merge( [ 'data-stonewright-connection-test' => true ], $rest( 'connection-test' ) ) ] ),
				Button::render( __( 'Verify connection', 'stonewright' ), [ 'variant' => 'primary', 'attrs' => array_merge( [ 'data-stonewright-connection-verify' => true ], $rest( 'connection-verify' ) ) ] ),
			]
		);

		$body = Html::element(
			'div',
			[ 'class' => 'sw-ui-stack' ],
			Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Run preflight for local readiness, then Verify connection for a live authenticated MCP loopback (initialize → tools/list → task-start). Preflight alone does not prove a client is connected.', 'stonewright' ) ) )
				. $actions
				. Html::element( 'ol', [ 'class' => 'sw-ui-checks sw-connection-test-results', 'data-stonewright-connection-results' => true, 'hidden' => true, 'aria-live' => 'polite', 'aria-label' => __( 'Preflight results', 'stonewright' ) ], '' )
				. Html::element( 'ol', [ 'class' => 'sw-ui-checks sw-connection-verify-results', 'data-stonewright-connection-verify-results' => true, 'hidden' => true, 'aria-live' => 'polite', 'aria-label' => __( 'Connection check results', 'stonewright' ) ], '' )
		);

		return Step::html( 4, __( 'Verify the connection', 'stonewright' ), '', $body, self::ID );
	}
}
