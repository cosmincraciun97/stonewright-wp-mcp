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
			] as $class
		) {
			self::assertStringContainsString( $class, $bootstrap );
		}
	}
}
