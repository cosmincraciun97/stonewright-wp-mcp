<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The Setup screen keeps stored API keys and the bridge token out of the page:
 * a stored secret shows as a placeholder, an empty or unchanged field keeps it,
 * a new value replaces it and an explicit request clears it.
 *
 * @covers \Stonewright\WpMcp\Admin\ConfigurationPage
 */
final class ConfigurationPageSecretsTest extends TestCase {

	private const UNSPLASH = 'synthetic-unsplash-key-0001';
	private const PEXELS   = 'synthetic-pexels-key-0002';
	private const BRIDGE   = 'synthetic-bridge-token-0003';

	/** @var array<string, string> */
	private const OPTIONS = [
		'stonewright_unsplash_access_key' => self::UNSPLASH,
		'stonewright_pexels_api_key'      => self::PEXELS,
		'stonewright_companion_token'     => self::BRIDGE,
	];

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
		$GLOBALS['stonewright_test_registered_settings']            = [];
		$_GET                                                       = [];
		$_POST                                                      = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_current_user_id']     = 0;
		$GLOBALS['stonewright_test_user_meta']           = [];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$_GET                                            = [];
		$_POST                                           = [];
	}

	private static function store_all(): void {
		foreach ( self::OPTIONS as $option => $value ) {
			update_option( $option, $value );
		}
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

	private static function sanitizer( string $option ): callable {
		ConfigurationPage::register_settings();
		foreach ( $GLOBALS['stonewright_test_registered_settings'] as $registered ) {
			if ( $option === $registered['option'] ) {
				return $registered['args']['sanitize_callback'];
			}
		}
		self::fail( 'No setting registered for ' . $option );
	}

	/** Posts the settings form the way options.php hands each field to the sanitizer. */
	private static function as_settings_form( array $extra = [] ): void {
		$_POST = array_merge( [ 'option_page' => 'stonewright_settings', 'action' => 'update' ], $extra );
	}

	// -------------------------------------------------------------------------
	// Page output
	// -------------------------------------------------------------------------

	public function test_no_stored_secret_is_written_into_the_page(): void {
		self::store_all();

		$html = self::render();

		foreach ( self::OPTIONS as $option => $secret ) {
			self::assertFalse( str_contains( $html, $secret ), $option . " leaks into the page" );
			self::assertFalse( str_contains( $html, base64_encode( $secret ) ), $option . " leaks into the page" );
			self::assertFalse( str_contains( $html, rawurlencode( $secret ) ), $option . " leaks into the page" );
			self::assertFalse( str_contains( $html, htmlspecialchars( $secret, ENT_QUOTES ) ), $option . " leaks into the page" );
		}
	}

	public function test_secret_inputs_stay_empty_and_say_that_a_value_is_stored(): void {
		self::store_all();

		$html = self::render();

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			self::assertSame( 1, preg_match( '/<input[^>]*name="' . preg_quote( $option, '/' ) . '"[^>]*>/', $html, $input ), $option );
			self::assertStringContainsString( 'type="password"', $input[0] );
			self::assertDoesNotMatchRegularExpression( '/\svalue="[^"]+"/', $input[0], $option . ' has no value attribute content.' );
			self::assertStringContainsString( 'placeholder="', $input[0] );
			self::assertStringContainsString( 'autocomplete="new-password"', $input[0] );
		}
		self::assertSame( count( self::OPTIONS ), substr_count( $html, 'data-stonewright-secret-stored' ) );
	}

	public function test_each_stored_secret_offers_an_explicit_clear_control(): void {
		self::store_all();

		$html = self::render();

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			self::assertMatchesRegularExpression(
				'/<input[^>]*type="checkbox"[^>]*name="stonewright_clear_secrets\[\]"[^>]*value="' . preg_quote( $option, '/' ) . '"/',
				$html,
				$option
			);
		}
	}

	public function test_a_site_without_stored_secrets_shows_plain_empty_fields_and_no_clear_control(): void {
		$html = self::render();

		self::assertStringNotContainsString( 'data-stonewright-secret-stored', $html );
		self::assertStringNotContainsString( 'stonewright_clear_secrets', $html );
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			self::assertSame( 1, preg_match( '/<input[^>]*name="' . preg_quote( $option, '/' ) . '"[^>]*>/', $html, $input ), $option );
			self::assertStringNotContainsString( 'placeholder="Stored', $input[0] );
		}
	}

	public function test_bridge_launch_values_use_a_placeholder_for_the_stored_token(): void {
		self::store_all();

		$html = self::render();

		self::assertSame( 1, preg_match( '/<pre[^>]*data-stonewright-bridge-token-source="stonewright_companion_token"[^>]*>(.*?)<\/pre>/s', $html, $block ) );
		self::assertStringContainsString( 'COMPANION_BEARER_TOKEN=&lt;your-saved-bridge-token&gt;', $block[1] );
		self::assertStringNotContainsString( self::BRIDGE, $block[1] );
	}

	public function test_bridge_script_substitutes_only_a_token_typed_or_generated_in_the_tab(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/admin.js' );

		self::assertStringContainsString( 'data-stonewright-bridge-token-placeholder', $script );
	}

	// -------------------------------------------------------------------------
	// Saving
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function secret_options(): array {
		return [
			'unsplash key' => [ 'stonewright_unsplash_access_key' ],
			'pexels key'   => [ 'stonewright_pexels_api_key' ],
			'bridge token' => [ 'stonewright_companion_token' ],
		];
	}

	/** @dataProvider secret_options */
	public function test_an_empty_submitted_field_keeps_the_stored_secret( string $option ): void {
		self::store_all();
		self::as_settings_form();

		self::assertSame( self::OPTIONS[ $option ], ( self::sanitizer( $option ) )( '' ) );
		self::assertSame( self::OPTIONS[ $option ], ( self::sanitizer( $option ) )( null ), 'A field missing from the post keeps it too.' );
	}

	/** @dataProvider secret_options */
	public function test_the_unchanged_mask_keeps_the_stored_secret( string $option ): void {
		self::store_all();
		self::as_settings_form();

		self::assertSame( self::OPTIONS[ $option ], ( self::sanitizer( $option ) )( ConfigurationPage::SECRET_MASK ) );
	}

	/** @dataProvider secret_options */
	public function test_a_new_value_replaces_the_stored_secret( string $option ): void {
		self::store_all();
		self::as_settings_form();

		self::assertSame( 'replacement-value', ( self::sanitizer( $option ) )( '  replacement-value ' ) );
	}

	/** @dataProvider secret_options */
	public function test_the_clear_request_removes_the_stored_secret( string $option ): void {
		self::store_all();
		self::as_settings_form( [ 'stonewright_clear_secrets' => [ $option ] ] );

		self::assertSame( '', ( self::sanitizer( $option ) )( '' ) );
	}

	/** @dataProvider secret_options */
	public function test_the_clear_request_names_only_its_own_secret( string $option ): void {
		self::store_all();
		$other = 'stonewright_companion_token' === $option ? 'stonewright_pexels_api_key' : 'stonewright_companion_token';
		self::as_settings_form( [ 'stonewright_clear_secrets' => [ $other ] ] );

		self::assertSame( self::OPTIONS[ $option ], ( self::sanitizer( $option ) )( '' ) );
	}

	/** @dataProvider secret_options */
	public function test_updates_from_code_are_not_changed_by_the_form_rules( string $option ): void {
		self::store_all();
		$_POST = [];

		self::assertSame( '', ( self::sanitizer( $option ) )( '' ), 'Code that clears the option still clears it.' );
		self::assertSame( 'from-code', ( self::sanitizer( $option ) )( 'from-code' ) );
	}

	public function test_secrets_are_read_by_their_users_unchanged(): void {
		self::store_all();

		self::assertSame( self::UNSPLASH, (string) get_option( 'stonewright_unsplash_access_key', '' ) );
		self::assertSame( self::BRIDGE, (string) get_option( 'stonewright_companion_token', '' ) );
	}
}
