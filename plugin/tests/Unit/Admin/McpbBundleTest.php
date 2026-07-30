<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\McpbBundle;

/**
 * @covers \Stonewright\WpMcp\Admin\McpbBundle
 */
final class McpbBundleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_site_url'] = 'https://example.test';
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
	}

	public function test_build_manifest_embeds_npx_companion_url_only(): void {
		$manifest = McpbBundle::build_manifest();

		self::assertSame( '0.3', $manifest['manifest_version'] );
		self::assertSame( 'stonewright', $manifest['name'] );
		self::assertArrayHasKey( 'server', $manifest );
		self::assertSame( 'node', $manifest['server']['type'] );
		self::assertSame( 'server/index.js', $manifest['server']['entry_point'] );

		$config = $manifest['server']['mcp_config'];
		self::assertSame( 'npx', $config['command'] );
		self::assertContains( '-y', $config['args'] );
		self::assertContains( '--package', $config['args'] );
		self::assertContains( 'stonewright-mcp', $config['args'] );
		self::assertArrayHasKey( 'STONEWRIGHT_WP_URL', $config['env'] );
		self::assertStringContainsString( 'example.test', (string) $config['env']['STONEWRIGHT_WP_URL'] );
		self::assertArrayNotHasKey( 'STONEWRIGHT_WP_USERNAME', $config['env'] );
		self::assertArrayNotHasKey( 'STONEWRIGHT_WP_APP_PASSWORD', $config['env'] );

		$encoded = (string) wp_json_encode( $manifest );
		self::assertStringNotContainsString( 'application-password', strtolower( $encoded ) );
		self::assertStringNotContainsString( 'your-wp-username', $encoded );
	}

	public function test_create_zip_writes_manifest_and_stub(): void {
		if ( ! class_exists( \ZipArchive::class ) ) {
			self::markTestSkipped( 'ZipArchive not available' );
		}

		$path = McpbBundle::create_zip();
		self::assertIsString( $path );
		self::assertFileExists( $path );

		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path ) );
		$manifest_json = $zip->getFromName( 'manifest.json' );
		$stub          = $zip->getFromName( 'server/index.js' );
		$zip->close();
		@unlink( $path );

		self::assertIsString( $manifest_json );
		$decoded = json_decode( $manifest_json, true );
		self::assertIsArray( $decoded );
		self::assertSame( 'stonewright', $decoded['name'] );
		self::assertIsString( $stub );
		self::assertStringContainsString( 'Placeholder', $stub );
	}
}
