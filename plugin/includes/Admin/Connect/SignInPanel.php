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

/**
 * Renders the OAuth part of the Setup screen in three pieces: the sign-in status with
 * the addresses a client needs, one disclosure per AI client with its published setup,
 * and the list of connected clients with a disconnect form each.
 *
 * Everything readable is server-rendered: disclosures are native details elements and
 * disconnecting is a plain form post, so nothing here needs JavaScript. Copy buttons use
 * the admin script when it is present. Every value is escaped at output.
 *
 * @phpstan-import-type Status from SignInStatus
 * @phpstan-import-type Guide from ClientInstructions
 * @phpstan-import-type Connection from ConnectedClients
 */
final class SignInPanel {

	public const CONNECTIONS_ID = 'stonewright-oauth-connections';

	/** @param Status $status */
	public static function render_status( array $status ): void {
		$state = $status['available'] ? 'on' : 'off';
		?>
		<section class="sw-connect-status sw-connect-status--<?php echo esc_attr( $state ); ?>" aria-labelledby="sw-connect-status-title">
			<div class="sw-connect-status__head">
				<h3 id="sw-connect-status-title" class="sw-connect-status__title"><?php esc_html_e( 'OAuth sign-in', 'stonewright' ); ?></h3>
				<span class="sw-connect-pill sw-connect-pill--<?php echo esc_attr( $state ); ?>">
					<?php echo esc_html( 'on' === $state ? __( 'On', 'stonewright' ) : __( 'Off', 'stonewright' ) ); ?>
				</span>
			</div>
			<dl class="sw-connect-facts">
				<div class="sw-connect-facts__row">
					<dt><?php esc_html_e( 'Transport', 'stonewright' ); ?></dt>
					<dd><?php echo esc_html( $status['transport_label'] ); ?></dd>
				</div>
				<div class="sw-connect-facts__row">
					<dt id="sw-connect-mcp-url-label"><?php esc_html_e( 'MCP server URL', 'stonewright' ); ?></dt>
					<dd class="sw-connect-copyable">
						<code id="sw-connect-mcp-url"><?php echo esc_html( $status['mcp_url'] ); ?></code>
						<button type="button" class="button button-small" data-stonewright-copy="sw-connect-mcp-url" aria-describedby="sw-connect-mcp-url-label"><?php esc_html_e( 'Copy', 'stonewright' ); ?></button>
					</dd>
				</div>
				<div class="sw-connect-facts__row">
					<dt id="sw-connect-server-name-label"><?php esc_html_e( 'Suggested server name', 'stonewright' ); ?></dt>
					<dd class="sw-connect-copyable">
						<code id="sw-connect-server-name"><?php echo esc_html( ClientInstructions::server_name( $status['server_name'] ) ); ?></code>
						<button type="button" class="button button-small" data-stonewright-copy="sw-connect-server-name" aria-describedby="sw-connect-server-name-label"><?php esc_html_e( 'Copy', 'stonewright' ); ?></button>
					</dd>
				</div>
			</dl>
			<?php if ( [] !== $status['issues'] ) : ?>
				<div class="sw-connect-callout sw-connect-callout--warn">
					<p class="sw-connect-callout__title"><strong><?php esc_html_e( 'Why OAuth sign-in is off', 'stonewright' ); ?></strong></p>
					<ul class="sw-connect-issues">
						<?php foreach ( $status['issues'] as $issue ) : ?>
							<li class="sw-connect-issue">
								<span><?php echo esc_html( $issue['reason'] ); ?></span>
								<span class="sw-connect-issue__fix"><?php echo esc_html( $issue['remedy'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Clients add this URL and send you to this site to approve access. No password is copied into their configuration.', 'stonewright' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * One disclosure per catalog client; the selected client starts open.
	 *
	 * @param Status $status
	 */
	public static function render_guides( array $status, string $selected = '' ): void {
		$selected = ClientCatalog::resolve_slug( $selected );
		$guides   = ClientInstructions::all( $status['mcp_url'], $status['server_name'] );
		?>
		<section class="sw-connect" aria-labelledby="sw-connect-title">
			<h3 id="sw-connect-title" class="sw-connect__title"><?php esc_html_e( 'Sign in from your AI client', 'stonewright' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Open your client below and follow its own setup: add the MCP server URL, then approve the request on this site when the client opens your browser.', 'stonewright' ); ?></p>
			<?php self::render_reach( $status ); ?>
			<div class="sw-connect-clients">
				<?php foreach ( $guides as $guide ) : ?>
					<?php self::render_guide( $guide, $status, $guide['slug'] === $selected ); ?>
				<?php endforeach; ?>
			</div>
			<p class="sw-connect-alternative">
				<strong><?php esc_html_e( 'Prefer a password?', 'stonewright' ); ?></strong>
				<?php esc_html_e( 'Clients without browser sign-in, and sites that cannot offer OAuth, can connect with an Application Password through the separate endpoint', 'stonewright' ); ?>
				<code><?php echo esc_html( $status['password_url'] ); ?></code>.
				<a href="#stonewright-application-password"><?php esc_html_e( 'Choose Application Password in step 2.', 'stonewright' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * The connected clients card with a disconnect form per client.
	 *
	 * @param list<Connection> $connections
	 */
	public static function render_connections( array $connections, string $notice = '' ): void {
		?>
		<section class="sw-setup-card sw-connect-connections" id="<?php echo esc_attr( self::CONNECTIONS_ID ); ?>" aria-labelledby="sw-connect-connections-title">
			<div class="stonewright-step-index" aria-hidden="true">&#8644;</div>
			<div class="stonewright-step-body">
				<h2 id="sw-connect-connections-title"><?php esc_html_e( 'Connected OAuth clients', 'stonewright' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Clients that signed in with OAuth and can still use this site. Last used is when the client last received or refreshed its access. Disconnecting revokes that access at once; the client has to sign in again.', 'stonewright' ); ?></p>
				<?php self::render_notice( $notice ); ?>
				<?php if ( [] === $connections ) : ?>
					<p class="sw-connect-empty"><?php esc_html_e( 'No AI client is signed in with OAuth.', 'stonewright' ); ?></p>
				<?php else : ?>
					<div class="sw-connect-table-wrap">
						<table class="widefat striped sw-connect-table">
							<caption class="screen-reader-text"><?php esc_html_e( 'Connected OAuth clients', 'stonewright' ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Client', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Approved by', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Connected since', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last used', 'stonewright' ); ?></th>
									<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'stonewright' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $connections as $connection ) : ?>
									<?php self::render_connection( $connection ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/** @param Status $status */
	private static function render_reach( array $status ): void {
		?>
		<div class="sw-connect-callout sw-connect-callout--info">
			<p class="sw-connect-callout__title"><strong><?php esc_html_e( 'Where clients connect from', 'stonewright' ); ?></strong></p>
			<ul class="sw-connect-reach">
				<li><?php esc_html_e( "Hosted apps (Claude.ai, Claude Desktop connectors and ChatGPT on the web) connect from their providers' servers, so they reach only sites on the public internet, never a site that runs on your computer or local network.", 'stonewright' ); ?></li>
				<li><?php esc_html_e( 'Gemini CLI signs in only to HTTPS sites on a public address.', 'stonewright' ); ?></li>
				<li><?php esc_html_e( 'Desktop apps, editors and command-line clients run on your computer and can reach a local site.', 'stonewright' ); ?></li>
			</ul>
			<?php if ( $status['local'] ) : ?>
				<p class="sw-connect-callout__site">
					<strong><?php esc_html_e( 'This site looks local', 'stonewright' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: why the site looks local. */
							__( '(%s): hosted apps and Gemini CLI cannot reach it. Use a client that runs on your computer, or publish the site on a public HTTPS address.', 'stonewright' ),
							$status['local_reason']
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param Guide  $guide
	 * @param Status $status
	 */
	private static function render_guide( array $guide, array $status, bool $open ): void {
		$slug    = $guide['slug'];
		$warning = self::site_warning( $guide, $status );
		?>
		<details class="sw-connect-client" id="<?php echo esc_attr( 'sw-connect-client-' . $slug ); ?>" data-stonewright-connect-client="<?php echo esc_attr( $slug ); ?>"<?php echo $open ? ' open' : ''; ?>>
			<summary class="sw-connect-client__summary">
				<span class="sw-connect-client__name"><?php echo esc_html( $guide['label'] ); ?></span>
				<span class="sw-connect-client__tag"><?php echo esc_html( $guide['tag'] ); ?></span>
				<?php if ( '' !== $warning ) : ?>
					<span class="sw-connect-client__flag"><?php esc_html_e( 'Cannot sign in to this site', 'stonewright' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="sw-connect-client__body">
				<?php if ( '' !== $warning ) : ?>
					<p class="sw-connect-client__warning"><?php echo esc_html( $warning ); ?></p>
				<?php endif; ?>
				<ol class="sw-connect-steps">
					<?php foreach ( $guide['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<?php if ( [] !== $guide['links'] ) : ?>
					<p class="sw-connect-links">
						<?php foreach ( $guide['links'] as $link ) : ?>
							<?php self::render_link( $link ); ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<?php foreach ( $guide['snippets'] as $snippet ) : ?>
					<?php $code_id = 'sw-connect-' . $slug . '-' . $snippet['key']; ?>
					<figure class="sw-connect-snippet">
						<figcaption class="sw-connect-snippet__head">
							<span class="sw-connect-snippet__title" id="<?php echo esc_attr( $code_id . '-title' ); ?>"><?php echo esc_html( $snippet['title'] ); ?></span>
							<span class="sw-connect-snippet__where"><?php echo esc_html( $snippet['location'] ); ?></span>
							<button type="button" class="button button-small" data-stonewright-copy="<?php echo esc_attr( $code_id ); ?>" aria-describedby="<?php echo esc_attr( $code_id . '-title' ); ?>"><?php esc_html_e( 'Copy', 'stonewright' ); ?></button>
						</figcaption>
						<pre class="sw-connect-snippet__code" id="<?php echo esc_attr( $code_id ); ?>"><code><?php echo esc_html( $snippet['code'] ); ?></code></pre>
					</figure>
				<?php endforeach; ?>
				<?php if ( '' !== $guide['sign_in'] ) : ?>
					<p class="sw-connect-signin"><strong><?php esc_html_e( 'How sign-in starts:', 'stonewright' ); ?></strong> <?php echo esc_html( $guide['sign_in'] ); ?></p>
				<?php endif; ?>
				<?php if ( [] !== $guide['limits'] ) : ?>
					<ul class="sw-connect-limits">
						<?php foreach ( $guide['limits'] as $limit ) : ?>
							<li><?php echo esc_html( $limit ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** @param array{label: string, url: string, schemes: list<string>} $link */
	private static function render_link( array $link ): void {
		$web = [ 'https' ] === $link['schemes'];
		?>
		<a class="button" href="<?php echo esc_url( $link['url'], $link['schemes'] ); ?>"<?php echo $web ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
			<?php echo esc_html( $link['label'] ); ?>
			<?php if ( $web ) : ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'stonewright' ); ?></span>
			<?php endif; ?>
		</a>
		<?php
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
	private static function render_connection( array $connection ): void {
		$key  = $connection['client_key'];
		$name = $connection['name'];
		/* translators: %d: number of active sign-ins. */
		$grants = sprintf( _n( '%d active sign-in', '%d active sign-ins', $connection['grants'], 'stonewright' ), $connection['grants'] );
		$meta   = '' === $connection['identity'] ? $grants : $connection['identity'] . ' · ' . $grants;
		?>
		<tr>
			<th scope="row">
				<span class="sw-connect-table__name"><?php echo esc_html( $name ); ?></span>
				<span class="sw-connect-table__meta"><?php echo esc_html( $meta ); ?></span>
			</th>
			<td><?php echo esc_html( implode( ', ', $connection['people'] ) ); ?></td>
			<td><?php self::render_time( $connection['connected_since'], __( 'Unknown', 'stonewright' ) ); ?></td>
			<td><?php self::render_time( $connection['last_used'], __( 'Not yet', 'stonewright' ) ); ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sw-connect-disconnect">
					<input type="hidden" name="action" value="<?php echo esc_attr( ConnectedClients::ACTION ); ?>" />
					<input type="hidden" name="client" value="<?php echo esc_attr( $key ); ?>" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( ConnectedClients::NONCE_PREFIX . $key ) ); ?>" />
					<?php /* translators: %s: client name. */ ?>
					<button type="submit" class="button button-small button-link-delete" data-confirm="<?php echo esc_attr( sprintf( __( 'Disconnect %s? It loses access to this site at once and has to sign in again.', 'stonewright' ), $name ) ); ?>">
						<?php esc_html_e( 'Disconnect', 'stonewright' ); ?><span class="screen-reader-text"> <?php echo esc_html( $name ); ?></span>
					</button>
				</form>
			</td>
		</tr>
		<?php
	}

	private static function render_time( ?int $timestamp, string $fallback ): void {
		if ( null === $timestamp ) {
			echo esc_html( $fallback );
			return;
		}
		$format = trim( (string) get_option( 'date_format', 'M j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
		?>
		<time datetime="<?php echo esc_attr( gmdate( 'c', $timestamp ) ); ?>"><?php echo esc_html( (string) wp_date( $format, $timestamp ) ); ?></time>
		<?php
	}

	private static function render_notice( string $notice ): void {
		$notices = [
			'disconnected' => [ 'success', 'status', __( 'The client was disconnected. It has to sign in again to use this site.', 'stonewright' ) ],
			'none'         => [ 'info', 'status', __( 'That client had no active sign-in, so nothing changed.', 'stonewright' ) ],
			'failed'       => [ 'error', 'alert', __( 'The client could not be disconnected. Try again; the attempt is recorded in the audit log.', 'stonewright' ) ],
		];
		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}
		[ $type, $role, $message ] = $notices[ $notice ];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> inline sw-notice" role="<?php echo esc_attr( $role ); ?>"><p><?php echo esc_html( $message ); ?></p></div>
		<?php
	}
}
