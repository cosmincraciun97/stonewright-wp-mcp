<?php
/**
 * The Setup page: header status, notices and its four views.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Tabs;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Prints Setup inside the shell: the page header (title, one line, the state of AI abilities), the notice about
 * a production site, and four views as tabs. The server renders the view the `tab` argument names; the others are
 * in the page too, so switching is instant and a link to something inside one opens it.
 *
 *   Get started   the numbered steps: turn on, choose a sign-in method, connect a client, verify
 *   Settings      the settings form and the domain lock
 *   Connections   the sign-in addresses and the connected OAuth clients
 *   Updates       how to keep the plugin and the companion current
 */
final class SetupPage {

	private const TAB_PREFIX = 'sw-setup';

	public static function render(): void {
		$context = SetupContext::current();

		AdminShell::open( SetupTabs::SLUG, [ 'actions' => self::header_status( $context ) ] );
		echo Scope::wrap( self::content( $context ), [ 'class' => 'sw-setup' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AuthMethodScript::render();
		AdminShell::close();
	}

	private static function content( SetupContext $context ): string {
		$current = SetupTabs::current();
		$labels  = [
			'get-started' => __( 'Get started', 'stonewright' ),
			'settings'    => __( 'Settings', 'stonewright' ),
			'connections' => __( 'Connections', 'stonewright' ),
			'updates'     => __( 'Updates', 'stonewright' ),
		];

		$tabs = [];
		foreach ( SetupTabs::ids() as $id ) {
			$tab = [ 'id' => $id, 'label' => $labels[ $id ], 'href' => SetupTabs::url( $id ) ];
			if ( 'connections' === $id && [] !== $context->oauth_connections ) {
				$tab['count']       = count( $context->oauth_connections );
				$tab['count_label'] = __( 'connected OAuth clients', 'stonewright' );
			}
			$tabs[] = $tab;
		}

		$panels = [
			'get-started' => self::get_started( $context ),
			'settings'    => SettingsForm::html( $context ) . DomainLockCard::html(),
			'connections' => SignInPanel::status_html( $context->oauth_status ) . SignInPanel::connections_html( $context->oauth_connections, ConnectedClients::notice() ),
			'updates'     => UpdateGuide::html(),
		];

		$html = self::production_notice( $context )
			. Tabs::list( $tabs, $current, [ 'label' => __( 'Setup views', 'stonewright' ), 'prefix' => self::TAB_PREFIX, 'param' => SetupTabs::PARAM ] );
		foreach ( $panels as $id => $panel ) {
			$html .= Tabs::panel( self::TAB_PREFIX, $id, Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $panel ), $id === $current );
		}

		return $html;
	}

	/** The numbered steps. */
	private static function get_started( SetupContext $context ): string {
		return self::enable_step( $context )
			. AuthenticationStep::html( $context )
			. ConnectStep::html( $context )
			. VerifyStep::html( $context );
	}

	/** Step 1 only reports where AI abilities stand; turning them on is a setting. */
	private static function enable_step( SetupContext $context ): string {
		if ( $context->enabled ) {
			$body = Html::element( 'p', [], Html::text( __( 'AI abilities are on for this site.', 'stonewright' ) ) );
			if ( PluginEffectiveState::STATE_ENABLED !== $context->effective_state ) {
				$body = Notice::callout(
					'warn',
					'',
					sprintf(
						/* translators: %s: effective runtime state key */
						__( 'Requested: on. Effective state: %s (abilities stay blocked until resolved).', 'stonewright' ),
						$context->effective_state
					)
				);
			}
			$body .= Button::group( [ Button::render( __( 'Change settings', 'stonewright' ), [ 'href' => SetupTabs::url( 'settings' ), 'variant' => 'tertiary' ] ) ] );
		} else {
			$body = Html::element( 'p', [], Html::text( __( 'Stonewright is off. Turn on AI abilities in Settings, then come back to choose how clients sign in.', 'stonewright' ) ) )
				. Button::group( [ Button::render( __( 'Open settings', 'stonewright' ), [ 'href' => SetupTabs::url( 'settings' ), 'variant' => 'primary' ] ) ] );
		}

		return Step::html( 1, __( 'Turn on AI abilities', 'stonewright' ), $context->step_states[1], $body );
	}

	/** On a production site the mode decides which notice shows. */
	private static function production_notice( SetupContext $context ): string {
		if ( 'production' !== wp_get_environment_type() || ! $context->enabled ) {
			return '';
		}
		if ( 'production-safe' !== $context->mode ) {
			return Notice::render(
				'danger',
				__( 'P0: WordPress environment is production but Stonewright mode is not production-safe.', 'stonewright' ),
				sprintf(
					/* translators: %s: current mode */
					__( 'Current mode: %s. Switch to production-safe before any destructive agent work so confirmation tokens gate writes. Development mode on a live host is an explicit operator risk.', 'stonewright' ),
					$context->mode
				),
				[ 'attrs' => [ 'data-stonewright-severity' => 'p0' ], 'actions_html' => Button::render( __( 'Open settings', 'stonewright' ), [ 'href' => SetupTabs::url( 'settings' ), 'size' => 'sm' ] ) ]
			);
		}

		return Notice::render( 'info', __( 'Production site detected.', 'stonewright' ), __( 'production-safe mode is active: destructive operations require confirmation tokens.', 'stonewright' ) );
	}

	/** The state of AI abilities, as a word with a dot or an icon, in the page header. */
	private static function header_status( SetupContext $context ): string {
		unset( $context );

		return match ( PluginEffectiveState::effective_state() ) {
			PluginEffectiveState::STATE_ENABLED              => Badge::render( __( 'AI abilities on', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] ),
			PluginEffectiveState::STATE_DISABLED_BY_OPERATOR => Badge::render( __( 'AI abilities off', 'stonewright' ), [ 'dot' => true ] ),
			default                                          => Badge::render( __( 'AI abilities blocked', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'alert' ] ),
		};
	}
}
