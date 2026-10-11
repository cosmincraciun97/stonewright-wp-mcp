<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRuntimeNotice;

/**
 * The admin notice and the explicit route answer that replace a silent "no route" when no usable MCP
 * adapter is loaded.
 *
 * @covers \Stonewright\WpMcp\Core\McpRuntimeNotice
 */
final class McpRuntimeNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_extra_plugins'] = [ 'foreign-seo/foreign-seo.php' => [ 'Name' => 'Foreign SEO Suite', 'Version' => '2.0.0' ] ];
		$GLOBALS['stonewright_test_user_caps']     = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_rest_routes']   = [];
		$GLOBALS['stonewright_test_actions']       = [];
		$GLOBALS['stonewright_test_user_meta']     = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_extra_plugins'] );
		$GLOBALS['stonewright_test_user_caps']   = [];
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_user_meta']   = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
	}

	/** @param list<string> $blocking */
	private static function runtime( bool $compatible, array $blocking, bool $foreign = false, string $version = '0.4.0', string $owner = 'plugin:foreign-seo' ): void {
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue(
			null,
			[
				'compatible'       => $compatible,
				'adapter'          => [
					'selected_owner'   => $foreign || ! $compatible ? $owner : 'plugin:stonewright',
					'selected_version' => $compatible && ! $foreign ? '0.6.1' : $version,
					'selection_state'  => 'selected',
					'foreign_runtime'  => $foreign,
					'owner_details'    => [ [ 'owner' => 'plugin:foreign-seo', 'version' => '0.4.0', 'state' => 'autoloadable' ] ],
					'abi'              => [ 'status' => $compatible ? 'compatible' : 'incompatible', 'issues' => [] ],
				],
				'blocking_reasons' => $blocking,
			]
		);
	}

	private static function html( string $screen_id = 'stonewright_page_stonewright-status', ?int $now = null ): string {
		ob_start();
		McpRuntimeNotice::render( $screen_id, $now );

		return (string) ob_get_clean();
	}

	public function test_a_blocked_adapter_shows_an_error_notice_that_names_the_plugin_version_and_remedy(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		$html = self::html();

		self::assertStringContainsString( 'notice notice-error stonewright-notice', $html );
		self::assertStringContainsString( 'Foreign SEO Suite', $html );
		self::assertStringContainsString( '0.4.0', $html );
		self::assertStringContainsString( 'MCP clients cannot connect', $html );
		self::assertStringContainsString( 'deactivate', $html );
		self::assertStringContainsString( 'stonewright-troubleshoot', $html );
	}

	public function test_the_notice_is_silent_when_stonewrights_own_adapter_runs(): void {
		self::runtime( true, [] );

		self::assertSame( '', self::html() );
	}

	public function test_the_notice_is_silent_when_another_plugins_adapter_works(): void {
		self::runtime( true, [], true );

		self::assertSame( '', self::html() );
		self::assertSame( '', self::html( 'plugins' ) );
		self::assertSame( '', self::html( 'dashboard' ) );
	}

	public function test_a_blocked_adapter_shows_on_stonewright_screens_the_plugins_screen_and_the_dashboard_only(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		self::assertSame( '', self::html( 'edit-post' ) );
		self::assertSame( '', self::html( 'options-general' ) );
		self::assertNotSame( '', self::html( 'plugins' ) );
		self::assertNotSame( '', self::html( 'dashboard' ) );
		self::assertNotSame( '', self::html( 'toplevel_page_stonewright' ) );
		self::assertNotSame( '', self::html( 'stonewright_page_stonewright-troubleshoot' ) );
	}

	public function test_an_unknown_screen_shows_nothing_and_the_hook_reads_the_screen_itself(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		// do_action() passes an empty string to a callback when the action has no arguments.
		self::assertSame( '', self::html( '' ) );
		ob_start();
		McpRuntimeNotice::on_admin_notices();
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_notice_is_for_administrators(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false, 'edit_posts' => true ];

		self::assertSame( '', self::html() );
		self::assertSame( '', self::html( 'plugins' ) );
		self::assertSame( '', self::html( 'dashboard' ) );
	}

	public function test_the_notice_offers_a_nonce_protected_dismiss_link_that_names_seven_days(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		$html = self::html( 'dashboard' );

		self::assertStringContainsString( 'admin-post.php?action=stonewright_dismiss_mcp_runtime_notice', $html );
		self::assertStringContainsString( '_wpnonce=', $html );
		self::assertStringContainsString( 'Dismiss for 7 days', $html );
	}

	public function test_dismissing_hides_the_notice_for_this_user_for_seven_days(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		$now = 1_800_000_000;

		McpRuntimeNotice::dismiss( 7, $now );

		foreach ( [ 'dashboard', 'plugins', 'stonewright_page_stonewright-status' ] as $screen ) {
			self::assertSame( '', self::html( $screen, $now + 6 * 86400 ), $screen );
		}
		self::assertNotSame( '', self::html( 'dashboard', $now + 7 * 86400 + 1 ), 'It comes back after seven days.' );
	}

	public function test_a_dismissal_belongs_to_one_user(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		$now = 1_800_000_000;
		McpRuntimeNotice::dismiss( 7, $now );

		$GLOBALS['stonewright_test_current_user_id'] = 8;

		self::assertNotSame( '', self::html( 'dashboard', $now + 60 ) );
	}

	public function test_the_notice_comes_back_when_the_owner_or_the_version_changes(): void {
		$now = 1_800_000_000;
		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		McpRuntimeNotice::dismiss( 7, $now );
		self::assertSame( '', self::html( 'dashboard', $now + 60 ) );

		self::runtime( false, [ 'adapter_abi_incompatible' ], false, '0.3.0' );
		self::assertNotSame( '', self::html( 'dashboard', $now + 60 ), 'A different version is a different cause.' );

		self::runtime( false, [ 'adapter_abi_incompatible' ], false, '0.4.0', 'plugin:another-seo' );
		self::assertNotSame( '', self::html( 'dashboard', $now + 60 ), 'A different owner is a different cause.' );

		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		self::assertSame( '', self::html( 'dashboard', $now + 60 ), 'The dismissed cause is still dismissed.' );
	}

	public function test_a_user_who_cannot_manage_options_is_refused_with_403_when_dismissing(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		unset( $GLOBALS['stonewright_test_last_wp_die_response'] );

		try {
			McpRuntimeNotice::handle_dismiss();
			self::fail( 'The handler must stop a user who cannot manage options.' );
		} catch ( \RuntimeException $stop ) {
			self::assertStringContainsString( 'not allowed to dismiss', $stop->getMessage() );
		}
		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] ?? null );
	}

	public function test_the_dismiss_handler_is_registered_for_administrators_only(): void {
		McpRuntimeNotice::register();

		$hooks = array_column( $GLOBALS['stonewright_test_actions']['admin_post_stonewright_dismiss_mcp_runtime_notice'] ?? [], 'callback' );
		self::assertSame( [ [ McpRuntimeNotice::class, 'handle_dismiss' ] ], $hooks );
	}

	public function test_the_notice_does_not_repeat_the_troubleshoot_link_on_the_troubleshoot_page(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		self::assertStringNotContainsString( 'Open Troubleshoot', self::html( 'stonewright_page_stonewright-troubleshoot' ) );
		self::assertStringContainsString( 'Open Troubleshoot', self::html( 'stonewright_page_stonewright-status' ) );
	}

	public function test_register_hooks_the_notice_and_the_fallback_routes(): void {
		McpRuntimeNotice::register();

		self::assertNotEmpty( $GLOBALS['stonewright_test_actions']['admin_notices'] ?? [] );
		self::assertNotEmpty( $GLOBALS['stonewright_test_actions']['rest_api_init'] ?? [] );
	}

	public function test_the_plugin_registers_the_notice_at_boot(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString( 'McpRuntimeNotice::register()', $source );
	}

	public function test_a_blocked_adapter_gets_explicit_routes_instead_of_a_missing_route(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		McpRuntimeNotice::register_fallback_routes();

		$routes = [];
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			$routes[ $route['namespace'] . $route['route'] ] = $route;
		}
		self::assertSame( [ 'mcp/stonewright', 'mcp/stonewright-oauth' ], array_keys( $routes ) );

		$callback = $routes['mcp/stonewright']['args']['callback'];
		$error    = $callback( new \WP_REST_Request() );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_mcp_adapter_conflict', $error->get_error_code() );
		self::assertSame( 503, (int) ( $error->get_error_data()['status'] ?? 0 ) );
		self::assertStringContainsString( 'Foreign SEO Suite', $error->get_error_message() );
		self::assertStringContainsString( '0.4.0', $error->get_error_message() );
	}

	public function test_the_fallback_answer_is_not_a_way_in(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );
		McpRuntimeNotice::register_fallback_routes();

		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			$permission = $route['args']['permission_callback'] ?? null;
			self::assertIsCallable( $permission );
			self::assertTrue( $permission(), 'The answer carries only the public explanation, so it may be read by the client that is told to connect.' );
			self::assertSame( [ 'GET', 'POST', 'DELETE' ], $route['args']['methods'] );
		}
	}

	public function test_no_fallback_route_is_added_while_an_adapter_serves_the_route(): void {
		self::runtime( true, [] );
		McpRuntimeNotice::register_fallback_routes();

		self::runtime( true, [], true );
		McpRuntimeNotice::register_fallback_routes();

		self::assertSame( [], $GLOBALS['stonewright_test_rest_routes'] );
	}
}
