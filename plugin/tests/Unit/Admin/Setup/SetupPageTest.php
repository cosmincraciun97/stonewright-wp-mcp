<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Setup;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\Setup\ApplicationPasswords;
use Stonewright\WpMcp\Admin\Setup\Nonce;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The structure of the Setup page: four views as tabs, one of them shown, the controls in the view they belong
 * to, and the states each step can be in.
 *
 * @covers \Stonewright\WpMcp\Admin\Setup\SetupPage
 * @covers \Stonewright\WpMcp\Admin\Setup\SetupTabs
 * @covers \Stonewright\WpMcp\Admin\Setup\SettingsForm
 * @covers \Stonewright\WpMcp\Admin\Setup\Nonce
 * @covers \Stonewright\WpMcp\Admin\Setup\ApplicationPasswords
 */
final class SetupPageTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		Html::reset_ids();
		$this->http = new HttpRig();
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']  = 1;
		$GLOBALS['stonewright_test_user_meta']        = [];
		$GLOBALS['stonewright_test_app_passwords']    = [];
		$GLOBALS['stonewright_test_environment_type'] = 'local';
		$_GET                                         = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		unset( $GLOBALS['stonewright_test_environment_type'] );
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_app_passwords']   = [];
		$_GET                                        = [];
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

	/** @return list<string> Ids of the panels the server shows. */
	private static function shown_panels( string $html ): array {
		preg_match_all( '/<div class="sw-ui-tabs__panel" role="tabpanel" id="sw-setup-panel-([a-z-]+)" aria-labelledby="[^"]+"(?! hidden)>/', $html, $matches );

		return $matches[1];
	}

	public function test_the_four_views_are_tabs_and_get_started_is_shown_by_default(): void {
		$html = self::render();

		self::assertSame( 4, substr_count( $html, 'class="sw-ui-tabs__tab" role="tab"' ) );
		self::assertSame( [ 'get-started' ], self::shown_panels( $html ) );
		self::assertMatchesRegularExpression( '/id="sw-setup-tab-get-started"[^>]*aria-selected="true"/', $html );
		foreach ( [ 'Get started', 'Settings', 'Connections', 'Updates' ] as $label ) {
			self::assertStringContainsString( '>' . $label, $html );
		}
	}

	public function test_the_tab_argument_shows_that_view_and_hides_the_rest(): void {
		foreach ( [ 'settings', 'connections', 'updates' ] as $tab ) {
			$_GET['tab'] = $tab;

			self::assertSame( [ $tab ], self::shown_panels( self::render() ), $tab );
		}
	}

	public function test_the_settings_form_and_the_domain_lock_are_in_the_settings_view_only(): void {
		$html     = self::render();
		$settings = substr( $html, (int) strpos( $html, 'id="sw-setup-panel-settings"' ) );
		$settings = substr( $settings, 0, (int) strpos( $settings, 'id="sw-setup-panel-connections"' ) );
		$started  = substr( $html, (int) strpos( $html, 'id="sw-setup-panel-get-started"' ), (int) strpos( $html, 'id="sw-setup-panel-settings"' ) - (int) strpos( $html, 'id="sw-setup-panel-get-started"' ) );

		self::assertStringContainsString( 'action="options.php"', $settings );
		self::assertStringContainsString( 'id="stonewright-domain-lock"', $settings );
		self::assertStringContainsString( 'name="stonewright_enabled"', $settings );
		self::assertStringNotContainsString( 'name="stonewright_enabled"', $started );
		self::assertStringContainsString( 'data-stonewright-app-password-form', $started );
		self::assertSame( 1, substr_count( $html, 'action="options.php"' ), 'One settings form.' );
	}

	public function test_step_one_only_reports_and_points_to_settings(): void {
		$off = self::render();
		self::assertStringContainsString( 'Stonewright is off.', $off );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=stonewright&tab=settings"', $off );
		self::assertStringContainsString( 'AI abilities off', $off );

		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$on = self::render();
		self::assertStringContainsString( 'AI abilities are on for this site.', $on );
		self::assertStringContainsString( 'AI abilities on', $on );
		self::assertStringNotContainsString( 'Stonewright is off.', $on );
	}

	public function test_only_the_steps_that_need_it_carry_a_state(): void {
		$html = self::render();

		self::assertStringContainsString( '1. Turn on AI abilities', $html );
		self::assertStringContainsString( '2. Choose how clients sign in', $html );
		self::assertStringContainsString( '3. Connect your AI client', $html );
		self::assertStringContainsString( '4. Verify the connection', $html );
		self::assertSame( 3, substr_count( $html, 'sw-ui-badge--accent' ) + substr_count( $html, '>To do<' ) + substr_count( $html, '>Done<' ) - 1, 'Steps 1 to 3 say where they stand; the OAuth choice has its own accent badge.' );
	}

	public function test_the_page_has_no_inline_style_one_h1_and_no_older_copy_hook(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		foreach ( [ '', 'settings', 'connections', 'updates' ] as $tab ) {
			$_GET['tab'] = $tab;
			$html        = self::render();

			self::assertDoesNotMatchRegularExpression( '/\sstyle=/', $html, 'No inline style (' . $tab . ').' );
			self::assertSame( 1, preg_match_all( '/<h1[\s>]/', $html ), 'One h1 (' . $tab . ').' );
			// The older copy path ends in a dialog that only the older stylesheet draws; Setup copies through the layer.
			self::assertStringNotContainsString( 'data-stonewright-copy=', $html, $tab );
		}
	}

	public function test_a_production_site_in_development_mode_is_an_alert_and_in_production_safe_mode_a_note(): void {
		$GLOBALS['stonewright_test_environment_type']               = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_options']['stonewright_mode']    = 'development';

		$alert = self::render();
		self::assertStringContainsString( 'P0: WordPress environment is production but Stonewright mode is not production-safe.', $alert );
		self::assertMatchesRegularExpression( '/class="sw-ui-notice sw-ui-notice--danger" role="alert" data-stonewright-severity="p0"/', $alert );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$note = self::render();
		self::assertStringContainsString( 'Production site detected.', $note );
		self::assertStringNotContainsString( 'P0:', $note );
	}

	public function test_saving_is_confirmed_on_the_settings_view(): void {
		$_GET = [ 'tab' => 'settings', 'settings-updated' => 'true' ];

		$html = self::render();

		self::assertStringContainsString( 'Settings saved', $html );
		self::assertStringContainsString( 'role="status"', $html );
	}

	public function test_the_connections_tab_counts_connected_clients_in_words(): void {
		self::assertStringNotContainsString( 'connected OAuth clients</span>', self::render(), 'No count while nothing is connected.' );

		$this->http->rig->connect();

		self::assertStringContainsString( '<span class="sw-ui-count sw-ui-num">1</span><span class="sw-ui-visually-hidden"> connected OAuth clients</span>', self::render() );
	}

	public function test_a_nonce_field_keeps_its_name_and_value_but_not_the_id_that_would_repeat(): void {
		$field = Nonce::field( 'stonewright_example_action' );

		self::assertStringContainsString( 'name="_wpnonce" value="test-nonce-stonewright_example_action"', $field );
		self::assertStringNotContainsString( ' id=', $field );
	}

	public function test_each_application_password_has_a_revoke_action_that_names_it(): void {
		$html = ApplicationPasswords::list_html(
			[
				[ 'uuid' => 'uuid-one', 'name' => 'Example laptop', 'created' => 1710000000 ],
				[ 'uuid' => '', 'name' => 'Unmanaged', 'created' => 0 ],
			]
		);

		self::assertStringContainsString( 'Manage existing application passwords (2)', $html );
		self::assertStringContainsString( 'Revoke<span class="sw-ui-visually-hidden"> Example laptop</span>', $html );
		self::assertStringContainsString( 'name="stonewright_app_password_uuid" value="uuid-one"', $html );
		self::assertStringContainsString( 'test-nonce-stonewright_revoke_application_password_uuid-one', $html );
		self::assertStringContainsString( 'Open profile to manage', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--danger' ), 'Revoke is destructive and never primary.' );
		self::assertStringContainsString( 'No existing application passwords found for this user.', ApplicationPasswords::list_html( [] ) );
	}
}
