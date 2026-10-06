<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationPages;
use Stonewright\WpMcp\Authorization\WordPress\DiscoveryDocuments;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes;
use Stonewright\WpMcp\Authorization\WordPress\ProtectedResource;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The plugin bootstrap reaches OAuth only through the new entry points.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\HttpSurface
 */
final class PluginWiringTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_submenu_pages'] = [];
	}

	private static function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$lines = (array) file( (string) $reflection->getFileName() );
		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}

	public function test_activation_deactivation_and_every_load_use_the_new_entry_points(): void {
		self::assertStringContainsString( 'AuthorizationLifecycle::activate()', self::method_source( PluginRegistration::class, 'on_activate' ) );
		self::assertStringContainsString( 'AuthorizationLifecycle::deactivate()', self::method_source( PluginRegistration::class, 'on_deactivate' ) );
		$hooks = self::method_source( PluginRegistration::class, 'register_hooks' );
		self::assertStringContainsString( 'AuthorizationLifecycle::register()', $hooks );
		self::assertStringContainsString( 'HttpSurface::class', $hooks );
	}

	public function test_rewired_callers_no_longer_name_the_removed_namespace(): void {
		$root = dirname( __DIR__, 4 ) . '/includes/';
		foreach ( [ 'Core/PluginRegistration.php', 'Abilities/Diagnostics/OAuthHeaderDiagnostic.php', 'Admin/SetupDiagnostics.php', 'Admin/AuditLogPage.php', 'Core/ServerRegistration.php' ] as $file ) {
			self::assertStringNotContainsString( 'WpMcp\\OAuth\\', (string) file_get_contents( $root . $file ), $file );
		}
	}

	public function test_the_http_surface_registers_every_hook(): void {
		HttpSurface::register();

		$actions = $GLOBALS['stonewright_test_actions'];
		self::assertSame( [ DiscoveryDocuments::class, 'serve' ], $actions['parse_request'][0]['callback'] );
		self::assertSame( 0, $actions['parse_request'][0]['priority'] );
		self::assertSame( [ OAuthRestRoutes::class, 'register' ], $actions['rest_api_init'][0]['callback'] );
		self::assertSame( [ AuthorizationPages::class, 'register_pages' ], $actions['admin_menu'][0]['callback'] );
		self::assertSame( [ ProtectedResource::class, 'guard' ], $GLOBALS['stonewright_test_filters']['rest_pre_dispatch'] );
		self::assertSame( [ ProtectedResource::class, 'finish' ], $GLOBALS['stonewright_test_filters']['rest_post_dispatch'] );
		self::assertSame( [ OAuthRestRoutes::class, 'serve_empty_body' ], $GLOBALS['stonewright_test_filters']['rest_pre_serve_request'] );
	}

	public function test_hidden_pages_exist_only_while_oauth_is_available(): void {
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		HttpSurface::use_site( HttpRig::site( false ) );
		AuthorizationPages::register_pages();
		self::assertSame( [], self::hidden_pages() );

		HttpSurface::use_site( HttpRig::site() );
		AuthorizationPages::register_pages();

		self::assertSame( [ 'stonewright-oauth-authorize', 'stonewright-oauth-consent' ], self::hidden_pages() );
	}

	/** @return list<string> */
	private static function hidden_pages(): array {
		$slugs = [];
		foreach ( (array) ( $GLOBALS['stonewright_test_submenu_pages'] ?? [] ) as $slug => $page ) {
			if ( 'options.php' === ( $page['parent'] ?? null ) && 'read' === ( $page['capability'] ?? null ) ) {
				$slugs[] = (string) $slug;
			}
		}
		return $slugs;
	}
}
