<?php
/**
 * Keep Stonewright current: the update procedure.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\CompanionUpdateStatus;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Notice;

/**
 * The Updates view: the expected versions, how to update the WordPress plugin, how to update the local companion
 * (which the browser cannot replace), and the companion status check whose result the page script fills in.
 */
final class UpdateGuide {

	public static function html(): string {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '';

		$stats = Html::element(
			'div',
			[ 'class' => 'sw-ui-stats', 'role' => 'group', 'aria-label' => __( 'Expected versions', 'stonewright' ) ],
			self::stat( __( 'Plugin', 'stonewright' ), $version )
			. self::stat( __( 'Expected companion', 'stonewright' ), $version )
		);

		$safe = Notice::callout( 'info', '', __( 'Updates preserve memory, user skills, audit, and Direct state.', 'stonewright' ) );

		$overview = Card::render(
			__( 'Keep Stonewright current', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], Html::element( 'p', [], Html::text( __( 'Plugin and companion are separate components. Match their release versions when using local stdio. Remote HTTP needs only the plugin; pluginless Direct mode needs only the companion.', 'stonewright' ) ) ) . $stats . $safe . self::when_to_update() )
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-update-guide sw-ui-stack' ],
			$overview
			. Html::element( 'div', [ 'class' => 'sw-ui-grid sw-setup__pair' ], self::plugin_card() . self::companion_card() )
			. Notice::callout(
				'warn',
				__( 'Never commit credentials.', 'stonewright' ),
				__( 'Keep site URLs, usernames, Application Passwords, tokens, and private client configuration out of repositories, prompts, memory, skills, screenshots, and issue reports.', 'stonewright' )
			)
		);
	}

	private static function stat( string $label, string $value ): string {
		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stat' ],
			Html::element( 'span', [ 'class' => 'sw-ui-stat__label' ], Html::text( $label ) )
			. Html::element( 'span', [ 'class' => 'sw-ui-stat__value sw-ui-num' ], Html::element( 'code', [], Html::text( $value ) ) )
		);
	}

	private static function plugin_card(): string {
		$steps = Html::element( 'li', [], Html::text( __( 'Take a normal site backup.', 'stonewright' ) ) )
			. Html::element(
				'li',
				[],
				wp_kses_post(
					sprintf(
						/* translators: %s: WordPress Updates URL. */
						__( 'Open <a href="%s">Dashboard → Updates</a>, click Check again, then update Stonewright. Check again refetches GitHub Releases. If the row is still missing, click Check latest companion on this page, reload Updates, or upload the release ZIP and choose Replace current with uploaded.', 'stonewright' ),
						esc_url( admin_url( 'update-core.php' ) )
					)
				)
			)
			. Html::element( 'li', [], Html::text( __( 'Return to Get started and run Verify connection.', 'stonewright' ) ) );

		return Card::render( __( 'Update the WordPress plugin', 'stonewright' ), Html::element( 'ol', [], $steps ) );
	}

	private static function companion_card(): string {
		$steps = Html::element( 'li', [], Html::text( __( 'Replace the old stonewright-companion tarball URL in private MCP config with the current release URL below.', 'stonewright' ) ) )
			. Html::element( 'li', [], Html::text( __( 'Fully restart the AI client so the old process and cached tool list are gone.', 'stonewright' ) ) );

		$calls = '';
		foreach ( CompanionUpdateStatus::verification_steps() as $step ) {
			$calls .= Html::element( 'li', [], Html::text( $step ) );
		}

		$check = Button::group(
			[
				Button::render(
					__( 'Check latest companion', 'stonewright' ),
					[
						'variant' => 'primary',
						'attrs'   => [
							'data-stonewright-companion-status' => true,
							'data-rest-url'                     => rest_url( 'stonewright/v1/admin/companion-update-status' ),
							'data-rest-nonce'                   => wp_create_nonce( 'wp_rest' ),
						],
					]
				),
			]
		);

		$versions = [
			[ __( 'Installed plugin', 'stonewright' ), 'data-stonewright-plugin-version' ],
			[ __( 'Latest release', 'stonewright' ), 'data-stonewright-release-version' ],
			[ __( 'Configured package', 'stonewright' ), 'data-stonewright-configured-companion-version' ],
			[ __( 'Running companion', 'stonewright' ), 'data-stonewright-running-companion-version' ],
			[ __( 'HTTP bridge', 'stonewright' ), 'data-stonewright-bridge-state' ],
		];
		$facts    = '';
		foreach ( $versions as [ $label, $hook ] ) {
			$facts .= Html::element( 'dt', [], Html::text( $label ) ) . Html::element( 'dd', [], Html::element( 'code', [ $hook => true ], '' ) );
		}

		$result = Html::element(
			'div',
			[ 'class' => 'sw-companion-update-result sw-ui-stack', 'data-stonewright-companion-result' => true, 'hidden' => true, 'aria-live' => 'polite' ],
			Html::element( 'p', [ 'data-stonewright-companion-summary' => true ], '' )
			. Html::element( 'dl', [ 'class' => 'sw-ui-kv' ], $facts )
			. Html::element( 'textarea', [ 'id' => 'stonewright-companion-update-prompt', 'class' => 'sw-ui-textarea', 'rows' => '8', 'readonly' => true, 'hidden' => true, 'data-stonewright-companion-prompt' => true, 'aria-label' => __( 'Companion update prompt', 'stonewright' ) ], '' )
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-actions' ],
				Button::render(
					__( 'Copy update prompt', 'stonewright' ),
					[
						'attrs' => [
							'data-stonewright-companion-prompt-copy' => true,
							'data-sw-ui-copy'                        => '#stonewright-companion-update-prompt',
							'data-sw-ui-copy-status'                 => '#stonewright-companion-prompt-status',
							'data-sw-ui-copied-label'                => __( 'Copied', 'stonewright' ),
							'data-sw-ui-copy-failed-label'           => __( 'Press Ctrl+C', 'stonewright' ),
							'hidden'                                 => true,
						],
					]
				)
				. Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status', 'id' => 'stonewright-companion-prompt-status' ], '' )
				. '<a class="sw-ui-btn" href="#" target="_blank" rel="noopener noreferrer" data-stonewright-companion-download hidden>' . esc_html__( 'Download official companion', 'stonewright' ) . '</a>'
				. '<a class="sw-ui-link" href="#" target="_blank" rel="noopener noreferrer" data-stonewright-companion-checksums hidden>' . esc_html__( 'Verify SHA-256 checksums', 'stonewright' ) . '</a>'
			)
		);

		$body = Html::element( 'p', [], Html::text( __( 'The browser cannot replace an stdio process on your computer. Stonewright can check the official release, inspect an optional configured HTTP bridge, and prepare a safe update handoff.', 'stonewright' ) ) )
			. Html::element( 'ol', [], $steps )
			. Html::element( 'p', [], Html::element( 'strong', [], Html::text( __( 'After restart, complete these four calls in order:', 'stonewright' ) ) ) )
			. Html::element( 'ol', [ 'data-stonewright-runtime-verification-flow' => true ], $calls )
			. Html::element( 'p', [], Html::text( __( 'Then verify the expected companion version, an empty refresh_required_tool_names list, and client_has_tool=true.', 'stonewright' ) ) )
			. $check
			. $result;

		return Card::render( __( 'Update the local companion', 'stonewright' ), Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $body ) );
	}

	private static function when_to_update(): string {
		$items = '';
		foreach (
			[
				__( 'When WordPress or GitHub Releases shows a newer Stonewright version.', 'stonewright' ),
				__( 'When Setup reports a version mismatch, missing required tools, or a stale companion process.', 'stonewright' ),
				__( 'When a release note fixes an activation, security, compatibility, or packaging problem that affects your site.', 'stonewright' ),
			] as $line
		) {
			$items .= Html::element( 'li', [], Html::text( $line ) );
		}
		$link = '<a class="sw-ui-link sw-ui-link--external" href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Stonewright Releases', 'stonewright' ) . '<span class="sw-ui-visually-hidden"> ' . esc_html__( '(opens in a new tab)', 'stonewright' ) . '</span></a>';

		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure sw-setup-details' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'When should I update?', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body sw-ui-stack' ], Html::element( 'ul', [], $items ) . $link )
		);
	}
}
