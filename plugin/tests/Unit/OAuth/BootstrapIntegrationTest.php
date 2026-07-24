<?php
/**
 * OAuth bootstrap wiring contract.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;

final class BootstrapIntegrationTest extends TestCase {

	public function test_plugin_registration_boots_every_oauth_component(): void {
		$registration = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );
		$bootstrap    = file_get_contents( dirname( __DIR__, 3 ) . '/includes/OAuth/Bootstrap.php' );
		self::assertIsString( $registration );
		self::assertIsString( $bootstrap );
		self::assertStringContainsString( 'OAuthBootstrap::class', $registration );

		foreach (
			[
				'OAuth\\Endpoints\\Discovery',
				'OAuth\\Endpoints\\Authorize',
				'OAuth\\Endpoints\\Register',
				'OAuth\\Endpoints\\Token',
				'OAuth\\Endpoints\\Revoke',
				'OAuth\\Endpoints\\Introspect',
				'Middleware::register',
				'Consent::class',
				'ConnectedApps::class',
			] as $class
		) {
			self::assertStringContainsString( $class, $bootstrap );
		}
	}

	public function test_plugin_lifecycle_installs_keys_schema_and_clears_gc(): void {
		$registration = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );
		self::assertIsString( $registration );
		self::assertStringContainsString( 'OAuthSchema::maybe_install()', $registration );
		self::assertStringContainsString( 'OAuthKeys::get()', $registration );
		self::assertStringContainsString( 'OAuthSchema::schedule_gc()', $registration );
		self::assertStringContainsString( 'OAuthSchema::unschedule_gc()', $registration );
	}
}
