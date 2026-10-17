<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Connect;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\Connect\ClientInstructions;
use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Admin\Connect\SignInStatus;
use Stonewright\WpMcp\Authorization\WordPress\SiteProfile;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * Markup of the connect panel: status, per-client sign-in guides and the connected
 * clients list with its disconnect forms.
 *
 * @covers \Stonewright\WpMcp\Admin\Connect\SignInPanel
 */
final class SignInPanelTest extends TestCase {

	private const KEY = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
	}

	private static function html( callable $render ): string {
		ob_start();
		try {
			$render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private static function status( ?SiteProfile $site = null ): array {
		return SignInStatus::describe( $site ?? HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );
	}

	/** @return array<string, mixed> */
	private static function connection( array $overrides = [] ): array {
		return array_replace(
			[
				'client_key'      => self::KEY,
				'name'            => 'Second <b>client</b>',
				'identity'        => 'Registered automatically',
				'people'          => [ 'Editor A', 'Editor B' ],
				'grants'          => 2,
				'connected_since' => StorageRig::T,
				'last_used'       => StorageRig::T + 30,
			],
			$overrides
		);
	}

	public function test_status_shows_on_with_the_transport_and_copyable_addresses(): void {
		$html = self::html( static fn () => SignInPanel::render_status( self::status() ) );

		self::assertStringContainsString( 'OAuth sign-in', $html );
		self::assertStringContainsString( 'sw-ui-badge--ok sw-ui-badge--dot">On</span>', $html );
		self::assertStringContainsString( '<dd>HTTPS</dd>', $html );
		self::assertStringContainsString( '<code class="sw-ui-copy__value" id="sw-connect-mcp-url">https://example.com/wp-json/mcp/stonewright-oauth</code>', $html );
		self::assertStringContainsString( 'data-sw-ui-copy="#sw-connect-mcp-url"', $html );
		self::assertStringContainsString( 'aria-label="Copy MCP server URL"', $html );
		self::assertStringContainsString( '<code class="sw-ui-copy__value" id="sw-connect-server-name">stonewright-example-test</code>', $html );
		self::assertStringNotContainsString( 'Why OAuth sign-in is off', $html );
	}

	public function test_status_lists_every_reason_sign_in_is_off_with_its_fix(): void {
		$html = self::html( static fn () => SignInPanel::render_status( self::status( HttpRig::site( false, 'production', 'pretty', 'http://example.com' ) ) ) );

		self::assertStringContainsString( 'sw-ui-badge sw-ui-badge--dot">Off</span>', $html );
		self::assertStringContainsString( 'sw-ui-callout--warn', $html );
		self::assertStringContainsString( 'Why OAuth sign-in is off', $html );
		self::assertStringContainsString( 'turned off', $html );
		self::assertStringContainsString( 'plain HTTP', $html );
		self::assertStringContainsString( 'WP_ENVIRONMENT_TYPE', $html );
		self::assertSame( 2, substr_count( $html, '<li class="sw-connect-issue">' ) );
	}

	public function test_every_client_is_a_disclosure_and_the_selected_one_is_open(): void {
		$html = self::html( static fn () => SignInPanel::render_guides( self::status(), 'cursor' ) );

		self::assertSame( count( ClientCatalog::slugs() ), substr_count( $html, '<details class="sw-ui-disclosure sw-connect-client"' ) );
		self::assertMatchesRegularExpression( '/<details class="sw-ui-disclosure sw-connect-client" id="sw-connect-client-cursor"[^>]* open>/', $html );
		self::assertDoesNotMatchRegularExpression( '/<details class="sw-ui-disclosure sw-connect-client" id="sw-connect-client-claude-code"[^>]* open>/', $html );
		self::assertStringContainsString( '<span class="sw-connect-client__name">Claude Code</span>', $html );
		self::assertStringContainsString( '<span class="sw-connect-client__name">VS Code with GitHub Copilot</span>', $html );
	}

	public function test_aliases_open_their_canonical_client(): void {
		$html = self::html( static fn () => SignInPanel::render_guides( self::status(), 'vscode' ) );

		self::assertMatchesRegularExpression( '/id="sw-connect-client-vscode-copilot"[^>]* open>/', $html );
	}

	public function test_every_copy_button_targets_a_rendered_block(): void {
		$html = self::html(
			static function (): void {
				SignInPanel::render_status( self::status() );
				SignInPanel::render_guides( self::status(), 'claude-code' );
			}
		);

		self::assertGreaterThan( 2, (int) preg_match_all( '/data-sw-ui-copy="#([^"]+)"/', $html, $targets ) );
		foreach ( array_unique( $targets[1] ) as $target ) {
			self::assertSame( 1, substr_count( $html, 'id="' . $target . '"' ), $target );
		}
		self::assertStringContainsString( '<pre class="sw-ui-code__body sw-connect-snippet__code" id="sw-connect-claude-code-add" tabindex="0" aria-label="Add the server: Add the server for Claude Code"><code>claude mcp add --transport http stonewright-example-test https://example.com/wp-json/mcp/stonewright-oauth</code></pre>', $html );
		self::assertStringContainsString( '.mcp.json', $html );
	}

	/** The guide of one client as the panel prints it, from its summary to the next one. */
	private static function guide_markup( string $html, string $slug ): string {
		self::assertSame( 1, preg_match( '/<details class="sw-ui-disclosure sw-connect-client" id="sw-connect-client-' . preg_quote( $slug, '/' ) . '".*?<\/details>/s', $html, $found ), $slug );

		return $found[0];
	}

	public function test_the_server_name_and_url_in_a_step_are_copyable_values(): void {
		$html  = self::html( static fn () => SignInPanel::render_guides( self::status(), '' ) );
		$guide = self::guide_markup( $html, 'claude-ai' );
		$url   = 'https://example.com/wp-json/mcp/stonewright-oauth';

		self::assertStringContainsString( '<li>Enter a name such as <span class="sw-ui-copy-chip"><code class="sw-ui-copy-chip__value" id="sw-connect-claude-ai-s2-1">stonewright-example-test</code>', $guide );
		self::assertStringContainsString( '</span> and the server URL <span class="sw-ui-copy-chip"><code class="sw-ui-copy-chip__value" id="sw-connect-claude-ai-s2-2">' . $url . '</code>', $guide );
		self::assertStringContainsString( 'aria-label="Copy server name" data-sw-ui-copy="#sw-connect-claude-ai-s2-1" data-sw-ui-copy-status="#sw-connect-claude-ai-s2-1-status"', $guide );
		self::assertStringContainsString( 'aria-label="Copy MCP server URL" data-sw-ui-copy="#sw-connect-claude-ai-s2-2"', $guide );
		self::assertSame( 2, substr_count( $guide, 'sw-ui-copy-chip__value' ) );
		self::assertStringContainsString( '<li>In Claude, open Customize &gt; Connectors and choose Add custom connector.</li>', $guide, 'A step without values stays plain text.' );

		self::assertSame( 1, substr_count( self::guide_markup( $html, 'claude-code' ), 'sw-ui-copy-chip__value' ) );
		self::assertSame( 2, substr_count( self::guide_markup( $html, 'chatgpt' ), 'sw-ui-copy-chip__value' ) );
		self::assertSame( 1, substr_count( self::guide_markup( $html, 'generic-mcp' ), 'sw-ui-copy-chip__value' ) );
		self::assertSame( 1, substr_count( self::guide_markup( $html, 'gemini-cli' ), 'sw-ui-copy-chip__value' ) );
		self::assertSame( 0, substr_count( self::guide_markup( $html, 'cursor' ), 'sw-ui-copy-chip__value' ) );
		self::assertStringContainsString( '<p class="sw-connect-signin"><strong>How sign-in starts:</strong> Sign-in starts on its own after the first 401 from this site. Inside the CLI, /mcp auth <span class="sw-ui-copy-chip">', self::guide_markup( $html, 'gemini-cli' ) );
	}

	public function test_every_chip_status_resolves_and_the_text_of_each_step_is_unchanged(): void {
		$status = self::status();
		$html   = self::html( static fn () => SignInPanel::render_guides( $status, '' ) );

		self::assertGreaterThan( 8, (int) preg_match_all( '/data-sw-ui-copy-status="#([^"]+)"/', $html, $statuses ) );
		foreach ( $statuses[1] as $target ) {
			self::assertSame( 1, substr_count( $html, 'id="' . $target . '"' ), $target );
		}
		foreach ( ClientInstructions::all( $status['mcp_url'], $status['server_name'] ) as $guide ) {
			$markup = self::guide_markup( $html, $guide['slug'] );
			preg_match( '/<ol class="sw-connect-steps">(.*?)<\/ol>/s', $markup, $list );
			preg_match_all( '/<li>(.*?)<\/li>/s', $list[1], $items );
			$printed = array_map( static fn ( string $item ): string => html_entity_decode( strip_tags( (string) preg_replace( '/<button.*?<\/button>/s', '', $item ) ), ENT_QUOTES ), $items[1] );
			self::assertSame( $guide['steps'], $printed, $guide['slug'] );
		}
	}

	public function test_a_value_with_markup_in_a_step_is_escaped(): void {
		$site = new SiteProfile(
			true,
			'production',
			[
				'issuer'                 => 'https://example.com',
				'resource'               => 'https://example.com/wp-json/mcp/"><script>alert(1)</script>',
				'authorization_endpoint' => 'https://example.com/wp-admin/admin.php?page=stonewright-oauth-authorize',
				'consent_endpoint'       => 'https://example.com/wp-admin/admin.php?page=stonewright-oauth-consent',
				'token_endpoint'         => 'https://example.com/wp-json/stonewright/v1/oauth/token',
				'registration_endpoint'  => 'https://example.com/wp-json/stonewright/v1/oauth/register',
				'revocation_endpoint'    => 'https://example.com/wp-json/stonewright/v1/oauth/revoke',
				'introspection_endpoint' => 'https://example.com/wp-json/stonewright/v1/oauth/introspect',
			]
		);
		$guide = self::guide_markup( self::html( static fn () => SignInPanel::render_guides( self::status( $site ), '' ) ), 'generic-mcp' );

		self::assertStringNotContainsString( '<script', $guide );
		self::assertStringContainsString( 'sw-connect-generic-mcp-s1-1">https://example.com/wp-json/mcp/&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;</code>', $guide );
	}

	public function test_install_links_keep_their_client_schemes(): void {
		$html = self::html( static fn () => SignInPanel::render_guides( self::status(), '' ) );

		self::assertMatchesRegularExpression( '/href="cursor:\/\/anysphere\.cursor-deeplink\/mcp\/install\?name=stonewright-example-test(&|&amp;|&#038;)config=[A-Za-z0-9+\/=]+"/', $html );
		self::assertStringContainsString( 'href="vscode:mcp/install?%7B%22name%22', $html );
		self::assertStringContainsString( 'href="vscode-insiders:mcp/install?', $html );
		self::assertStringContainsString( 'href="https://claude.ai/customize/connectors" target="_blank" rel="noopener noreferrer"', $html );
	}

	public function test_limits_and_the_password_route_are_explained(): void {
		$html = self::html( static fn () => SignInPanel::render_guides( self::status(), '' ) );

		self::assertStringContainsString( 'Hosted apps', $html );
		self::assertStringContainsString( 'Gemini CLI signs in only to HTTPS sites on a public address', $html );
		self::assertStringContainsString( 'Application Password', $html );
		self::assertStringContainsString( '<code>https://example.test/wp-json/mcp/stonewright</code>', $html );
		self::assertStringContainsString( 'href="#stonewright-application-password"', $html );
		self::assertStringNotContainsString( 'This site looks local', $html );
		self::assertStringNotContainsString( 'Cannot sign in to this site', $html );
	}

	public function test_a_local_site_flags_the_clients_that_cannot_reach_it(): void {
		$html = self::html( static fn () => SignInPanel::render_guides( self::status( HttpRig::site( true, 'local', 'pretty', 'http://site-a.test' ) ), '' ) );

		self::assertStringContainsString( 'This site looks local', $html );
		foreach ( [ 'claude-ai', 'claude-desktop', 'chatgpt', 'gemini-cli', 'claude-code' ] as $slug ) {
			self::assertMatchesRegularExpression( '/id="sw-connect-client-' . preg_quote( $slug, '/' ) . '".*?<\/summary>/s', $html );
			preg_match( '/id="sw-connect-client-' . preg_quote( $slug, '/' ) . '".*?<\/summary>/s', $html, $summary );
			self::assertStringContainsString( 'Cannot sign in to this site', $summary[0], $slug );
		}
		preg_match( '/id="sw-connect-client-cursor".*?<\/summary>/s', $html, $cursor );
		self::assertStringNotContainsString( 'Cannot sign in to this site', $cursor[0] );
		self::assertStringContainsString( 'site-a.test', $html );
	}

	public function test_every_value_is_escaped(): void {
		$site = new SiteProfile(
			true,
			'production',
			[
				'issuer'                 => 'https://example.com',
				'resource'               => 'https://example.com/wp-json/mcp/"><script>alert(1)</script>',
				'authorization_endpoint' => 'https://example.com/wp-admin/admin.php?page=stonewright-oauth-authorize',
				'consent_endpoint'       => 'https://example.com/wp-admin/admin.php?page=stonewright-oauth-consent',
				'token_endpoint'         => 'https://example.com/wp-json/stonewright/v1/oauth/token',
				'registration_endpoint'  => 'https://example.com/wp-json/stonewright/v1/oauth/register',
				'revocation_endpoint'    => 'https://example.com/wp-json/stonewright/v1/oauth/revoke',
				'introspection_endpoint' => 'https://example.com/wp-json/stonewright/v1/oauth/introspect',
			]
		);
		$html = self::html(
			static function () use ( $site ): void {
				SignInPanel::render_status( self::status( $site ) );
				SignInPanel::render_guides( self::status( $site ), '' );
				SignInPanel::render_connections( [ self::connection( [ 'name' => '<img src=x onerror=alert(2)>', 'people' => [ '<i>Editor</i>' ] ] ) ], '' );
			}
		);

		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=alert(2)&gt;', $html );
		self::assertStringNotContainsString( '<i>Editor</i>', $html );
	}

	public function test_read_only_parts_need_no_script(): void {
		$html = self::html(
			static function (): void {
				SignInPanel::render_status( self::status() );
				SignInPanel::render_guides( self::status(), '' );
				SignInPanel::render_connections( [ self::connection() ], '' );
			}
		);

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( ' hidden', $html );
	}

	public function test_connections_list_clients_with_a_disconnect_form_each(): void {
		$html = self::html( static fn () => SignInPanel::render_connections( [ self::connection() ], '' ) );

		self::assertStringContainsString( 'id="' . SignInPanel::CONNECTIONS_ID . '"', $html );
		self::assertStringContainsString( 'Connected OAuth clients', $html );
		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">Connected OAuth clients</caption>', $html );
		self::assertStringContainsString( 'Second &lt;b&gt;client&lt;/b&gt;', $html );
		self::assertStringContainsString( 'Registered automatically', $html );
		self::assertStringContainsString( '2 active sign-ins', $html );
		self::assertStringContainsString( 'Editor A, Editor B', $html );
		self::assertStringContainsString( '<time datetime="2026-01-01T00:00:30+00:00">', $html );
		self::assertStringContainsString( 'Disconnect<span class="sw-ui-visually-hidden"> Second &lt;b&gt;client&lt;/b&gt;</span>', $html, 'Each action names its client.' );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="' . ConnectedClients::ACTION . '"', $html );
		self::assertStringContainsString( 'name="client" value="' . self::KEY . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( ConnectedClients::NONCE_PREFIX . self::KEY ) . '"', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm" data-confirm="Disconnect Second &lt;b&gt;client&lt;\/b&gt;\?/', $html, 'A disconnect is a small destructive button, never a primary one.' );
	}

	public function test_unknown_dates_read_as_such(): void {
		$html = self::html( static fn () => SignInPanel::render_connections( [ self::connection( [ 'connected_since' => null, 'last_used' => null, 'grants' => 1 ] ) ], '' ) );

		self::assertStringContainsString( 'Unknown', $html );
		self::assertStringContainsString( 'Not yet', $html );
		self::assertStringContainsString( '1 active sign-in', $html );
		self::assertStringNotContainsString( '<time', $html );
	}

	public function test_an_empty_list_and_the_outcome_notices(): void {
		$empty = self::html( static fn () => SignInPanel::render_connections( [], 'disconnected' ) );
		self::assertStringContainsString( 'No AI client is signed in with OAuth.', $empty );
		self::assertStringNotContainsString( '<table', $empty );
		self::assertMatchesRegularExpression( '/class="sw-ui-notice sw-ui-notice--ok" role="status"/', $empty );

		$failed = self::html( static fn () => SignInPanel::render_connections( [], 'failed' ) );
		self::assertMatchesRegularExpression( '/class="sw-ui-notice sw-ui-notice--danger" role="alert"/', $failed );

		$none = self::html( static fn () => SignInPanel::render_connections( [], 'none' ) );
		self::assertStringContainsString( 'nothing changed', $none );
	}
}
