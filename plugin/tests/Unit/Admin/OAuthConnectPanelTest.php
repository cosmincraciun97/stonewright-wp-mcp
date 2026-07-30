<?php
/**
 * OAuth connection panel rendering tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\OAuthClientConfig;
use Stonewright\WpMcp\Admin\OAuthConnectPanel;

final class OAuthConnectPanelTest extends TestCase {

	public function test_renders_all_clients_and_stonewright_oauth_route(): void {
		ob_start();
		OAuthConnectPanel::render(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright-example'
		);
		$html = (string) ob_get_clean();

		foreach ( OAuthClientConfig::client_labels() as $label ) {
			self::assertStringContainsString( '>' . $label . '</button>', $html );
		}
		self::assertStringContainsString( 'stonewright-oauth', $html );
		self::assertStringContainsString( 'Change server name (optional)', $html );
		self::assertStringContainsString( 'Manage connected apps', $html );
		self::assertStringNotContainsString( 'Novamira', $html );
	}
}
