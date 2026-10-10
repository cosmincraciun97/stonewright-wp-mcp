<?php
/**
 * Markup of the OAuth connect panel on the Setup screen.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Connect;

use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\CodeBlock;
use Stonewright\WpMcp\Admin\Ui\CopyField;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Table;

/**
 * Renders the OAuth part of the Setup screen in four pieces: the sign-in status with the addresses a client
 * needs, the reasons sign-in is off, one disclosure per AI client with its published setup, and the list of
 * connected clients with a disconnect form each.
 *
 * Everything readable is server-rendered: disclosures are native details elements and disconnecting is a plain
 * form post, so nothing here needs JavaScript. Copy buttons use the shared UI script when it is present. Every
 * value is escaped at output; markup comes from the Ui helpers.
 *
 * @phpstan-import-type Status from SignInStatus
 * @phpstan-import-type Guide from ClientInstructions
 * @phpstan-import-type Connection from ConnectedClients
 */
final class SignInPanel {

	public const CONNECTIONS_ID = 'stonewright-oauth-connections';

	/** @param Status $status */
	public static function render_status( array $status ): void {
		echo self::status_html( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/** @param Status $status */
	public static function render_issues( array $status ): void {
		echo self::issues_html( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/**
	 * The sign-in status card: whether OAuth sign-in is on, the transport and the addresses a client needs.
	 *
	 * @param Status $status
	 */
	public static function status_html( array $status ): string {
		$on    = $status['available'];
		$badge = $on
			? Badge::render( __( 'On', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] )
			: Badge::render( __( 'Off', 'stonewright' ), [ 'dot' => true ] );

		$facts = KvList::render(
			[
				[ 'label' => __( 'Transport', 'stonewright' ), 'value' => $status['transport_label'] ],
				[
					'label'      => __( 'MCP server URL', 'stonewright' ),
					'value_html' => CopyField::render( $status['mcp_url'], [ 'id' => 'sw-connect-mcp-url', 'label' => __( 'MCP server URL', 'stonewright' ) ] ),
				],
				[
					'label'      => __( 'Suggested server name', 'stonewright' ),
					'value_html' => CopyField::render( ClientInstructions::server_name( $status['server_name'] ), [ 'id' => 'sw-connect-server-name', 'label' => __( 'server name', 'stonewright' ) ] ),
				],
			],
			[ 'label' => __( 'OAuth sign-in', 'stonewright' ) ]
		);

		$note = [] !== $status['issues']
			? self::issues_html( $status )
			: Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Clients add this URL and send you to this site to approve access. No password is copied into their configuration.', 'stonewright' ) ) );

		return Card::render( __( 'OAuth sign-in', 'stonewright' ), $facts . $note, [ 'actions_html' => $badge, 'id' => 'sw-connect-status' ] );
	}

	/**
	 * Why OAuth sign-in is off, with the fix for each reason. Empty when there is nothing to say.
	 *
	 * @param Status $status
	 */
	public static function issues_html( array $status ): string {
		if ( [] === $status['issues'] ) {
			return '';
		}
		$items = '';
		foreach ( $status['issues'] as $issue ) {
			$items .= Html::element(
				'li',
				[ 'class' => 'sw-connect-issue' ],
				Html::element( 'span', [], Html::text( $issue['reason'] ) ) . ' ' . Html::element( 'span', [ 'class' => 'sw-connect-issue__fix' ], Html::text( $issue['remedy'] ) )
			);
		}

		return Notice::callout( 'warn', __( 'Why OAuth sign-in is off', 'stonewright' ), '', [ 'text_html' => Html::element( 'ul', [ 'class' => 'sw-connect-issues' ], $items ) ] );
	}

	/**
	 * One disclosure per catalog client; the selected client starts open. Clients that can reach this site come
	 * first, the ones that cannot follow under their own heading.
	 *
	 * @param Status $status
	 */
	public static function render_guides( array $status, string $selected = '' ): void {
		$selected = ClientCatalog::resolve_slug( $selected );
		$works    = '';
		$blocked  = '';
		$count    = 0;
		foreach ( ClientInstructions::all( $status['mcp_url'], $status['server_name'] ) as $guide ) {
			$warning = self::site_warning( $guide, $status );
			$item    = self::guide_html( $guide, $warning, $guide['slug'] === $selected );
			if ( '' === $warning ) {
				$works .= $item;
			} else {
				$blocked .= $item;
				++$count;
			}
		}

		$title_id = 'sw-connect-title';
		$html     = Html::element( 'h3', [ 'class' => 'sw-connect__title', 'id' => $title_id ], Html::text( __( 'Sign in from your AI client', 'stonewright' ) ) )
			. Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Open your client below and follow its own setup: add the MCP server URL, then approve the request on this site when the client opens your browser.', 'stonewright' ) ) )
			. self::reach_html( $status )
			. Html::element( 'div', [ 'class' => 'sw-connect-clients' ], $works );
		if ( '' !== $blocked ) {
			$html .= Html::element(
				'h4',
				[ 'class' => 'sw-connect__subtitle' ],
				Html::text( __( 'Cannot sign in to this site', 'stonewright' ) ) . ' ' . Badge::count( $count )
			) . Html::element( 'div', [ 'class' => 'sw-connect-clients' ], $blocked );
		}
		$html .= Html::element(
			'p',
			[ 'class' => 'sw-connect-alternative' ],
			Html::element( 'strong', [], Html::text( __( 'Prefer a password?', 'stonewright' ) ) ) . ' '
			. Html::text( __( 'Clients without browser sign-in, and sites that cannot offer OAuth, can connect with an Application Password through the separate endpoint', 'stonewright' ) ) . ' '
			. Html::element( 'code', [], Html::text( $status['password_url'] ) ) . '. '
			. Html::element( 'a', [ 'href' => '#stonewright-application-password' ], Html::text( __( 'Choose Application Password in step 2.', 'stonewright' ) ) )
		);

		echo Html::element( 'section', [ 'class' => 'sw-connect sw-ui-stack', 'aria-labelledby' => $title_id ], $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/**
	 * The connected clients card with a disconnect form per client.
	 *
	 * @param list<Connection> $connections
	 */
	public static function render_connections( array $connections, string $notice = '' ): void {
		echo self::connections_html( $connections, $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/**
	 * @param list<Connection> $connections
	 */
	public static function connections_html( array $connections, string $notice = '' ): string {
		$body = self::notice_html( $notice );

		if ( [] === $connections ) {
			$body .= EmptyState::render(
				__( 'No AI client is signed in with OAuth.', 'stonewright' ),
				__( 'A client appears here after it signs in and you approve it on this site. Choose OAuth in step 2 of Get started, then add the MCP server URL in your client.', 'stonewright' ),
				[ 'variant' => 'first-run', 'icon' => 'plug', 'heading' => 3 ]
			);

			return Card::render( __( 'Connected OAuth clients', 'stonewright' ), $body, [ 'id' => self::CONNECTIONS_ID, 'desc' => self::connections_description() ] );
		}

		$rows = [];
		foreach ( $connections as $connection ) {
			/* translators: %d: number of active sign-ins. */
			$grants = sprintf( _n( '%d active sign-in', '%d active sign-ins', $connection['grants'], 'stonewright' ), $connection['grants'] );
			$meta   = '' === $connection['identity'] ? $grants : $connection['identity'] . ' · ' . $grants;
			$rows[] = [
				'client'    => [ 'text' => $connection['name'], 'meta' => $meta ],
				'people'    => implode( ', ', $connection['people'] ),
				'since'     => [ 'html' => self::time_html( $connection['connected_since'], __( 'Unknown', 'stonewright' ) ) ],
				'last_used' => [ 'html' => self::time_html( $connection['last_used'], __( 'Not yet', 'stonewright' ) ) ],
				'action'    => [ 'html' => self::disconnect_form( $connection ) ],
			];
		}

		$body .= Table::render(
			[
				[ 'key' => 'client', 'label' => __( 'Client', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'people', 'label' => __( 'Approved by', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'since', 'label' => __( 'Connected since', 'stonewright' ), 'secondary' => true ],
				[ 'key' => 'last_used', 'label' => __( 'Last used', 'stonewright' ) ],
				[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
			],
			$rows,
			[ 'caption' => __( 'Connected OAuth clients', 'stonewright' ), 'class' => 'sw-connect-table' ]
		);

		return Card::render( __( 'Connected OAuth clients', 'stonewright' ), $body, [ 'id' => self::CONNECTIONS_ID, 'desc' => self::connections_description() ] );
	}

	private static function connections_description(): string {
		return __( 'Clients that signed in with OAuth and can still use this site. Last used is when the client last received or refreshed its access. Disconnecting revokes that access at once; the client has to sign in again.', 'stonewright' );
	}

	/** @param Status $status */
	private static function reach_html( array $status ): string {
		$items = '';
		foreach (
			[
				__( "Hosted apps (Claude.ai, Claude Desktop connectors and ChatGPT on the web) connect from their providers' servers, so they reach only sites on the public internet, never a site that runs on your computer or local network.", 'stonewright' ),
				__( 'Gemini CLI signs in only to HTTPS sites on a public address.', 'stonewright' ),
				__( 'Desktop apps, editors and command-line clients run on your computer and can reach a local site.', 'stonewright' ),
			] as $line
		) {
			$items .= Html::element( 'li', [], Html::text( $line ) );
		}
		$list = Html::element( 'ul', [ 'class' => 'sw-connect-reach' ], $items );
		if ( $status['local'] ) {
			$list .= Html::element(
				'p',
				[ 'class' => 'sw-connect-callout__site' ],
				Html::element( 'strong', [], Html::text( __( 'This site looks local', 'stonewright' ) ) ) . ' '
				. Html::text(
					sprintf(
						/* translators: %s: why the site looks local. */
						__( '(%s): hosted apps and Gemini CLI cannot reach it. Use a client that runs on your computer, or publish the site on a public HTTPS address.', 'stonewright' ),
						$status['local_reason']
					)
				)
			);
		}

		return Notice::callout( 'info', __( 'Where clients connect from', 'stonewright' ), '', [ 'text_html' => $list ] );
	}

	/**
	 * @param Guide $guide
	 */
	private static function guide_html( array $guide, string $warning, bool $open ): string {
		$slug    = $guide['slug'];
		$summary = Icon::render( 'chev-r' )
			. Html::element( 'span', [ 'class' => 'sw-connect-client__name' ], Html::text( $guide['label'] ) )
			. Badge::tag( $guide['tag'] )
			. ( '' !== $warning ? Badge::render( __( 'Cannot sign in to this site', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] ) : '' );

		$body = '';
		if ( '' !== $warning ) {
			$body .= Notice::callout( 'warn', '', $warning );
		}
		$steps = '';
		foreach ( $guide['steps'] as $step ) {
			$steps .= Html::element( 'li', [], Html::text( $step ) );
		}
		$body .= Html::element( 'ol', [ 'class' => 'sw-connect-steps' ], $steps );

		if ( [] !== $guide['links'] ) {
			$links = '';
			foreach ( $guide['links'] as $link ) {
				$links .= self::link_html( $link );
			}
			$body .= Html::element( 'div', [ 'class' => 'sw-ui-actions' ], $links );
		}
		foreach ( $guide['snippets'] as $snippet ) {
			$body .= CodeBlock::render(
				$snippet['code'],
				[
					'id'         => 'sw-connect-' . $slug . '-' . $snippet['key'],
					'title'      => $snippet['title'],
					'where'      => $snippet['location'],
					/* translators: 1: snippet title, 2: client name. */
					'copy_label' => sprintf( __( '%1$s for %2$s', 'stonewright' ), $snippet['title'], $guide['label'] ),
					'body_class' => 'sw-connect-snippet__code',
				]
			);
		}
		if ( '' !== $guide['sign_in'] ) {
			$body .= Html::element( 'p', [ 'class' => 'sw-connect-signin' ], Html::element( 'strong', [], Html::text( __( 'How sign-in starts:', 'stonewright' ) ) ) . ' ' . Html::text( $guide['sign_in'] ) );
		}
		if ( [] !== $guide['limits'] ) {
			$limits = '';
			foreach ( $guide['limits'] as $limit ) {
				$limits .= Html::element( 'li', [], Html::text( $limit ) );
			}
			$body .= Html::element( 'ul', [ 'class' => 'sw-connect-limits' ], $limits );
		}

		return Html::element(
			'details',
			[
				'class'                           => 'sw-ui-disclosure sw-connect-client',
				'id'                              => 'sw-connect-client-' . $slug,
				'data-stonewright-connect-client' => $slug,
				'open'                            => $open ? true : null,
			],
			Html::element( 'summary', [], $summary ) . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body sw-ui-stack' ], $body )
		);
	}

	/** @param array{label: string, url: string, schemes: list<string>} $link */
	private static function link_html( array $link ): string {
		$web  = [ 'https' ] === $link['schemes'];
		$text = Html::text( $link['label'] );
		if ( $web ) {
			$text .= Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( __( '(opens in a new tab)', 'stonewright' ) ) );
		}
		// The destination may be a client's own scheme (cursor:, vscode:), which the generic attribute helper would drop.
		return '<a class="sw-ui-btn sw-ui-btn--sm" href="' . esc_url( $link['url'], $link['schemes'] ) . '"' . ( $web ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . $text . '</a>';
	}

	/**
	 * Why a client cannot sign in to this particular site, or an empty string.
	 *
	 * @param Guide  $guide
	 * @param Status $status
	 */
	private static function site_warning( array $guide, array $status ): string {
		if ( ClientInstructions::REACH_HOSTED === $guide['reach'] && $status['local'] ) {
			/* translators: 1: client name, 2: why the site looks local. */
			return sprintf( __( "%1\$s connects from its provider's servers and cannot reach this site, because %2\$s.", 'stonewright' ), $guide['label'], $status['local_reason'] );
		}
		if ( ClientInstructions::REACH_PUBLIC === $guide['reach'] && ( $status['local'] || ! $status['secure'] ) ) {
			/* translators: %s: client name. */
			return sprintf( __( '%s signs in only to HTTPS sites on a public address, and this site is not one.', 'stonewright' ), $guide['label'] );
		}
		if ( 'claude-code' === $guide['slug'] && ! $status['secure'] && ! $status['loopback'] ) {
			/* translators: %s: site host name. */
			return sprintf( __( 'Claude Code sends OAuth credentials only to HTTPS or loopback addresses, so it cannot sign in to this plain HTTP site at %s.', 'stonewright' ), $status['host'] );
		}
		return '';
	}

	/** @param Connection $connection */
	private static function disconnect_form( array $connection ): string {
		$key = $connection['client_key'];

		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-connect-disconnect' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => ConnectedClients::ACTION ] )
			. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'client', 'value' => $key ] )
			. Html::void( 'input', [ 'type' => 'hidden', 'name' => '_wpnonce', 'value' => wp_create_nonce( ConnectedClients::NONCE_PREFIX . $key ) ] )
			. Button::render(
				__( 'Disconnect', 'stonewright' ),
				[
					'type'    => 'submit',
					'variant' => 'danger',
					'size'    => 'sm',
					'context' => $connection['name'],
					'attrs'   => [
						/* translators: %s: client name. */
						'data-confirm' => sprintf( __( 'Disconnect %s? It loses access to this site at once and has to sign in again.', 'stonewright' ), $connection['name'] ),
					],
				]
			)
		);
	}

	private static function time_html( ?int $timestamp, string $fallback ): string {
		if ( null === $timestamp ) {
			return Html::text( $fallback );
		}
		$format = trim( (string) get_option( 'date_format', 'M j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );

		return Html::element( 'time', [ 'datetime' => gmdate( 'c', $timestamp ) ], Html::text( (string) wp_date( $format, $timestamp ) ) );
	}

	private static function notice_html( string $notice ): string {
		$notices = [
			'disconnected' => [ 'ok', __( 'The client was disconnected. It has to sign in again to use this site.', 'stonewright' ) ],
			'none'         => [ 'info', __( 'That client had no active sign-in, so nothing changed.', 'stonewright' ) ],
			'failed'       => [ 'danger', __( 'The client could not be disconnected. Try again; the attempt is recorded in the audit log.', 'stonewright' ) ],
		];
		if ( ! isset( $notices[ $notice ] ) ) {
			return '';
		}
		[ $variant, $message ] = $notices[ $notice ];

		return Notice::render( $variant, $message );
	}
}
