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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_extra_plugins'] );
		$GLOBALS['stonewright_test_user_caps']   = [];
		$GLOBALS['stonewright_test_rest_routes'] = [];
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
	}

	/** @param list<string> $blocking */
	private static function runtime( bool $compatible, array $blocking, bool $foreign = false ): void {
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue(
			null,
			[
				'compatible'       => $compatible,
				'adapter'          => [
					'selected_owner'   => $foreign || ! $compatible ? 'plugin:foreign-seo' : 'plugin:stonewright',
					'selected_version' => $compatible && ! $foreign ? '0.6.1' : '0.4.0',
					'selection_state'  => 'selected',
					'foreign_runtime'  => $foreign,
					'owner_details'    => [ [ 'owner' => 'plugin:foreign-seo', 'version' => '0.4.0', 'state' => 'autoloadable' ] ],
					'abi'              => [ 'status' => $compatible ? 'compatible' : 'incompatible', 'issues' => [] ],
				],
				'blocking_reasons' => $blocking,
			]
		);
	}

	private static function html( string $screen_id = 'stonewright_page_stonewright-status' ): string {
		ob_start();
		McpRuntimeNotice::render( $screen_id );

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
	}

	public function test_the_notice_shows_on_stonewright_screens_only(): void {
		self::runtime( false, [ 'adapter_abi_incompatible' ] );

		self::assertSame( '', self::html( 'edit-post' ) );
		self::assertSame( '', self::html( 'plugins' ) );
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
