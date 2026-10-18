<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\Adapters\OptionsAdapter;
use Stonewright\WpMcp\Security\FullSurfaceGuard;

/**
 * The Full tool surface (and the full profile) is reached only by a person in wp-admin: a request with a
 * cookie and a valid REST nonce, or an admin screen. An Application Password, an OAuth bearer or any other
 * way of signing in is refused with the settings link, whatever the route.
 *
 * @covers \Stonewright\WpMcp\Security\FullSurfaceGuard
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Security\Adapters\OptionsAdapter
 */
final class FullSurfaceHumanOnlyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_rest_routes']    = [];
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['stonewright_test_nonce_invalid'] );
		$GLOBALS['stonewright_test_is_admin'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_is_admin'] );
		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_rest_routes'] = [];
		$GLOBALS['stonewright_test_user_caps']   = [];
	}

	/** @param array<string, mixed> $params */
	private static function post( array $params, ?string $nonce = null ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/settings', $params );
		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		return $request;
	}

	/** The way the route is registered: its POST callback and its permission callback. */
	private static function route_callback(): callable {
		RestRoutes::register();
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $route ) {
			if ( 'stonewright/v1' === $route['namespace'] && '/settings' === $route['route'] ) {
				foreach ( $route['args'] as $handler ) {
					if ( is_array( $handler ) && 'POST' === ( $handler['methods'] ?? '' ) ) {
						return $handler['callback'];
					}
				}
			}
		}
		self::fail( 'The settings route has no POST handler.' );
	}

	private static function assert_refusal( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_full_surface_requires_admin', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['approval_required'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertStringContainsString( 'page=stonewright', $data['approval_url'] );
		self::assertStringContainsString( 'stonewright_mcp_surface', $data['approval_url'] );
	}

	public function test_an_application_password_cannot_turn_the_essential_mode_off_through_the_rest_route(): void {
		$result = ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ] ) );

		self::assert_refusal( $result );
		self::assertTrue( get_option( 'stonewright_essential_tools_mode' ), 'Nothing was written.' );
	}

	public function test_a_bearer_or_any_non_cookie_sign_in_is_refused_even_with_a_nonce_header(): void {
		// No cookie sign-in was recorded for this request: the nonce alone does not make it a person in wp-admin.
		$result = ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ], 'a-valid-looking-nonce' ) );

		self::assert_refusal( $result );
	}

	public function test_the_whole_request_is_refused_so_other_settings_in_it_are_not_written(): void {
		$result = ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false, 'mode' => 'staging' ] ) );

		self::assert_refusal( $result );
		self::assertSame( 'development', get_option( 'stonewright_mode' ) );
	}

	public function test_a_cookie_request_without_a_valid_rest_nonce_is_refused(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;

		self::assert_refusal( ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ] ) ) );

		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		self::assert_refusal( ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ], 'stale-nonce' ) ) );
		self::assertTrue( get_option( 'stonewright_essential_tools_mode' ) );
	}

	public function test_a_person_in_wp_admin_with_a_valid_rest_nonce_may_turn_the_mode_off(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;

		$result = ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ], 'valid-nonce' ) );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertFalse( get_option( 'stonewright_essential_tools_mode' ) );
	}

	public function test_other_settings_keep_working_with_an_application_password(): void {
		$callback = self::route_callback();

		self::assertNotInstanceOf( \WP_Error::class, $callback( self::post( [ 'mode' => 'staging' ] ) ) );
		self::assertSame( 'staging', get_option( 'stonewright_mode' ) );
		self::assertNotInstanceOf( \WP_Error::class, $callback( self::post( [ 'essential_tools_mode' => true ] ) ) );
		self::assertTrue( get_option( 'stonewright_essential_tools_mode' ) );
	}

	public function test_a_site_that_is_already_on_the_full_surface_has_nothing_to_refuse(): void {
		update_option( 'stonewright_mcp_surface', 'full' );
		update_option( 'stonewright_essential_tools_mode', false );

		$result = ( self::route_callback() )( self::post( [ 'essential_tools_mode' => false ] ) );

		self::assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_restoring_the_full_surface_from_the_ledger_needs_wp_admin(): void {
		$image = [
			'v'          => 1,
			'kind'       => 'options',
			'options'    => [
				'stonewright_mcp_surface'          => [ 'exists' => true, 'value' => 'full' ],
				'stonewright_essential_tools_mode' => [ 'exists' => true, 'value' => false ],
				'stonewright_last_tool_profile'    => [ 'exists' => true, 'value' => 'full' ],
			],
			'entries'    => [],
			'theme_mods' => [],
			'vetoed'     => [],
		];

		// An ability call or a REST request is not wp-admin.
		$refused = OptionsAdapter::restore( $image, 'stonewright/tool-profile' );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_full_surface_requires_admin', $refused->get_error_code() );
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
		self::assertTrue( get_option( 'stonewright_essential_tools_mode' ) );

		// The Changes page is an admin screen.
		$GLOBALS['stonewright_test_is_admin'] = true;
		$restored = OptionsAdapter::restore( $image, 'stonewright/tool-profile' );
		self::assertIsArray( $restored );
		self::assertSame( 'full', AbilityRegistry::mcp_surface() );
	}

	public function test_restoring_away_from_full_is_never_refused(): void {
		update_option( 'stonewright_mcp_surface', 'full' );
		$image = [
			'v'          => 1,
			'kind'       => 'options',
			'options'    => [ 'stonewright_mcp_surface' => [ 'exists' => true, 'value' => 'essential' ] ],
			'entries'    => [],
			'theme_mods' => [],
			'vetoed'     => [],
		];

		self::assertIsArray( OptionsAdapter::restore( $image, 'stonewright/tool-profile' ) );
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
	}

	public function test_the_guard_names_what_puts_full_in_effect(): void {
		self::assertTrue( FullSurfaceGuard::puts_full_in_effect( 'stonewright_mcp_surface', 'full' ) );
		self::assertTrue( FullSurfaceGuard::puts_full_in_effect( 'stonewright_essential_tools_mode', false ) );
		self::assertTrue( FullSurfaceGuard::puts_full_in_effect( 'stonewright_essential_tools_mode', '0' ) );
		self::assertTrue( FullSurfaceGuard::puts_full_in_effect( 'stonewright_last_tool_profile', 'full' ) );
		self::assertFalse( FullSurfaceGuard::puts_full_in_effect( 'stonewright_mcp_surface', 'essential' ) );
		self::assertFalse( FullSurfaceGuard::puts_full_in_effect( 'stonewright_essential_tools_mode', true ) );
		self::assertFalse( FullSurfaceGuard::puts_full_in_effect( 'stonewright_mode', 'full' ) );
	}

	public function test_no_other_rest_route_writes_the_surface_options(): void {
		// The routes that exist today. A new route that writes the surface has to be added here on purpose,
		// and has to go through FullSurfaceGuard.
		$sources = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$text = (string) file_get_contents( $file->getPathname() );
			if ( str_contains( $text, 'register_rest_route' ) && preg_match( '/stonewright_mcp_surface|stonewright_essential_tools_mode|set_mcp_surface/', $text ) ) {
				$sources[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( dirname( __DIR__, 3 ) ) + 1 ) );
			}
		}
		sort( $sources );

		self::assertSame( [ 'includes/Core/RestRoutes.php' ], $sources );
		self::assertStringContainsString( 'FullSurfaceGuard', (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/RestRoutes.php' ) );
	}
}
