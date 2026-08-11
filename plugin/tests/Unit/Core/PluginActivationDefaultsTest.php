<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Fresh-install defaults must provide a useful surface, never a bootstrap trap.
 */
final class PluginActivationDefaultsTest extends TestCase {

	public function test_fresh_install_defaults_to_essential_surface(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString(
			"update_option( 'stonewright_mcp_surface', 'essential', false )",
			$source
		);
		self::assertStringNotContainsString(
			"update_option( 'stonewright_mcp_surface', 'bootstrap', false )",
			$source
		);
	}
}
