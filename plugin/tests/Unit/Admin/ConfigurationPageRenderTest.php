<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Admin\SetupState;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The Setup screen with its OAuth connect panel and the Application Password route.
 *
 * @covers \Stonewright\WpMcp\Admin\ConfigurationPage
 */
final class ConfigurationPageRenderTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		$this->http = new HttpRig();
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$GLOBALS['stonewright_test_user_caps']                      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']                = 1;
		$GLOBALS['stonewright_test_user_meta']                      = [];
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$_GET = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_meta']       = [];
		unset( $_SERVER['REMOTE_ADDR'] );
		$_GET = [];
	}

	private static function render(): string {
		ob_start();
		try {
			ConfigurationPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Whether each panel of one authentication method is hidden, in page order.
	 *
	 * @return list<bool>
	 */
	private static function panels( string $html, string $method ): array {
		preg_match_all( '/<div data-stonewright-auth-panel="' . preg_quote( $method, '/' ) . '"([^>]*)>/', $html, $matches );

		return array_map( static fn ( string $attributes ): bool => str_contains( $attributes, ' hidden' ), $matches[1] );
	}

	private static function auth_button( string $html, string $method ): string {
		self::assertSame( 1, preg_match( '/<button[^>]*data-stonewright-auth-method="' . preg_quote( $method, '/' ) . '"[^>]*>/s', $html, $match ), $method );
		return $match[0];
	}

	public function test_an_oauth_site_shows_status_client_guides_and_connections(): void {
		$this->http->rig->connect();

		$html = self::render();

		self::assertStringContainsString( 'Choose how clients sign in', $html );
		self::assertStringContainsString( 'sw-ui-badge--ok sw-ui-badge--dot">On</span>', $html );
		self::assertStringContainsString( 'https://example.com/wp-json/mcp/stonewright-oauth', $html );
		self::assertStringNotContainsString( 'disabled', self::auth_button( $html, 'oauth' ) );
		self::assertStringContainsString( 'aria-checked="true"', self::auth_button( $html, 'oauth' ) );
		self::assertSame( count( ClientCatalog::slugs() ), substr_count( $html, '<details class="sw-ui-disclosure sw-connect-client"' ) );
		self::assertMatchesRegularExpression( '/id="sw-connect-client-claude-code"[^>]* open>/', $html, 'The saved or default client starts open.' );
		self::assertStringContainsString( 'claude mcp add --transport http stonewright-example-test https://example.com/wp-json/mcp/stonewright-oauth', $html );
		self::assertStringContainsString( 'id="' . SignInPanel::CONNECTIONS_ID . '"', $html );
		self::assertStringContainsString( 'Synthetic client', $html );
		self::assertStringContainsString( 'name="action" value="' . ConnectedClients::ACTION . '"', $html );
		self::assertStringContainsString( 'href="#' . SignInPanel::CONNECTIONS_ID . '"', $html );
		self::assertStringNotContainsString( 'stonewright-connected-apps', $html );
	}

	public function test_the_password_route_keeps_its_client_picker_and_snippets(): void {
		$html = self::render();

		self::assertStringContainsString( 'data-stonewright-client-card="claude-code"', $html );
		self::assertStringContainsString( 'data-stonewright-method-picker', $html );
		self::assertStringContainsString( esc_html( "claude mcp add 'stonewright-example-test'" ), $html );
		self::assertStringContainsString( esc_html( "claude mcp add --transport http 'stonewright-example-test' 'https://example.test/wp-json/mcp/stonewright'" ), $html );
		self::assertStringContainsString( 'data-stonewright-app-password-form', $html );
		self::assertStringContainsString( 'id="stonewright-connect-prompt-full"', $html );
		self::assertMatchesRegularExpression( '/id="sw-client-panel-cursor".*?href="cursor:\/\/anysphere\.cursor-deeplink\/mcp\/install\?name=stonewright-example-test/s', $html );
		self::assertStringNotContainsString( 'data-oauth-support', $html );
	}

	public function test_oauth_choice_shows_the_guides_and_hides_the_password_snippets(): void {
		$html = self::render();

		self::assertSame( [ false, false ], self::panels( $html, 'oauth' ) );
		self::assertSame( [ true, true ], self::panels( $html, 'application-password' ) );
	}

	public function test_a_saved_password_choice_hides_the_oauth_panels(): void {
		update_user_meta( 1, SetupState::META_AUTH_METHOD, 'application-password' );

		$html = self::render();

		self::assertSame( [ true, true ], self::panels( $html, 'oauth' ) );
		self::assertSame( [ false, false ], self::panels( $html, 'application-password' ) );
		self::assertStringContainsString( 'aria-checked="true"', self::auth_button( $html, 'application-password' ) );
	}

	public function test_a_site_that_cannot_offer_oauth_explains_why_and_falls_back_to_passwords(): void {
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'http://example.com' ) );

		$html = self::render();

		self::assertStringContainsString( 'sw-ui-badge sw-ui-badge--dot">Off</span>', $html );
		self::assertStringContainsString( 'Why OAuth sign-in is off', $html );
		self::assertStringContainsString( 'plain HTTP', $html );
		self::assertStringContainsString( 'disabled', self::auth_button( $html, 'oauth' ) );
		self::assertSame( [ true, true ], self::panels( $html, 'oauth' ) );
		self::assertStringContainsString( 'No AI client is signed in with OAuth.', $html );
	}

	public function test_a_disconnect_outcome_is_announced(): void {
		$_GET[ ConnectedClients::NOTICE_ARG ] = 'disconnected';

		self::assertStringContainsString( 'The client was disconnected.', self::render() );
	}

	public function test_nothing_renders_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		self::assertSame( '', self::render() );
	}

	public function test_register_wires_the_disconnect_handler(): void {
		$GLOBALS['stonewright_test_actions'] = [];

		ConfigurationPage::register();

		self::assertSame(
			[ ConnectedClients::class, 'handle' ],
			$GLOBALS['stonewright_test_actions'][ 'admin_post_' . ConnectedClients::ACTION ][0]['callback']
		);
		$GLOBALS['stonewright_test_actions'] = [];
	}
}
